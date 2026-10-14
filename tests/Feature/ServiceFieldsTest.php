<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Console\Commands\ImportFromWhmcs;
use App\Helpers\ExtensionHelper;
use App\Models\ClientUser;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use Filament\Facades\Filament;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * The service's own data, as WHMCS's tblhosting keeps it: domain, username, password (encrypted), dedicated and
 * assigned IPs, staff notes, suspension reason. Imported, edited and searched by staff, shown to the customer, read by
 * the server modules.
 */
class ServiceFieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['settings.default_currency' => 'USD']); // the client pages' currency switch
        $this->owner = User::factory()->create();
        $product = $this->createProduct();
        $this->service = Service::factory()->create(['client_id' => $this->owner->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth(),
            'domain' => 'example.gr', 'username' => 'examplegr', 'password' => 'S3cret!pw', 'dedicated_ip' => '192.0.2.10', 'assigned_ips' => "192.0.2.11\n192.0.2.12"]);
    }

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
    }

    public function test_the_password_is_stored_encrypted_kept_out_of_serialization_and_out_of_the_audit(): void
    {
        $stored = DB::table('services')->where('id', $this->service->id)->value('password');

        $this->assertNotSame('S3cret!pw', $stored);
        $this->assertSame('S3cret!pw', $this->service->fresh()->password);
        $this->assertArrayNotHasKey('password', $this->service->fresh()->toArray());

        config(['audit.console' => true]);
        $this->service->fresh()->update(['password' => 'N3w!pw']);
        $audit = DB::table('audits')->where('auditable_type', (new Service)->getMorphClass())->latest('id')->first();
        $this->assertStringNotContainsString('N3w', (string) $audit->new_values);
        $this->assertStringNotContainsString('S3c', (string) $audit->old_values);
    }

    public function test_the_modules_read_the_native_fields_and_their_own_properties_win(): void
    {
        $properties = ExtensionHelper::getServiceProperties($this->service->fresh());

        $this->assertSame('example.gr', $properties['domain']);
        $this->assertSame('examplegr', $properties['cpanel_username'], 'an imported cPanel account has only the native username');
        $this->assertSame('192.0.2.10', $properties['dedicated_ip']);

        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'fromthemodule']);
        $this->assertSame('fromthemodule', ExtensionHelper::getServiceProperties($this->service->fresh())['cpanel_username']);
    }

    public function test_staff_edit_and_find_a_service_by_domain_or_ip(): void
    {
        $staff = $this->staff();

        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->assertFormSet(['domain' => 'example.gr', 'dedicated_ip' => '192.0.2.10'])
            ->fillForm(['notes' => 'Moved from the old server', 'dedicated_ip' => 'not-an-ip'])
            ->call('save')
            ->assertHasFormErrors(['dedicated_ip']);
        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->fillForm(['notes' => 'Moved from the old server', 'dedicated_ip' => '192.0.2.20'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Moved from the old server', $this->service->fresh()->notes);
        $this->assertSame('S3cret!pw', $this->service->fresh()->password, 'kept as it was');

        $this->actingAs($staff);
        foreach (['example.gr', '192.0.2.20', '192.0.2.12'] as $search) {
            $found = ServiceResource::getGlobalSearchResults($search)->map(fn ($result) => $result->title)->implode('|');
            $this->assertStringContainsString('#' . $this->service->id, $found, "global search for {$search}");
        }
    }

    public function test_the_customer_sees_the_details_and_only_a_manager_the_login(): void
    {
        $client = $this->owner->client();
        $html = $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, [CurrentClient::SESSION_KEY => $client->id]))
            ->get(route('services.show', $this->service))->assertOk()->getContent();
        $this->assertStringContainsString('example.gr', $html);
        $this->assertStringContainsString('192.0.2.12', $html);
        $this->assertStringContainsString('examplegr', $html);
        $this->assertStringContainsString('S3cret!pw', $html, 'behind «Show», as WHMCS shows it to the customer');
        $this->assertStringNotContainsString('Moved from the old server', $html);

        $viewer = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $viewer->id, 'permissions' => ['products'], 'granted_at' => now()]);
        $html = $this->actingAs($viewer)->withSession($this->loginUser($viewer, [CurrentClient::SESSION_KEY => $client->id]))
            ->get(route('services.show', $this->service))->assertOk()->getContent();
        $this->assertStringContainsString('example.gr', $html);
        $this->assertStringNotContainsString('S3cret!pw', $html, 'a login that may only view the service');
    }

    public function test_the_cron_records_why_it_suspended_and_unsuspending_clears_it(): void
    {
        Carbon::setTestNow('2026-10-20 09:00:00');
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_invoice' => 0]);
        $this->service->update(['expires_at' => '2026-10-10']);

        $this->artisan('app:cron-job');
        $this->assertSame('suspended', $this->service->fresh()->status);
        $this->assertSame('Overdue on Payment', $this->service->fresh()->suspend_reason);

        $this->service->fresh()->update(['status' => 'active']);
        $this->assertNull($this->service->fresh()->suspend_reason);
        Carbon::setTestNow();
    }

    public function test_the_importer_brings_tblhosting_fields(): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, regdate TEXT, domain TEXT, qty INT, amount REAL,
            billingcycle TEXT, nextduedate TEXT, domainstatus TEXT, username TEXT, password TEXT, notes TEXT, suspendreason TEXT, dedicatedip TEXT, assignedips TEXT)');
        $clientId = $this->owner->client()->id;
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");
        $whmcs->exec("INSERT INTO tblclients VALUES ({$clientId}, 1)");
        $productId = $this->service->product_id;
        $whmcs->exec("INSERT INTO tblhosting VALUES (5001, {$clientId}, NULL, {$productId}, '2024-01-02', ' shop.example.gr ', 1, 10, 'Monthly', '2026-11-02', 'Suspended',
            'shopex', 'whmcs-encrypted-blob', 'VIP customer', 'Abuse report', '198.51.100.5', '198.51.100.6\r\n198.51.100.7')");
        $whmcs->exec("INSERT INTO tblhosting VALUES (5002, {$clientId}, NULL, {$productId}, '2024-01-02', '', 1, 10, 'Monthly', '2026-11-02', 'Active',
            '', '', '', 'old reason', '', '')");

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        (new \ReflectionMethod($command, 'importServices'))->invoke($command);

        $imported = Service::query()->find(5001);
        $this->assertSame(['shop.example.gr', 'shopex', '198.51.100.5', "198.51.100.6\n198.51.100.7", 'VIP customer', 'Abuse report'],
            [$imported->domain, $imported->username, $imported->dedicated_ip, $imported->assigned_ips, $imported->notes, $imported->suspend_reason]);
        $this->assertNull($imported->password, 'WHMCS-encrypted: brought by its own step');
        $empty = Service::query()->find(5002);
        $this->assertSame([null, null, null, null], [$empty->domain, $empty->username, $empty->dedicated_ip, $empty->suspend_reason]);
    }

    public function test_a_domain_a_checkout_wrote_as_a_property_moves_to_the_service(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Re-running a migration inside the test transaction needs transactional DDL (sqlite).');
        }
        $migration = require database_path('migrations/2026_11_02_000000_add_whmcs_fields_to_services.php');
        $migration->down();
        DB::table('properties')->insert(['model_type' => Service::class, 'model_id' => $this->service->id, 'key' => 'domain', 'name' => 'Domain', 'value' => 'from-checkout.gr']);

        $migration->up();

        $this->assertSame('from-checkout.gr', DB::table('services')->where('id', $this->service->id)->value('domain'));
    }

    public function test_a_new_orders_domain_property_becomes_the_services_domain(): void
    {
        $this->service->update(['domain' => null]);

        $this->service->properties()->create(['key' => 'domain', 'name' => 'Domain', 'value' => 'neworder.gr']);
        $this->assertSame('neworder.gr', $this->service->fresh()->domain);

        $this->service->properties()->where('key', 'domain')->first()->update(['value' => 'changed.gr']);
        $this->assertSame('neworder.gr', $this->service->fresh()->domain, 'a domain already on the service is kept');
    }
}
