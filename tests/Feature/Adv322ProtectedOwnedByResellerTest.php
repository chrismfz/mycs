<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Servers\CPanel\CPanel;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #322: a protected account owned by a (not protected) WHM reseller is suspended or removed
 * with it, by suspendreseller / terminatereseller, which name only the reseller.
 */
class Adv322ProtectedOwnedByResellerTest extends TestCase
{
    use RefreshDatabase;

    private const WHM = 'https://whm.test:2087/json-api';

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        $server = Server::query()->create(['name' => 'WHM', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key', 'protected_accounts' => 'mycsapp'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $product->product->settings()->create(['key' => 'package', 'value' => 'Business20']);
        $this->service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'server_id' => $server->id, 'status' => 'active', 'expires_at' => now()->addMonth(),
            'currency_code' => 'USD', 'price' => 10.00, 'domain' => 'example.test']);
        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'resellerx']);
        $this->service->properties()->create(['key' => CPanel::RESELLER, 'name' => 'WHM reseller', 'value' => '1']);

        Http::fake([
            self::WHM . '/listresellers*' => Http::response(['metadata' => ['result' => 1], 'data' => ['reseller' => ['resellerx']]]),
            self::WHM . '/listaccts*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => [['user' => 'mycsapp', 'owner' => 'resellerx']]]]),
            self::WHM . '/*' => Http::response(['metadata' => ['result' => 1], 'data' => []]),
        ]);
    }

    private function sent(string $endpoint): bool
    {
        return Http::recorded()->contains(fn (array $pair) => str_ends_with((string) parse_url($pair[0]->url(), PHP_URL_PATH), $endpoint));
    }

    public function test_suspending_a_reseller_never_suspends_a_protected_account_it_owns(): void
    {
        try {
            ExtensionHelper::suspendServer($this->service->fresh());
        } catch (Throwable) {
            // refusing is fine
        }

        $this->assertFalse($this->sent('/suspendreseller'), 'suspendreseller of resellerx also suspends the protected mycsapp it owns');
    }

    public function test_terminating_a_reseller_never_removes_a_protected_account_it_owns(): void
    {
        CPanel::confirmTermination($this->service, 'resellerx'); // staff typed the reseller's name, not mycsapp's
        try {
            ExtensionHelper::terminateServer($this->service->fresh());
        } catch (Throwable) {
            // refusing is fine
        } finally {
            CPanel::clearConfirmation();
        }

        $this->assertFalse($this->sent('/terminatereseller'), 'terminatereseller of resellerx also deletes the protected mycsapp it owns');
    }
}
