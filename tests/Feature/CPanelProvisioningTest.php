<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\ModuleAction;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Exception;
use GuzzleHttp\Promise\Create;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The cPanel module against WHM's answers as a real WHM 138 gave them (virgo, 2026-10-15, docs/provisioning.md):
 * «Change Package» is WHM's changepackage (modifyacct ignores a plan and still answers result 1); a refused one says why;
 * a create whose answer was lost is not made twice.
 */
class CPanelProvisioningTest extends TestCase
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
            'currency_code' => 'USD', 'price' => 10.00, 'domain' => 'example.test']);
    }

    /** WHM's answer to changepackage (shortened): result 1, reason OK, what changed in output.raw. */
    private static function changed(): array
    {
        return ['metadata' => ['result' => 1, 'reason' => 'OK', 'command' => 'changepackage', 'version' => 1,
            'output' => ['raw' => "<pre>\nChanging the maximum SQL databases from “100” to “100”.\n"]]];
    }

    /** WHM's accountsummary of this order's account (shortened). */
    private static function summary(string $plan): array
    {
        return ['metadata' => ['result' => 1, 'reason' => 'OK', 'command' => 'accountsummary'],
            'data' => ['acct' => [['user' => 'exampleuser', 'domain' => 'example.test', 'plan' => $plan]]]];
    }

    public function test_change_package_is_whms_changepackage_with_the_new_products_package(): void
    {
        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'exampleuser']);
        Http::fake([self::WHM . '/changepackage*' => Http::response(self::changed()), self::WHM . '/accountsummary*' => Http::response(self::summary('undefined'))]);

        ExtensionHelper::upgradeServer($this->service->fresh());

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/json-api/changepackage')
            && $request['user'] === 'exampleuser' && $request['pkg'] === 'Business20' && (string) $request['api.version'] === '1');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/modifyacct'));
    }

    public function test_a_refused_change_package_goes_to_pending_module_actions_with_whms_reason(): void
    {
        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'exampleuser']);
        Http::fake([self::WHM . '/changepackage*' => Http::response(['metadata' => ['result' => 0, 'command' => 'changepackage',
            'reason' => 'API failure: (XID 227fma) Specified package does not exist.']]), self::WHM . '/accountsummary*' => Http::response(self::summary('undefined'))]);

        try {
            ExtensionHelper::upgradeServer($this->service->fresh());
            $this->fail('a refused change must throw');
        } catch (Exception $e) {
            $this->assertStringContainsString('Specified package does not exist', $e->getMessage());
        }
        $action = ModuleAction::query()->open()->sole();
        $this->assertSame('upgrade', $action->action);
        $this->assertStringContainsString('Specified package does not exist', (string) $action->last_error);
    }

    public function test_a_create_whose_answer_was_lost_is_not_made_twice(): void
    {
        $attempts = 0;
        Http::fake([
            self::WHM . '/createacct*' => function () use (&$attempts) {
                $attempts++;

                return Create::rejectionFor(new ConnectionException('cURL error 28: Operation timed out')); // WHM made it, the answer was lost
            },
            self::WHM . '/accountsummary*' => Http::response(self::summary('Business20')), // made, for this order's domain
        ]);
        $this->service->update(['status' => 'pending']);

        $thrown = null;
        try {
            ExtensionHelper::createServer($this->service->fresh());
        } catch (Exception $e) {
            $thrown = $e;
        }
        $this->assertInstanceOf(Exception::class, $thrown, 'the timeout throws');
        $pending = $this->service->properties()->where('key', 'cpanel_pending_username')->value('value');
        $this->assertMatchesRegularExpression('/^[a-z][a-z0-9]{7}$/', (string) $pending);
        $this->assertNull($this->service->properties()->where('key', 'cpanel_username')->value('value'), 'not an account yet for suspend/terminate');

        ExtensionHelper::createServer($this->service->fresh()); // the retry

        $this->assertSame(1, $attempts, 'no second createacct: WHM has the account');
        $this->assertSame($pending, $this->service->properties()->where('key', 'cpanel_username')->value('value'));
        $this->assertSame($pending, $this->service->fresh()->username);
        $this->assertSame('active', $this->service->fresh()->status);
    }

    public function test_a_retry_of_a_create_that_never_reached_whm_makes_it_under_the_same_name(): void
    {
        $this->service->properties()->create(['key' => 'cpanel_pending_username', 'name' => 'cPanel username (being created)', 'value' => 'abcdefgh']);
        Http::fake([
            self::WHM . '/accountsummary*' => Http::response(['metadata' => ['result' => 0, 'reason' => 'API failure: (XID wh3syj) You do not have a user named “abcdefgh”.']]),
            self::WHM . '/createacct*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'Account Creation Ok', 'command' => 'createacct']]),
        ]);

        ExtensionHelper::createServer($this->service->fresh());

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/createacct') && $request['username'] === 'abcdefgh');
        $this->assertSame('abcdefgh', $this->service->properties()->where('key', 'cpanel_username')->value('value'));
        $this->assertNull($this->service->properties()->where('key', 'cpanel_pending_username')->value('value'));
    }

    /** The server's WHM login (root in setUp; a reseller's token here). */
    private function reseller(string $username): void
    {
        $this->service->server->settings()->where('key', 'username')->update(['value' => $username]);
    }

    /** WHM's listpkgs for the login: its own packages (prefixed) and the global ones it may use. */
    private static function packages(string ...$names): array
    {
        return ['package' => array_map(fn (string $name) => ['name' => $name], $names)];
    }

    /** @param  list<string>  $listed */
    private function createsWithPlan(array $listed = []): ?string
    {
        $plan = null;
        Http::fake([
            self::WHM . '/listpkgs*' => Http::response(self::packages(...$listed)),
            self::WHM . '/createacct*' => function (Request $request) use (&$plan) {
                $plan = $request['plan'];

                return Http::response(['metadata' => ['result' => 1, 'reason' => 'Account Creation Ok', 'command' => 'createacct']]);
            },
        ]);
        ExtensionHelper::createServer($this->service->fresh());

        return $plan;
    }

    public function test_a_resellers_short_package_name_takes_its_prefix_as_whm_knows_it(): void
    {
        // A reseller of nixpal (2026-10-20): WHMCS stores «V16», WHM knows the reseller's «…_V16».
        $this->reseller('reseller1');

        $this->assertSame('reseller1_Business20', $this->createsWithPlan(['reseller1_Business20', 'reseller1_V16', 'default']));
    }

    public function test_a_global_package_a_reseller_may_use_is_not_prefixed(): void
    {
        // Code review of the prefix: root's packages (no prefix) listed for a reseller with the right ACL.
        $this->reseller('reseller1');

        $this->assertSame('Business20', $this->createsWithPlan(['Business20', 'reseller1_V16']));
    }

    public function test_a_package_already_prefixed_is_as_it_is_and_the_login_is_read_lowercase(): void
    {
        $this->reseller('Reseller1');
        $this->service->product->settings()->where('key', 'package')->update(['value' => 'reseller1_V16']);

        $this->assertSame('reseller1_V16', $this->createsWithPlan(['reseller1_V16']));
    }

    public function test_a_package_the_login_may_not_use_is_refused_with_why(): void
    {
        $this->reseller('reseller1');

        $this->expectExceptionMessage('is not one this server\'s login (reseller1) may use');
        $this->createsWithPlan(['reseller1_V16']);
    }

    public function test_root_creates_with_the_package_as_stored(): void
    {
        $this->assertSame('Business20', $this->createsWithPlan());
    }

    public function test_a_resellers_change_of_package_is_resolved_too(): void
    {
        $this->reseller('reseller1');
        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'exampleuser']);
        Http::fake([self::WHM . '/listpkgs*' => Http::response(self::packages('reseller1_Business10', 'reseller1_Business20')),
            self::WHM . '/changepackage*' => Http::response(self::changed()), self::WHM . '/accountsummary*' => Http::response(self::summary('reseller1_Business10'))]);

        ExtensionHelper::upgradeServer($this->service->fresh());

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/changepackage') && $request['pkg'] === 'reseller1_Business20');
    }

    public function test_a_resellers_unchanged_package_is_not_applied_again(): void
    {
        $this->reseller('reseller1');
        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'exampleuser']);
        Http::fake([self::WHM . '/listpkgs*' => Http::response(self::packages('reseller1_Business20')),
            self::WHM . '/accountsummary*' => Http::response(self::summary('reseller1_Business20'))]);

        ExtensionHelper::upgradeServer($this->service->fresh());

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/changepackage'));
    }

    public function test_a_resellers_config_option_package_is_resolved_as_the_products(): void
    {
        $this->reseller('reseller1');
        $this->service->properties()->create(['key' => 'package', 'name' => 'Package', 'value' => 'V16']);

        $this->assertSame('reseller1_V16', $this->createsWithPlan(['reseller1_V16', 'reseller1_Business20']));
    }
}
