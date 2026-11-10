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

/**
 * Sweep 7, provisioning (adversarial verifier).
 *
 * S7-PROVISIONING-1: the cPanel module acts on WHM's account by its username alone. The service is ours for the domain
 * «ours.test»; WHM's account by that username is by now another one (primary domain «stranger.test», owned by a reseller:
 * removed and made again in WHM since the name was stored; no MyCS service has it). Mailcow refuses such an admin
 * (ownedAdmin); cPanel's own pending-create path compares the domains (sameDomain); the normal paths do not.
 */
class Sweep7ProvisioningTest extends TestCase
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
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $product->product->settings()->create(['key' => 'package', 'value' => 'Business20']);
        $this->service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'server_id' => $server->id, 'status' => 'active', 'expires_at' => now()->addMonth(),
            'currency_code' => 'USD', 'price' => 10.00, 'domain' => 'ours.test', 'username' => 'exampl']);
        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'exampl']);
        $this->service = $this->service->fresh();

        // WHM: «exampl» is a reseller's customer's account for another domain. It owns nothing (no reseller itself).
        $stranger = ['user' => 'exampl', 'domain' => 'stranger.test', 'owner' => 'reseller1', 'plan' => 'reseller1_Small', 'suspended' => 0];
        Http::fake([
            self::WHM . '/accountsummary*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => [$stranger]]]),
            self::WHM . '/listaccts*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => []]]),
            self::WHM . '/listresellers*' => Http::response(['metadata' => ['result' => 1], 'data' => ['reseller' => ['reseller1']]]),
            self::WHM . '/*' => Http::response(['metadata' => ['result' => 1], 'data' => ['url' => 'https://whm.test:2083/cpsess1/login/?session=x']]),
        ]);
    }

    /** @return list<string> the WHM calls that change or open the account */
    private function acted(): array
    {
        return Http::recorded()
            ->map(fn (array $pair) => basename((string) parse_url($pair[0]->url(), PHP_URL_PATH)))
            ->reject(fn (string $path) => in_array($path, ['accountsummary', 'listaccts', 'listresellers', 'listpkgs', 'getpkginfo', 'version'], true))
            ->values()->all();
    }

    private function attempt(callable $action): ?Throwable
    {
        try {
            $action();
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    }

    public function test_terminate_does_not_remove_an_account_whose_primary_domain_is_not_the_services(): void
    {
        $this->attempt(fn () => ExtensionHelper::terminateServer($this->service));

        $this->assertSame([], $this->acted(), 'removeacct was sent for an account of another domain, owned by a reseller');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/removeacct'));
        $this->assertSame('exampl', $this->service->fresh()->username, 'the terminate was recorded as done');
    }

    public function test_suspend_does_not_suspend_an_account_whose_primary_domain_is_not_the_services(): void
    {
        $this->attempt(fn () => ExtensionHelper::suspendServer($this->service));

        $this->assertSame([], $this->acted(), 'suspendacct was sent for an account of another domain, owned by a reseller');
    }

    public function test_the_customer_is_not_signed_in_to_an_account_whose_primary_domain_is_not_the_services(): void
    {
        $url = null;
        $this->attempt(function () use (&$url) {
            $url = ExtensionHelper::callService($this->service, 'getLoginUrl', [null]);
        });

        $this->assertNull($url, 'a signed-in cPanel session of another domain\'s account was handed out');
        $this->assertSame([], $this->acted(), 'create_user_session was sent for an account of another domain, owned by a reseller');
    }

    public function test_the_password_of_an_account_whose_primary_domain_is_not_the_services_is_not_changed(): void
    {
        $extension = ExtensionHelper::getExtension('server', 'CPanel', $this->service->product->server->settings);
        $this->attempt(fn () => $extension->changePassword($this->service, [], ExtensionHelper::getServiceProperties($this->service), 'N3w-Passw0rd!x'));

        $this->assertSame([], $this->acted(), 'passwd was sent for an account of another domain, owned by a reseller');
    }
}
