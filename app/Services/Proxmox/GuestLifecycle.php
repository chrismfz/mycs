<?php

namespace App\Services\Proxmox;

use App\Exceptions\LeftToStaff;
use App\Models\ProxmoxGuest;
use App\Models\Server;
use App\Models\Service;
use Closure;
use Illuminate\Support\Facades\Cache;
use MyCS\Proxmox\Guest;
use MyCS\Proxmox\ProxmoxClient;
use MyCS\Proxmox\ProxmoxException;
use RuntimeException;

/**
 * What MyCS does to a service's Proxmox guest (owner 2026-10-24): suspend = stop (a clean shutdown, then a hard stop),
 * unsuspend = start, terminate = destroy with its disks. Only on the one guest mapped to the service (Proxmox Guests),
 * found on the cluster now, in MyCS's pool, and still the same machine (its MACs): anything else is refused, for staff.
 * Each step is safe to repeat: a retry of the job finds what is already done and goes on.
 */
final class GuestLifecycle
{
    public const POWER = ['start', 'shutdown', 'reboot', 'stop'];

    /** Seconds an action may wait for the cluster's tasks in all: under the server jobs' timeout (120 s), with room for the calls. */
    public const WAIT_BUDGET = 80;

    private int $waited = 0;

    /** @param  Closure(int): void|null  $sleep */
    public function __construct(
        private readonly ProxmoxClient $client,
        private readonly Server $server,
        private readonly string $pool = 'mycs',
        private readonly ?Closure $sleep = null,
    ) {}

    public function suspend(Service $service): void
    {
        $this->locked($service, function (ProxmoxGuest $row, ?array $resource) {
            $resource ?? throw new RuntimeException("VM {$row->vmid} is no longer on the cluster: nothing to suspend. Check the mapping in Proxmox Guests.");
            $path = ProxmoxClient::guestPath($resource);
            $onboot = (int) (((array) $this->client->get("{$path}/config"))['onboot'] ?? 0) === 1;
            // Its own setting, kept once: a retry finds onboot already 0 and must not keep that.
            if ($row->onboot_before_suspend === null) {
                $row->update(['onboot_before_suspend' => $onboot]);
            }
            if ($onboot) {
                $this->client->write('PUT', "{$path}/config", ['onboot' => 0]); // a node's reboot does not start it again
            }
            if (!in_array($resource['status'], ['running', 'stopped'], true)) {
                throw new RuntimeException("VM {$row->vmid} is «{$resource['status']}» on the cluster: not suspended, tried again later.");
            }
            if ($resource['status'] === 'running') {
                // A clean shutdown, stopped hard if it is not off within a minute.
                $this->wait($this->client->write('POST', "{$path}/status/shutdown", ['timeout' => 60, 'forceStop' => 1]), 100, 'shutting down');
            }
            $row->update(['status' => 'stopped']);
        });
    }

    public function unsuspend(Service $service): void
    {
        $this->locked($service, function (ProxmoxGuest $row, ?array $resource) {
            $resource ?? throw new RuntimeException("VM {$row->vmid} is no longer on the cluster: nothing to start. Check the mapping in Proxmox Guests.");
            $path = ProxmoxClient::guestPath($resource);
            if ($row->onboot_before_suspend === true) {
                $this->client->write('PUT', "{$path}/config", ['onboot' => 1]);
            }
            if (($resource['status'] ?? '') !== 'running') {
                $this->wait($this->client->write('POST', "{$path}/status/start"), 60, 'starting');
            }
            $row->update(['status' => 'running', 'onboot_before_suspend' => null]);
        });
    }

    /**
     * Destroyed with its disks; a guest already gone is done. The service's backups are the way back (owner). With
     * $destroy off (the server's «Terminate: stop and leave to staff», the default) it is only stopped, not started
     * again at boot, and the destroy is a task for staff (LeftToStaff).
     */
    public function terminate(Service $service, bool $destroy = true): void
    {
        if (!$destroy) {
            $this->stopForStaff($service);

            return;
        }
        $this->locked($service, function (ProxmoxGuest $row, ?array $resource) {
            if ($resource === null && $row->gone_at === null && $row->status !== 'destroyed') {
                // Not in the list, though the last read saw it: a token that sees only part of the cluster (its pool), or
                // a VM taken out of the pool. Never «destroyed» on that: staff confirm it (adversarial round 2 of #447).
                throw new LeftToStaff("VM {$row->vmid} ({$row->name}) is not listed on the cluster any more: check whether it still runs "
                    . 'and destroy it by hand if it does; then mark this done.');
            }
            if ($resource !== null && $row->status !== 'destroyed') {
                $path = ProxmoxClient::guestPath($resource);
                if (($resource['status'] ?? '') === 'running') {
                    $this->wait($this->client->write('POST', "{$path}/status/stop"), 60, 'stopping');
                }
                // purge: its disks and its place in backup jobs. Not destroy-unreferenced-disks: that removes every
                // «vm-<vmid>-disk-*» on every storage, another cluster's 101 too on a shared one (adversarial of #447).
                $this->wait($this->client->write('DELETE', "{$path}?purge=1"), 100, 'being destroyed');
            }
            $row->update(['status' => 'destroyed', 'gone_at' => now()]);
        }, destroying: true);
    }

    private function stopForStaff(Service $service): void
    {
        $left = null;
        $this->locked($service, function (ProxmoxGuest $row, ?array $resource) use (&$left) {
            if ($resource === null) {
                $left = "VM {$row->vmid} is no longer on the cluster: check it was destroyed, then mark this done.";

                return;
            }
            $path = ProxmoxClient::guestPath($resource);
            if ((int) (((array) $this->client->get("{$path}/config"))['onboot'] ?? 0) === 1) {
                $this->client->write('PUT', "{$path}/config", ['onboot' => 0]);
            }
            if (($resource['status'] ?? '') === 'running') {
                $this->wait($this->client->write('POST', "{$path}/status/stop"), 60, 'stopping');
            }
            $row->update(['status' => 'stopped']);
            $left = "VM {$row->vmid} ({$row->name}) on {$resource['node']} is stopped and kept: destroy it by hand on the cluster "
                . '(' . (($resource['type'] ?? 'qemu') === 'lxc' ? 'pct' : 'qm') . " destroy {$row->vmid} --purge), then mark this done. Once this is trusted, the server's «Terminate: Destroy» does it.";
        });

        throw new LeftToStaff((string) $left);
    }

    /** Staff's power buttons: the task is started, not waited for. */
    public function power(Service $service, string $action): void
    {
        if (!in_array($action, self::POWER, true)) {
            throw new RuntimeException("Unknown power action «{$action}».");
        }
        $this->locked($service, function (ProxmoxGuest $row, ?array $resource) use ($action) {
            $resource ?? throw new RuntimeException("VM {$row->vmid} is no longer on the cluster.");
            $this->client->write('POST', ProxmoxClient::guestPath($resource) . "/status/{$action}");
        });
    }

    /**
     * The service's guest and what the cluster lists for it now (null: no longer there), refused unless it is safe to
     * act on: one guest, in the pool, the same machine. A guest already destroyed, or seen gone by the last read, is never
     * looked up again by its VMID: that number may be another machine's by now.
     *
     * @return array{0: ProxmoxGuest, 1: array<string, mixed>|null}
     */
    public function guest(Service $service, bool $destroying = false): array
    {
        $mapped = ProxmoxGuest::query()->where('server_id', $this->server->id)->where('service_id', $service->id);
        $rows = (clone $mapped)->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'destroyed'))->get();
        if ($rows->isEmpty() && $destroying && ($destroyed = (clone $mapped)->where('status', 'destroyed')->first()) !== null) {
            return [$destroyed, null];
        }
        if ($rows->isEmpty()) {
            throw new RuntimeException("No Proxmox guest is mapped to service #{$service->id}: map it in Utilities → Proxmox Guests.");
        }
        if ($rows->count() > 1) {
            throw new RuntimeException("{$rows->count()} Proxmox guests are mapped to service #{$service->id} (VMIDs " . $rows->pluck('vmid')->implode(', ') . '): MyCS acts on one only.');
        }
        /** @var ProxmoxGuest $row */
        $row = $rows->first();
        if ($row->gone_at !== null) {
            return [$row, null];
        }
        if ($row->template) {
            throw new RuntimeException("VM {$row->vmid} is a template: MyCS never acts on one. Map the service to its VM.");
        }
        $listed = array_values(array_filter((array) $this->client->get('cluster/resources?type=vm'), 'is_array'));
        if ($listed === []) {
            // Not «gone»: a token that sees nothing (its ACL removed) would make every destroy a silent success.
            throw new RuntimeException('The token sees no guest on the cluster: nothing done. Check its permissions (PVEAuditor on «/»).');
        }
        $resource = collect($listed)->first(fn (array $r) => (int) ($r['vmid'] ?? 0) === $row->vmid);
        if ($resource === null) {
            return [$row, null];
        }
        if (!empty($resource['template'])) {
            throw new RuntimeException("VM {$row->vmid} is a template: MyCS never acts on one.");
        }
        if (($resource['pool'] ?? null) !== $this->pool) {
            throw new RuntimeException("VM {$row->vmid} is not in the pool «{$this->pool}»: MyCS does not act on it. Add it on the cluster: pvesh set /pools --poolid {$this->pool} --vms {$row->vmid}");
        }
        // The same machine as the one mapped: a VMID reused since the last read is another customer's. A destroy
        // needs that proof; the other actions only when there is something to compare.
        $known = (array) $row->macs;
        if ($known === [] && $destroying) {
            throw new RuntimeException("VM {$row->vmid}: no MAC known to confirm it is the machine mapped to service #{$service->id}. Read the cluster again (Proxmox Guests) first.");
        }
        if ($known !== []) {
            $live = Guest::macsOf((array) $this->client->get(ProxmoxClient::guestPath($resource) . '/config'));
            if (array_intersect($known, $live) === [] && ($live !== [] || $destroying)) {
                throw new RuntimeException("VM {$row->vmid} is not the machine mapped to service #{$service->id} (its MACs changed): check it in Proxmox Guests.");
            }
        }

        $resource['status'] = $this->current($resource);

        return [$row, $resource];
    }

    /**
     * Its state now: the cluster's list is a cache a few seconds old (a VM just stopped is listed running), so the
     * guest's own status is read. A node that cannot say falls back to the list; a refusal (403) does not.
     */
    private function current(array $resource): string
    {
        try {
            $status = (string) (((array) $this->client->get(ProxmoxClient::guestPath($resource) . '/status/current'))['status'] ?? '');
        } catch (ProxmoxException $e) {
            if ($e->getCode() === 403) {
                throw $e;
            }
            $status = '';
        }

        return $status !== '' ? $status : (string) ($resource['status'] ?? '');
    }

    /** @param  Closure(ProxmoxGuest, array<string, mixed>|null): void  $do */
    private function locked(Service $service, Closure $do, bool $destroying = false): void
    {
        // One action on a service's guest at a time (a suspension and a payment's unsuspension racing).
        $this->waited = 0;
        $lock = Cache::lock("proxmox.guest.{$this->server->id}.{$service->id}", 150);
        if (!$lock->block(10)) {
            throw new RuntimeException("Another action on service #{$service->id}'s VM is running: tried again later.");
        }
        try {
            [$row, $resource] = $this->guest($service, $destroying);
            $do($row, $resource);
        } finally {
            $lock->release();
        }
    }

    private function wait(mixed $upid, int $timeout, string $doing): void
    {
        if (!is_string($upid) || $upid === '') {
            return; // nothing to wait for (an older node answers null)
        }
        $left = max(0, self::WAIT_BUDGET - $this->waited);
        $sleep = $this->sleep ?? fn (int $seconds) => sleep($seconds);
        $count = function (int $seconds) use ($sleep) {
            $this->waited += $seconds;
            $sleep($seconds);
        };
        if (!$this->client->waitTask($upid, min($timeout, $left), 2, $count)) {
            throw new RuntimeException("The VM is still {$doing} on the cluster: checked again on the next try.");
        }
    }
}
