<?php

namespace MyCS\Extensions\Servers\Proxmox;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Models\Service;
use App\Services\Proxmox\GuestLifecycle;
use App\Support\PanelConnection;
use App\Support\Proxmox\PanelTransport;
use MyCS\Proxmox\ProxmoxClient;
use RuntimeException;
use Throwable;

/**
 * A Proxmox VE cluster (owner 2026-10-24), read-only for now: its nodes, their graphs and its guests, each mapped to a
 * service or marked internal (Proxmox guests). One server per cluster, with up to three of its nodes' addresses: any
 * node answers for the whole cluster, so one node down hides nothing. A second cluster or a standalone node is another
 * server. Reading needs PVEAuditor on «/»; the actions the MyCS role on its pool (docs/proxmox.md).
 */
#[ExtensionMeta(
    name: 'Proxmox VE',
    description: 'A Proxmox VE cluster: its nodes and guests, read with an API token',
    version: 'builtin',
    author: 'MyCS',
)]
class Proxmox extends Server
{
    public function getConfig($values = []): array
    {
        $ip = PanelConnection::fields()[0];
        $node = fn (int $n) => [
            ['name' => "node{$n}_host", 'type' => 'text', 'label' => "Node {$n} address", 'placeholder' => "https://pve{$n}.example.com:8006",
                'validation' => 'nullable|url:https', 'required' => false, 'description' => 'Tried when the nodes above do not answer.'],
            ['name' => "node{$n}_ip", 'label' => "Node {$n} IP"] + $ip,
        ];

        return [
            ['name' => 'host', 'type' => 'text', 'label' => 'Node address', 'placeholder' => 'https://pve1.example.com:8006',
                'validation' => 'url:https', 'required' => true, 'description' => 'Any node of the cluster: it answers for all of them.'],
            ...PanelConnection::fields(),
            ...$node(2),
            ...$node(3),
            ['name' => 'token_id', 'type' => 'text', 'label' => 'API token ID', 'placeholder' => 'mycs@pve!api', 'required' => true,
                'validation' => ['required', 'regex:/^[^@\s]+@[^!\s]+![^\s]+$/']],
            ['name' => 'token_secret', 'type' => 'password', 'label' => 'API token secret', 'required' => true, 'encrypted' => true],
            ['name' => 'pool', 'type' => 'text', 'label' => 'MyCS pool', 'default' => 'mycs', 'required' => false,
                'validation' => ['nullable', 'regex:/^[A-Za-z0-9_.-]+$/'],
                'description' => 'MyCS suspends, starts and destroys only VMs in this pool, each mapped to its service (Proxmox Guests).'],
            ['name' => 'terminate', 'type' => 'select', 'label' => 'Terminate', 'default' => 'stop', 'required' => false,
                'options' => [['value' => 'stop', 'label' => 'Stop the VM and leave its destroy to staff'], ['value' => 'destroy', 'label' => 'Destroy the VM and its disks']],
                'description' => 'A failsafe while MyCS is new: a terminated service\'s VM is stopped, and staff destroy it by hand (a task in Pending Module Actions).'],
        ];
    }

    /** The cluster's client: its nodes' addresses in order, each to its own IP. */
    public function client(): ProxmoxClient
    {
        $ips = [];
        foreach ([['host', 'ip'], ['node2_host', 'node2_ip'], ['node3_host', 'node3_ip']] as [$host, $ip]) {
            $base = rtrim(trim((string) $this->config($host)), '/');
            if ($base !== '') {
                $ips[$base] = trim((string) $this->config($ip)) ?: null;
            }
        }

        return new ProxmoxClient(new PanelTransport($ips, (bool) $this->config('self_signed')), array_keys($ips),
            trim((string) $this->config('token_id')), trim((string) $this->config('token_secret')));
    }

    /** Suspend = stop (owner 2026-10-24): a clean shutdown, then a hard stop; not started again by a node's reboot. */
    public function suspendServer(Service $service, $settings, $properties)
    {
        $this->lifecycle($service)->suspend($service);

        return true;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $this->lifecycle($service)->unsuspend($service);

        return true;
    }

    /**
     * Terminate = destroy with its disks (owner 2026-10-24: months unpaid by then; the backups are the way back), once the
     * server's «Terminate» says so; until then (the default) stopped, the destroy left to staff.
     */
    public function terminateServer(Service $service, $settings, $properties)
    {
        $this->lifecycle($service)->terminate($service, $this->config('terminate') === 'destroy');

        return true;
    }

    public function lifecycle(Service $service): GuestLifecycle
    {
        $server = $service->provisioningServer() ?? throw new RuntimeException("Service #{$service->id} has no server.");

        return new GuestLifecycle($this->client(), $server, trim((string) $this->config('pool')) ?: 'mycs');
    }

    public function testConfig(): bool|string
    {
        try {
            $client = $this->client();
            $client->version();
            // /version and /nodes answer any valid token; a token without its ACL sees no node (review of #446).
            if ($client->nodes() === []) {
                return 'The token works but sees no node: give it PVEAuditor on «/» (pveum acl modify / --roles PVEAuditor --users mycs@pve --tokens \'mycs@pve!api\').';
            }
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return true;
    }
}
