<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Throwable;

/** A server's protected accounts (MyCS's own, staff's, root) are never acted on by a module action. */
class CPanelProtectedAccountsTest extends TestCase
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
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key', 'protected_accounts' => 'MyCSapp, staffmail'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $product->product->settings()->create(['key' => 'package', 'value' => 'Business20']);
        $this->service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'server_id' => $server->id, 'status' => 'active', 'expires_at' => now()->addMonth(),
            'currency_code' => 'USD', 'price' => 10.00, 'domain' => 'example.test']);
    }

    private function account(string $username): Service
    {
        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => $username]);

        return $this->service->fresh();
    }

    /** @return list<string> the WHM endpoints called */
    private function called(): array
    {
        return Http::recorded()->map(fn (array $pair) => (string) parse_url($pair[0]->url(), PHP_URL_PATH))->values()->all();
    }

    public function test_a_protected_account_is_never_suspended_terminated_or_logged_into(): void
    {
        Http::fake([self::WHM . '/*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => [], 'url' => 'https://whm.test/x']])]);

        foreach (['mycsapp', 'staffmail', 'root'] as $username) {
            $service = $this->account($username);
            foreach (['suspendServer', 'unsuspendServer', 'terminateServer'] as $action) {
                try {
                    ExtensionHelper::$action($service);
                    $this->fail("{$action} of {$username} went through");
                } catch (Throwable $e) {
                    $this->assertStringContainsString('protected account', $e->getMessage(), "{$action} of {$username}");
                }
            }
            $this->service->properties()->where('key', 'cpanel_username')->delete();
        }

        $this->assertSame([], array_values(array_filter($this->called(), fn (string $path) => !preg_match('~/(accountsummary|listaccts|listresellers)$~', $path))));
    }

    public function test_another_account_is_acted_on_as_before(): void
    {
        Http::fake([self::WHM . '/*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => []]])]);

        ExtensionHelper::suspendServer($this->account('exampleuser'));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/suspendacct') && $request['user'] === 'exampleuser');
    }
}
