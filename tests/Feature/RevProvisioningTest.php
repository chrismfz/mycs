<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Independent review of the provisioning sweep's fixes (2026-11-07). */
class RevProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const WHM = 'https://whm.test:2087/json-api';

    private function serviceOn(Server $server, array $attributes = []): Service
    {
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $product->product->settings()->create(['key' => 'package', 'value' => 'basic']);

        return Service::factory()->create(array_merge(['client_id' => User::factory()->create()->client()->id,
            'plan_id' => $product->plan->id, 'product_id' => $product->product->id, 'status' => 'active',
            'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth()], $attributes));
    }

    /**
     * PROVISIONING-3 fix, false positive. A pending service holds no account: WHMCS gives an order its username when it is
     * placed (from its domain), so an abandoned, never-created order for the same domain, imported as pending, carries
     * the live account's username. cPanel's create never uses it (a fresh random name, kept as cpanel_pending_username).
     * SharedAccount::otherLive counts every status but cancelled/terminated, so that pending row blocks the suspension of
     * the overdue live account (and its termination): 4 failed attempts and a staff task for every overdue run.
     */
    public function test_a_pending_never_created_order_with_the_same_username_does_not_block_suspension(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([self::WHM . '/*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK']])]);
        $server = Server::query()->create(['name' => 'WHM', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $live = $this->serviceOn($server, ['domain' => 'shop.example.test', 'username' => 'shopexam', 'status' => Service::STATUS_SUSPENDED]);
        $this->serviceOn($server, ['domain' => 'shop.example.test', 'username' => 'shopexam', 'status' => Service::STATUS_PENDING,
            'client_id' => $live->client_id]);

        ExtensionHelper::suspendServer($live->fresh());

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/suspendacct') && $request['user'] === 'shopexam');
    }
}
