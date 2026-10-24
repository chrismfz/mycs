<?php

namespace MyCS\Extensions\Servers\OfflineVm;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\OfflineMachineServer;

/**
 * A VM outside the fleet (Hetzner, another provider, a node of ours not in a module): no API. Its details are kept on
 * the service; its hostname is the service's domain, its IPv4 the service's dedicated / assigned IPs. Every server
 * action is a task for staff (OfflineServer).
 */
#[ExtensionMeta(
    name: 'Offline VM',
    description: 'A virtual machine managed by hand: its details kept, every action a task for staff',
    version: 'builtin',
    author: 'MyCS',
)]
class OfflineVm extends OfflineMachineServer
{
    public function serviceFields(): array
    {
        return [
            ['name' => 'machine_vm_id', 'label' => 'VM ID', 'type' => 'text', 'help' => 'Its ID at the provider or on the node.'],
            ['name' => 'machine_provider', 'label' => 'Provider / location', 'type' => 'text', 'help' => 'Hetzner, a node of ours, another provider.'],
            ['name' => 'machine_mac', 'label' => 'MAC address(es)', 'type' => 'mac', 'help' => 'One per line.'],
            ['name' => 'machine_ipv6', 'label' => 'IPv6', 'type' => 'textarea', 'help' => 'Addresses or prefixes, one per line.'],
            ['name' => 'machine_os', 'label' => 'Operating system', 'type' => 'text'],
            ['name' => 'machine_specs', 'label' => 'CPU / RAM / disk', 'type' => 'text'],
            ['name' => 'machine_console_url', 'label' => 'Console / provider panel', 'type' => 'text'],
            ['name' => 'machine_notes', 'label' => 'Notes', 'type' => 'textarea'],
        ];
    }
}
