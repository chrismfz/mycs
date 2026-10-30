<?php

namespace App\Services\Proxmox;

use App\Helpers\ExtensionHelper;
use App\Models\Property;
use App\Models\ProxmoxGuest;
use App\Models\Server;
use App\Models\Service;
use App\Services\Import\WhmcsProxmoxImport;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MyCS\Extensions\Servers\Proxmox\Proxmox;
use MyCS\Proxmox\Guest;
use RuntimeException;

/**
 * A cluster's guests read into proxmox_guests: new ones added unmapped, known ones refreshed (their mapping kept), the
 * ones no longer listed marked gone (kept, with their mapping: a guest moved to another cluster or deleted is staff's to
 * look at). Reads only.
 */
final class GuestSync
{
    /** @return array{seen: int, new: int, gone: int, mapped: int} */
    public static function sync(Server $server): array
    {
        $extension = ExtensionHelper::getExtension('server', $server->extension, $server->settings);
        if (!$extension instanceof Proxmox) {
            throw new \InvalidArgumentException("{$server->name} is not a Proxmox server.");
        }
        $lock = Cache::lock("proxmox.sync.{$server->id}", 1800);
        if (!$lock->get()) {
            throw new RuntimeException("{$server->name} is being read already: try again in a minute.");
        }
        try {
            return self::read($server, $extension);
        } finally {
            $lock->release();
        }
    }

    /** @return array{seen: int, new: int, gone: int, mapped: int} */
    private static function read(Server $server, Proxmox $extension): array
    {
        $guests = $extension->client()->guests(); // throws before anything is written: a failed read marks nothing gone
        // An empty list where there were guests is a token that sees nothing (its ACL removed), not a cluster emptied:
        // nothing marked gone (review of #446).
        // A token that sees its nodes but no guest is a cluster really emptied: then they go.
        if ($guests === [] && ProxmoxGuest::query()->where('server_id', $server->id)->whereNull('gone_at')->exists()
            && $extension->client()->nodes() === []) {
            throw new RuntimeException("{$server->name} listed no guest: check the token's permissions (PVEAuditor on /). Nothing marked gone.");
        }

        $now = now();
        $new = 0;
        $seen = [];
        foreach ($guests as $guest) {
            /** @var Guest $guest */
            $row = ProxmoxGuest::query()->firstOrNew(['server_id' => $server->id, 'vmid' => $guest->vmid]);
            $new += $row->exists ? 0 : 1;
            // A VMID reused by another machine (Proxmox gives the lowest free one): not the guest that was mapped.
            if ($row->exists && self::anotherMachine($row, $guest)) {
                $row->fill(['service_id' => null, 'internal' => false, 'note' => null, 'macs' => null, 'ips' => null, 'onboot_before_suspend' => null]);
            }
            $row->fill([
                'type' => $guest->type, 'node' => $guest->node, 'pool' => $guest->pool, 'name' => $guest->name, 'status' => $guest->status,
                'template' => $guest->template, 'cpus' => $guest->cpus, 'max_memory' => $guest->maxMemory, 'max_disk' => $guest->maxDisk,
                'uptime' => $guest->uptime, 'tags' => $guest->tags, 'last_seen_at' => $now, 'gone_at' => null,
            ]);
            // What could not be read this time (an agent not answering) keeps what was read before.
            if ($guest->macs !== []) {
                $row->macs = $guest->macs;
            }
            if ($guest->ips !== []) {
                $row->ips = $guest->ips;
            }
            $row->save();
            $seen[] = $guest->vmid;
        }
        $gone = ProxmoxGuest::query()->where('server_id', $server->id)->whereNull('gone_at')->whereNotIn('vmid', $seen ?: [0])
            ->update(['gone_at' => $now, 'status' => 'gone']);

        $mapped = self::mapFromWhmcs($server);

        return ['seen' => count($seen), 'new' => $new, 'gone' => $gone, 'mapped' => $mapped];
    }

    /**
     * The guests WHMCS's module knew (their VMID kept on the live service by the importer, WhmcsProxmoxImport::WHMCS_VMID),
     * on the cluster's first read here, once: an unmapped guest with that VMID becomes that live, unmapped service's, the
     * cluster its server if it has none (docs/proxmox.md A3). Each VMID is used on that read only, mapped or not, and
     * goes (ADV-548-4/5: never a machine made later, nor after staff clear a mapping). Only with one Proxmox cluster:
     * VMIDs are per cluster, two leave them to staff (ADV-548-6). Under the server's sync lock.
     */
    private static function mapFromWhmcs(Server $server): int
    {
        if (self::servers()->count() !== 1) {
            return 0; // which cluster a VMID is in is staff's to say
        }
        $mapped = 0;
        $pending = Property::query()->where('model_type', (new Service)->getMorphClass())->where('key', WhmcsProxmoxImport::WHMCS_VMID)->get();
        foreach ($pending as $property) {
            DB::transaction(function () use ($property, $server, &$mapped) {
                $service = Service::query()->lockForUpdate()->find($property->model_id);
                /** @var ProxmoxGuest|null $guest */
                $guest = ProxmoxGuest::whereUnmapped(ProxmoxGuest::query())->where('server_id', $server->id)->where('vmid', (int) $property->value)
                    ->whereNull('gone_at')->lockForUpdate()->first();
                $address = Property::query()->where('model_type', $property->model_type)->where('model_id', $property->model_id)
                    ->where('key', WhmcsProxmoxImport::WHMCS_VM_IP)->value('value');
                Property::query()->where('model_type', $property->model_type)->where('model_id', $property->model_id)
                    ->whereIn('key', [WhmcsProxmoxImport::WHMCS_VMID, WhmcsProxmoxImport::WHMCS_VM_IP])->delete(); // once, whatever comes of it
                if ($service === null || $guest === null || !in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED, Service::STATUS_PENDING], true)
                    || ProxmoxGuest::query()->where('service_id', $service->id)->exists()
                    || ($service->server_id !== null && (int) $service->server_id !== (int) $server->id)
                    || !self::sameMachine($guest, $service, $address)) {
                    return; // not this one's to map: staff map it from Proxmox Guests
                }
                // Only if staff did not map it meanwhile (their action takes no sync lock: code review 5).
                if (ProxmoxGuest::query()->whereKey($guest->id)->whereNull('service_id')->where('internal', false)->update(['service_id' => $service->id]) !== 1) {
                    return;
                }
                if ($service->server_id === null) {
                    $service->forceFill(['server_id' => $server->id])->save();
                }
                $mapped++;
            });
        }

        return $mapped;
    }

    /**
     * The guest is the service's VM by more than its VMID (code review 1 of #548: a VMID Proxmox gave another machine):
     * WHMCS's address for it among the guest's, or the service's own, or the guest named as its domain.
     */
    private static function sameMachine(ProxmoxGuest $guest, Service $service, ?string $address): bool
    {
        $ips = array_map('strval', (array) $guest->ips);
        foreach (array_filter([$address, $service->dedicated_ip]) as $ip) {
            if (in_array((string) $ip, $ips, true)) {
                return true;
            }
        }

        return filled($service->domain) && filled($guest->name) && strcasecmp(trim((string) $guest->name), trim((string) $service->domain)) === 0;
    }

    /**
     * Another machine under the same VMID: its MACs all different from the ones known, or, with no MAC to compare, a
     * guest that was gone coming back under another name.
     */
    private static function anotherMachine(ProxmoxGuest $row, Guest $guest): bool
    {
        $known = (array) $row->macs;
        if ($known !== [] && $guest->macs !== []) {
            return array_intersect($known, $guest->macs) === [];
        }

        return $row->gone_at !== null && (string) $row->name !== $guest->name;
    }

    /** @return Collection<int, Server> */
    public static function servers()
    {
        return Server::query()->where('extension', 'Proxmox')->where('enabled', true)->with('settings')->get();
    }
}
