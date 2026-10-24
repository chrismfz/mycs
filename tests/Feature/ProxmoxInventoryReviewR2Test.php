<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Review of #446, round 2: the Proxmox extension (extensions/Servers/Proxmox/Proxmox.php). */
class ProxmoxInventoryReviewR2Test extends TestCase
{
    use RefreshDatabase;

    /**
     * A privsep token with no ACL (the doc's third pveum command forgotten) authenticates: /version needs no
     * privilege and /nodes answers an empty list. «Test connection» says the server works, and the first sync then
     * reads no guest at all, which is exactly the case GuestSync now refuses as «check the token's permissions».
     */
    public function test_the_connection_test_fails_for_a_token_that_sees_nothing(): void
    {
        Http::fake(function (Request $request) {
            $path = strtok(substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api2/json/')), '?');

            return match ($path) {
                'version' => Http::response(['data' => ['version' => '9.0.3']]),
                'nodes', 'cluster/resources' => Http::response(['data' => []]),
                default => Http::response(['data' => null], 403),
            };
        });
        $server = Server::query()->create(['name' => 'PVE', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        $settings = ['host' => 'https://vega.example.test:8006', 'token_id' => 'mycs@pve!api', 'token_secret' => 's3cret'];
        ExtensionHelper::saveSettings($server, $settings);

        $this->assertNotTrue(ExtensionHelper::testConfig($server->fresh(), $settings),
            'a token that sees no node is not a working connection');
    }
}
