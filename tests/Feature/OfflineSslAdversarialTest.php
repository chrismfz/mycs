<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Console\Commands\ImportFromWhmcs;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Service\RenewServiceService;
use Filament\Facades\Filament;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Adversarial review of #532 (Offline SSL, and the importer's step for WHMCS's TheSSLStore certificates).
 */
class OfflineSslAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['settings.default_currency' => 'USD']);
    }

    private function offlineSslService(string $status = 'active'): Service
    {
        $server = Server::query()->create(['name' => 'TheSSLStore (offline)', 'extension' => 'OfflineSsl', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);

        return Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'server_id' => $server->id, 'status' => $status, 'currency_code' => 'USD', 'price' => 10,
            'quantity' => 1, 'domain' => 'shop.example.test', 'expires_at' => now()->addDays(3)]);
    }

    /** @param array<string, list<array<int, mixed>>> $tables */
    private function import(array $tables, string $ddl): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec($ddl);
        foreach ($tables as $table => $rows) {
            foreach ($rows as $row) {
                $whmcs->prepare("INSERT INTO {$table} VALUES (" . implode(',', array_fill(0, count($row), '?')) . ')')->execute($row);
            }
        }
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new ReflectionMethod($command, 'importOfflineSsl'))->invoke($command);
    }

    /**
     * ADV-532-1. A certificate is not a machine: its renewal is a new order at the provider (TheSSLStore's WHMCS module
     * did it on the paid renewal invoice). Here a customer pays the renewal of an active Offline SSL service: the service's
     * date moves a year, but no task reaches staff (renewal is not a server action, so Offline SSL never hears of it).
     * The customer has paid, the certificate expires at the CA, and nothing tells staff to renew it.
     */
    public function test_adv_532_1_a_paid_renewal_of_a_certificate_is_a_task_for_staff(): void
    {
        $service = $this->offlineSslService();
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'currency_code' => 'USD', 'status' => Invoice::STATUS_UNPAID]);
        $item = $invoice->items()->create(['description' => 'Renewal', 'quantity' => 1, 'price' => 10, 'reference_type' => Service::class, 'reference_id' => $service->id]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid']);

        (new RenewServiceService)->handle($service->fresh(), $item);

        $this->assertTrue($service->fresh()->expires_at->greaterThan(now()->addMonth()->subDays(1)), 'the renewal was applied');
        $this->assertTrue(ModuleAction::query()->open()->where('service_id', $service->id)->exists(),
            'a paid renewal of an offline certificate left staff no task: the customer paid, the certificate expires at the provider');
        Http::assertNothingSent();
    }

    /**
     * ADV-532-2. In WHMCS the module of a service is its product's (servertype); tblhosting.server only says which server
     * of that module. A TheSSLStore service whose tblhosting.server points at a server imported here of another module (a
     * product switched to TheSSLStore, or the dropdown left set) is «kept» on that server: every action of the
     * certificate (terminate for non-payment) then runs on a cPanel server, with the service's username.
     */
    public function test_adv_532_2_a_certificate_left_on_another_modules_server_is_put_on_offline_ssl(): void
    {
        $cpanel = Server::query()->create(['name' => 'web1', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $ssl = $this->createProduct();
        $client = User::factory()->create()->client();
        DB::table('services')->insert(['id' => 901, 'client_id' => $client->id, 'product_id' => $ssl->product->id, 'plan_id' => $ssl->plan->id,
            'status' => 'active', 'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'domain' => 'shop.example.test',
            'server_id' => $cpanel->id, 'whmcs_server_id' => 3]);

        $this->import(['tblproducts' => [[$ssl->product->id, 'thesslstorefullv2']]], 'CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, servertype TEXT)');

        $offline = Server::query()->where('extension', 'OfflineSsl')->sole();
        $this->assertSame($offline->id, Service::query()->find(901)->provisioningServer()?->id,
            'a TheSSLStore certificate stays on a cPanel server: its terminate goes to cPanel, never a task for staff');
        Http::assertNothingSent();
    }

    /**
     * ADV-532-3. mod_sslstore_orders_data has a row per order of the service (the first, then a renewal or reissue). The
     * «latest» is merged over the older ones field by field: a newer order not issued yet (no CA order ID, no expiry)
     * inherits the old order's CA ID and expiry. Staff see the new order's ID beside the old certificate's CA order and
     * expiry date, as if the new one were issued and valid until then.
     */
    public function test_adv_532_3_a_newer_order_never_takes_the_older_orders_ca_id_or_expiry(): void
    {
        $ssl = $this->createProduct();
        $client = User::factory()->create()->client();
        DB::table('services')->insert(['id' => 902, 'client_id' => $client->id, 'product_id' => $ssl->product->id, 'plan_id' => $ssl->plan->id,
            'status' => 'active', 'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'domain' => 'shop.example.test', 'whmcs_server_id' => 0]);

        $this->import([
            'tblproducts' => [[$ssl->product->id, 'thesslstorefullv2']],
            'mod_sslstore_orders_data' => [
                [1, 902, 1001, 'CA-OLD', 'shop.example.test', 'rapidssl', 'active', '2026-01-10 00:00:00'],
                [2, 902, 1002, '', 'shop.example.test', 'rapidssl', 'pending', null],
            ],
        ], 'CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, servertype TEXT);
            CREATE TABLE mod_sslstore_orders_data (id INTEGER PRIMARY KEY, service_id INT, store_order_id INT, vendor_order_id TEXT,
            common_name TEXT, product_code TEXT, ssl_status TEXT, expiration_date TEXT)');

        $fields = DB::table('properties')->where('model_type', Service::class)->where('model_id', 902)->pluck('value', 'key')->all();
        $this->assertSame(['1002', 'pending'], [$fields['ssl_order_id'] ?? null, $fields['ssl_status'] ?? null]);
        $this->assertNotSame('CA-OLD', $fields['ssl_vendor_order_id'] ?? null, 'the new order shows the old certificate\'s CA order');
        $this->assertNotSame('2026-01-10', $fields['ssl_expires_on'] ?? null, 'the new order shows the old certificate\'s expiry');
        Http::assertNothingSent();
    }

    /** Sanity (passes): the staff page names a certificate, and its details are edited there. */
    public function test_the_staff_page_says_certificate_and_edits_its_details(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $service = $this->offlineSslService();
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);

        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->assertSee('Certificate')->assertSee('Edit certificate details')->assertDontSee('Edit machine details')
            ->callAction('editMachine', ['ssl_order_id' => '123', 'ssl_expires_on' => '2027-01-01'])
            ->assertHasNoActionErrors();

        $this->assertSame('123', $service->fresh()->properties->firstWhere('key', 'ssl_order_id')?->value);
        Http::assertNothingSent();
    }
}
