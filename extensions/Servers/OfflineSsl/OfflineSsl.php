<?php

namespace MyCS\Extensions\Servers\OfflineSsl;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\OfflineMachineServer;

/**
 * An SSL certificate bought by hand at a provider with no module here (TheSSLStore under WHMCS, owner 2026-10-29): its
 * order kept on the service, every server action (issue, suspend, cancel, change) a task for staff (OfflineServer). Its
 * common name is the service's domain unless it says otherwise. Renewals move to Openprovider SSL as they come due.
 */
#[ExtensionMeta(
    name: 'Offline SSL',
    description: 'An SSL certificate managed by hand at its provider: its order kept, every action a task for staff',
    version: 'builtin',
    author: 'MyCS',
)]
class OfflineSsl extends OfflineMachineServer
{
    public function noun(): string
    {
        return 'certificate';
    }

    public function serviceFields(): array
    {
        return [
            ['name' => 'ssl_provider', 'label' => 'Provider', 'type' => 'text', 'help' => 'Where it was bought: TheSSLStore, a CA, a reseller.'],
            ['name' => 'ssl_order_id', 'label' => 'Order ID at the provider', 'type' => 'text'],
            ['name' => 'ssl_vendor_order_id', 'label' => 'Order ID at the CA', 'type' => 'text'],
            ['name' => 'ssl_product', 'label' => 'Certificate type', 'type' => 'text', 'help' => 'e.g. a DV, OV or wildcard product.'],
            ['name' => 'ssl_common_name', 'label' => 'Common name', 'type' => 'text', 'help' => 'When it is not the service\'s domain.'],
            ['name' => 'ssl_status', 'label' => 'Status at the provider', 'type' => 'text'],
            ['name' => 'ssl_expires_on', 'label' => 'Expires on', 'type' => 'text', 'help' => 'YYYY-MM-DD, as the provider shows it.'],
            ['name' => 'ssl_notes', 'label' => 'Notes', 'type' => 'textarea'],
        ];
    }
}
