<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Helpers\ExtensionHelper;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Independent review of f3283d0 (native service fields). Each test fails on f3283d0. */
class ServiceFieldsReviewTest extends TestCase
{
    use RefreshDatabase;

    private const WHM = 'https://whm.test:2087/json-api';

    private Service $service;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        config(['settings.default_currency' => 'USD']);

        $this->server = Server::query()->create(['name' => 'WHM', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $this->server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $product = $this->createProduct();
        $product->product->update(['server_id' => $this->server->id]);
        $product->product->settings()->create(['key' => 'package', 'value' => 'basic']);

        $this->service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id,
            'plan_id' => $product->plan->id, 'product_id' => $product->product->id, 'status' => 'active',
            'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth(),
            'domain' => 'example.gr', 'username' => 'examplegr', 'password' => 'S3cret!pw']);
    }

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
    }

    /**
     * R1: staff changing the password in the admin form. EditService saves through Service::applyStaffChanges, which
     * diffs on a probe model (the encrypted cast encrypts there) and then forceFills the probe's RAW dirty values on
     * the locked row: the ciphertext goes through the encrypted cast a second time. The stored password decrypts to
     * the first ciphertext, not to what staff typed; modules and the customer get garbage.
     */
    public function test_staff_changing_the_password_stores_it_encrypted_once(): void
    {
        $this->livewireAs($this->staff())->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->fillForm(['password' => 'N3w!pass'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('N3w!pass', $this->service->fresh()->password);
    }

    /** R1 (API path, same helper): applyStaffChanges with a password. */
    public function test_apply_staff_changes_with_a_password_stores_it_encrypted_once(): void
    {
        $this->service->applyStaffChanges(['password' => 'Other!pw']);

        $this->assertSame('Other!pw', $this->service->fresh()->password);
    }

    /**
     * R2: cPanel terminate deletes the `cpanel_username` property («Delete the properties»), but the native username
     * stays and getServiceProperties re-creates `cpanel_username` from it. After a termination the service still
     * looks provisioned: a retried terminate, a suspend/unsuspend or «Access cPanel» goes to that username on the
     * server, which by then may belong to another customer's account (WHMCS-style usernames derive from the domain).
     */
    public function test_after_a_cpanel_terminate_the_service_no_longer_names_an_account(): void
    {
        Http::fake([self::WHM . '/removeacct*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK', 'command' => 'removeacct']]),
            // A terminate on a root login asks first what the account owns (#315): nothing here.
            self::WHM . '/listaccts*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => ['acct' => []]])]);

        ExtensionHelper::terminateServer($this->service->fresh());

        $this->assertArrayNotHasKey('cpanel_username', ExtensionHelper::getServiceProperties($this->service->fresh()));
    }

    /**
     * R3: the native fields are merged into the module properties, and Pterodactyl (createServer/upgradeServer),
     * DirectAdmin, Virtualizor and Enhance do `array_merge($settings, $properties)`: a native `dedicated_ip`
     * (an address) now overrides Pterodactyl's product setting `dedicated_ip` (a boolean: «allocate a dedicated
     * IP»), sent as `deploy.dedicated_ip`.
     */
    public function test_a_native_dedicated_ip_does_not_override_a_product_setting_of_the_same_name(): void
    {
        $this->service->product->settings()->create(['key' => 'dedicated_ip', 'value' => '0']);
        $this->service->update(['dedicated_ip' => '192.0.2.10']);
        $service = $this->service->fresh();

        $merged = array_merge(ExtensionHelper::settingsToArray($service->product->settings), ExtensionHelper::getServiceProperties($service));

        $this->assertSame('0', (string) $merged['dedicated_ip']);
    }

    /**
     * R4: getServiceProperties decrypts the password eagerly for every module call (suspend, terminate, getActions
     * on the customer's page), also for modules that never read it. A value the current APP_KEY cannot decrypt
     * (key rotated without APP_PREVIOUS_KEYS, a WHMCS blob written raw by the planned password step) makes every
     * server action of the service throw.
     */
    public function test_an_undecryptable_password_does_not_block_server_actions(): void
    {
        DB::table('services')->where('id', $this->service->id)->update(['password' => 'not-a-laravel-ciphertext']);

        $properties = ExtensionHelper::getServiceProperties($this->service->fresh());

        $this->assertSame('examplegr', $properties['cpanel_username']);
    }

    /**
     * R5: the cron suspends with «Overdue on Payment»; staff turning that into a manual hold (placeOnHold clears
     * dunning_suspended_at, so a payment no longer unsuspends) leaves the reason. The customer pays, the service stays
     * suspended and its page still says «Overdue on Payment».
     */
    public function test_a_staff_hold_over_a_dunning_suspension_does_not_keep_the_overdue_reason(): void
    {
        $this->service->forceFill(['status' => 'suspended', 'dunning_suspended_at' => now(), 'suspend_reason' => 'Overdue on Payment'])->save();

        $this->service->fresh()->placeOnHold();

        $this->assertNull($this->service->fresh()->dunning_suspended_at);
        $this->assertNotSame('Overdue on Payment', $this->service->fresh()->suspend_reason);
    }
}
