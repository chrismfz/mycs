<?php

namespace MyCS\Extensions\Servers\NixpalLicensing;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Models\License;
use App\Models\Service;
use App\Services\Licensing\Licenses;

/**
 * nixpal licenses (WHMCS's «Licensing» server module and Licensing Addon): a product per license type, its key made on
 * activation, its status following the service. zlic.php answers for them (ZlicController, packages/nixpal-licensing).
 * The product settings are WHMCS's configoptions (docs/history/blesta-modules/licensing.md §2, §3.3): key length (co1)
 * and prefix (co2), allow reissue (co3), allow IP conflict (co5), the PSK (co8); domain and directory conflicts are not
 * checked (IP binding only).
 */
#[ExtensionMeta(
    name: 'nixpal Licensing',
    description: 'Software licenses checked by zlic.php (WHMCS Licensing Addon compatible)',
    version: 'builtin',
    author: 'MyCS',
)]
class NixpalLicensing extends Server
{
    public function getConfig($values = []): array
    {
        return [];
    }

    public function getProductConfig($values = []): array
    {
        return [
            ['name' => 'key_prefix', 'type' => 'text', 'label' => 'Key Prefix', 'placeholder' => 'ZCAgent-', 'required' => false,
                'description' => 'Put before every new key (WHMCS configoption2).'],
            ['name' => 'key_length', 'type' => 'number', 'label' => 'Key Length', 'default' => 16, 'required' => true,
                'description' => 'Hex characters after the prefix (WHMCS configoption1).'],
            ['name' => 'secret_key', 'type' => 'password', 'label' => 'Secret Key (PSK)', 'required' => true, 'encrypted' => true,
                'description' => 'The license_PSK the product\'s clients send (WHMCS configoption8). Stored encrypted.'],
            ['name' => 'allow_reissue', 'type' => 'checkbox', 'label' => 'Allow Reissue', 'default' => true, 'database_type' => 'boolean',
                'description' => 'The customer may reissue the license (up to ' . Licenses::MAX_REISSUES . ' times).'],
            ['name' => 'allow_ip_conflict', 'type' => 'checkbox', 'label' => 'Allow IP Conflict', 'default' => false, 'database_type' => 'boolean',
                'description' => 'Answer Active from any IP (WHMCS configoption5).'],
            ['name' => 'custom_fields', 'type' => 'text', 'label' => 'Custom Fields Reported', 'required' => false, 'placeholder' => 'comments',
                'description' => 'Service properties reported in «customfields», comma separated (WHMCS product custom fields).'],
        ];
    }

    public function testConfig(): bool|string
    {
        return true;
    }

    public function createServer(Service $service, $settings, $properties)
    {
        $license = Licenses::issue($service, $settings);

        return ['license_key' => $license->license_key];
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        Licenses::setStatus($service, License::STATUS_SUSPENDED);

        return true;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        Licenses::setStatus($service, License::STATUS_ACTIVE);

        return true;
    }

    /**
     * A plan or cycle change keeps the license as it is (review R7): same key, bindings and status. A move to another
     * licensing product changes the PSK its clients must send: staff reissue it there.
     */
    public function upgradeServer(Service $service, $settings, $properties)
    {
        return true;
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        Licenses::setStatus($service, License::STATUS_EXPIRED);

        return true;
    }
}
