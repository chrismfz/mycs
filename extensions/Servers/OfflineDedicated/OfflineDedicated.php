<?php

namespace MyCS\Extensions\Servers\OfflineDedicated;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\OfflineMachineServer;

/**
 * A dedicated server in our rack or elsewhere: no API. Its details are kept on the service: where it is (rack, U), how
 * to reach it (IPMI / iDRAC by its MAC too: its address may change in the management DHCP pool), and what it is. Its
 * hostname is the service's domain, its IPv4 the service's dedicated / assigned IPs. Every server action is a task for
 * staff (OfflineServer).
 */
#[ExtensionMeta(
    name: 'Offline Dedicated Server',
    description: 'A dedicated server managed by hand: rack, IPMI, hardware kept, every action a task for staff',
    version: 'builtin',
    author: 'MyCS',
)]
class OfflineDedicated extends OfflineMachineServer
{
    public function serviceFields(): array
    {
        return [
            ['name' => 'machine_asset_id', 'label' => 'Server ID / asset tag', 'type' => 'text'],
            ['name' => 'machine_location', 'label' => 'Location', 'type' => 'text', 'help' => 'Our rack, a datacenter, the customer\'s premises.'],
            ['name' => 'machine_rack', 'label' => 'Rack', 'type' => 'text'],
            ['name' => 'machine_rack_unit', 'label' => 'Rack unit (U)', 'type' => 'text', 'help' => 'Its lowest U, e.g. 12.'],
            ['name' => 'machine_rack_height', 'label' => 'Height (U)', 'type' => 'number'],
            ['name' => 'machine_mac', 'label' => 'MAC address(es)', 'type' => 'mac', 'help' => 'Its network cards, one per line.'],
            ['name' => 'machine_ipv6', 'label' => 'IPv6', 'type' => 'textarea', 'help' => 'Addresses or prefixes, one per line.'],
            ['name' => 'machine_ipmi_ip', 'label' => 'IPMI / iDRAC IP', 'type' => 'ip', 'help' => 'As last known: in a DHCP pool it may change.'],
            ['name' => 'machine_ipmi_mac', 'label' => 'IPMI / iDRAC MAC', 'type' => 'mac', 'help' => 'Finds it again in the DHCP leases when its address changes.'],
            ['name' => 'machine_serial', 'label' => 'Serial number / service tag', 'type' => 'text'],
            ['name' => 'machine_switch_port', 'label' => 'Switch port', 'type' => 'text'],
            ['name' => 'machine_pdu_port', 'label' => 'PDU outlet', 'type' => 'text'],
            ['name' => 'machine_os', 'label' => 'Operating system', 'type' => 'text'],
            ['name' => 'machine_specs', 'label' => 'CPU / RAM / disks', 'type' => 'textarea'],
            ['name' => 'machine_notes', 'label' => 'Notes', 'type' => 'textarea'],
        ];
    }
}
