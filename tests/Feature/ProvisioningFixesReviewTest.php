<?php

namespace Tests\Feature;

use App\Admin\Resources\ServerGroupResource\Pages\EditServerGroup;
use App\Helpers\ExtensionHelper;
use App\Models\ConfigOption;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Exception;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of fix/provisioning (a174e4d). Every test here fails on the branch and describes the expected
 * behaviour. WHM answers are shaped as the PR's own fixtures (CPanelProvisioningTest) and WHM API 1 docs.
 */
class ProvisioningFixesReviewTest extends TestCase
{
    use RefreshDatabase;

    private const WHM = 'https://whm.test:2087/json-api';

    private Server $whm;

    private Product $product;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        $this->whm = Server::query()->create(['name' => 'WHM', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $this->whm->settings()->create(['key' => $key, 'value' => $value]);
        }
        $product = $this->createProduct();
        $this->product = $product->product;
        $this->product->update(['server_id' => $this->whm->id]);
        $this->product->settings()->create(['key' => 'package', 'value' => 'Business20']);
        $this->service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $this->product->id, 'server_id' => $this->whm->id, 'status' => 'active', 'expires_at' => now()->addMonth(),
            'currency_code' => 'USD', 'price' => 10.00, 'domain' => 'example.test']);
    }

    private static function changed(): array
    {
        return ['metadata' => ['result' => 1, 'reason' => 'OK', 'command' => 'changepackage', 'version' => 1,
            'output' => ['raw' => "<pre>\nChanging the maximum SQL databases from “100” to “100”.\n"]]];
    }

    /** WHM API 1 accountsummary for an existing account (shortened). */
    private static function summary(string $user, string $domain, string $plan): array
    {
        return ['metadata' => ['result' => 1, 'reason' => 'OK', 'command' => 'accountsummary', 'version' => 1],
            'data' => ['acct' => [['user' => $user, 'domain' => $domain, 'plan' => $plan, 'email' => 'someone@other.test', 'owner' => 'root']]]];
    }

    /**
     * R1a. A package chosen by a config option (env variable «package», what createServer honours:
     * `$properties['package'] ?? $settings['package']`) is overwritten by the product's package on every change:
     * sweep §4 #7 (α) asked for «το properties['package'] όπως στο create». Before this PR the call was a no-op.
     */
    public function test_change_package_honours_the_package_config_option_as_create_does(): void
    {
        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'exampleuser']);
        $option = ConfigOption::query()->create(['name' => 'Package', 'env_variable' => 'package', 'type' => 'select', 'upgradable' => true]);
        $pro = ConfigOption::query()->create(['name' => 'Pro', 'env_variable' => 'Pro50', 'type' => 'select', 'parent_id' => $option->id]);
        $this->service->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $pro->id]);
        Http::fake([
            self::WHM . '/changepackage*' => Http::response(self::changed()),
            self::WHM . '/accountsummary*' => Http::response(self::summary('exampleuser', 'example.test', 'Business20')),
        ]);

        ExtensionHelper::upgradeServer($this->service->fresh());

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/changepackage') && $request['pkg'] === 'Business20');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/changepackage') && $request['pkg'] === 'Pro50');
    }

    /**
     * R1b. An options-only upgrade (UpgradeJob runs for every applied upgrade, ServiceUpgradeService:124) now re-applies
     * the same package: per docs/provisioning.md «ένα πακέτο χωρίς shell κόβει το shell» (and crons), and custom limits
     * staff set in WHM go back to the package's. WHMCS/Blesta call changepackage only when the package changed.
     */
    public function test_change_package_is_not_sent_when_the_account_is_already_on_that_package(): void
    {
        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'exampleuser']);
        Http::fake([
            self::WHM . '/changepackage*' => Http::response(self::changed()),
            self::WHM . '/accountsummary*' => Http::response(self::summary('exampleuser', 'example.test', 'Business20')),
        ]);

        ExtensionHelper::upgradeServer($this->service->fresh());

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/changepackage'));
    }

    /**
     * R2. A createacct WHM refused (result 0) keeps the pending name. If the refusal was «that user exists» (another
     * customer's account), the retry's accountsummary says result 1 and the service adopts that account: username,
     * SSO login (getLoginUrl), suspend/terminate all act on someone else's cPanel. accountsummary returns the domain:
     * it is never compared with the order's. A name-specific refusal is also retried with the same name forever.
     */
    public function test_a_refused_create_never_adopts_another_customers_account_on_retry(): void
    {
        $this->service->update(['status' => 'pending']);
        $this->service->properties()->create(['key' => 'cpanel_pending_username', 'name' => 'cPanel username (being created)', 'value' => 'abcdefgh']);
        Http::fake([
            // abcdefgh belongs to another customer (other.test): WHM refuses the create, then accountsummary finds it.
            self::WHM . '/accountsummary*' => Http::response(self::summary('abcdefgh', 'other.test', 'Personal5')),
            self::WHM . '/createacct*' => Http::response(['metadata' => ['result' => 0, 'command' => 'createacct',
                'reason' => 'Sorry, a user with that name (abcdefgh) already exists.']]),
        ]);

        try {
            ExtensionHelper::createServer($this->service->fresh());
        } catch (Exception) {
            // a refusal is fine; adopting is not
        }

        $this->assertNotSame('abcdefgh', $this->service->properties()->where('key', 'cpanel_username')->value('value'),
            'the account of other.test was adopted as this service\'s (example.test)');
        $this->assertNotSame('abcdefgh', $this->service->fresh()->username);
        $this->assertSame('pending', $this->service->fresh()->status, 'not started on someone else\'s account');
    }

    /**
     * R3. The group's edit page deletes with a bare DeleteAction: the model's DisplayException (no global handler)
     * escapes as a 500. The table action got a before() guard, the page did not.
     */
    public function test_deleting_a_used_group_from_its_edit_page_tells_staff_instead_of_failing(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner ' . uniqid(), 'permissions' => ['*']])->id]);
        $group = ServerGroup::query()->create(['name' => 'G', 'fill_type' => ServerGroup::FILL_LEAST_FULL]);
        $this->product->update(['server_group_id' => $group->id]);

        $this->livewireAs($staff)->test(EditServerGroup::class, ['record' => $group->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified();

        $this->assertNotNull($group->fresh());
    }

    /**
     * R4. productUpgrades compares the product's server module, not the service's own server (where its account is).
     * A service on the WHM server whose product lost its server (module null) is offered a module-less product; the
     * change (ServiceObserver::keepServerOfProductsModule) then drops its server_id and the cPanel account stays alive
     * with no server recorded: what the PR set out to prevent («would leave the account on the old server, alive»).
     */
    public function test_upgrades_follow_the_module_of_the_services_own_server(): void
    {
        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'exampleuser']);
        $this->product->update(['server_id' => null]); // detached (as CreateJob's comment allows)
        $plain = $this->createProduct()->product; // server_id null
        $this->product->upgrades()->sync([$plain->id]);

        $this->assertSame([], $this->service->fresh()->productUpgrades()->pluck('id')->values()->all(),
            'the account lives on a cPanel server: a module-less product would orphan it');
    }

    /**
     * R5. A create whose answer was lost leaves only the pending name; if the service is then terminated (staff, or a
     * cancellation), terminateServer says «not created» and the account WHM made stays, unbilled. The pending name is
     * known: terminate can ask accountsummary as the create retry does.
     */
    public function test_terminate_removes_an_account_made_by_a_create_whose_answer_was_lost(): void
    {
        $this->service->update(['status' => 'pending']);
        $this->service->properties()->create(['key' => 'cpanel_pending_username', 'name' => 'cPanel username (being created)', 'value' => 'abcdefgh']);
        Http::fake([
            self::WHM . '/accountsummary*' => Http::response(self::summary('abcdefgh', 'example.test', 'Business20')),
            self::WHM . '/removeacct*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'abcdefgh account removed', 'command' => 'removeacct']]),
        ]);

        try {
            ExtensionHelper::terminateServer($this->service->fresh());
        } catch (Exception $e) {
            $this->fail('terminate of a lost-answer account: ' . $e->getMessage());
        }

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/removeacct') && $request['user'] === 'abcdefgh');
    }
}
