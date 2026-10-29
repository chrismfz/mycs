<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\CreateService;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Console\Commands\ImportFromWhmcs;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class OfflineSslReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']);
    }

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
    }

    public function test_edit_page_shows_certificate_and_saves(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $server = Server::query()->create(['name' => 'TheSSLStore (offline)', 'extension' => 'OfflineSsl', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'domain' => 'a.example.test']);
        $staff = $this->staff();
        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->assertSee('Certificate')->assertSee('Edit certificate details')->assertDontSee('Edit machine details')
            ->callAction('editMachine', ['ssl_provider' => 'TheSSLStore', 'ssl_notes' => str_repeat('x', 600)])
            ->assertHasNoActionErrors();
        $this->assertSame(600, strlen($service->fresh()->properties->firstWhere('key', 'ssl_notes')->value));
    }

    public function test_create_page_renders_with_null_record(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->livewireAs($this->staff())->test(CreateService::class)->assertOk();
    }

    private function import(PDO $whmcs): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new ReflectionMethod($command, 'importOfflineSsl'))->invoke($command);
    }

    /** A later tblsslorders row of another module (MarketConnect addon on the same serviceid) overrides the TheSSLStore one. */
    public function test_a_tblsslorders_row_of_another_module_or_an_addon_is_never_taken(): void
    {
        $ssl = $this->createProduct();
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, servertype TEXT)');
        $whmcs->prepare("INSERT INTO tblproducts VALUES (?, 'thesslstorefullv2')")->execute([$ssl->product->id]);
        $whmcs->exec('CREATE TABLE tblsslorders (id INTEGER PRIMARY KEY, userid INT, serviceid INT, addon_id INT, module TEXT, remoteid TEXT, certtype TEXT, status TEXT, certificate_expiry_date TEXT)');
        $whmcs->exec("INSERT INTO tblsslorders VALUES (1, 1, 801, 0, 'thesslstorefullv2', '77001', 'rapidssl', 'Completed', '2027-03-01 00:00:00'),
            (2, 1, 801, 5, 'marketconnect', 'mc-1', 'symantec', 'Awaiting Configuration', NULL)");
        $client = User::factory()->create()->client();
        DB::table('services')->insert(['id' => 801, 'client_id' => $client->id, 'product_id' => $ssl->product->id, 'plan_id' => $ssl->plan->id,
            'status' => 'active', 'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'domain' => 'a.example.test', 'whmcs_server_id' => 0]);
        $this->import($whmcs);
        $fields = DB::table('properties')->where('model_id', 801)->pluck('value', 'key')->all();
        $this->assertSame('77001', $fields['ssl_order_id'] ?? null, 'the TheSSLStore order, not the addon\'s');
        $this->assertSame('2027-03-01', $fields['ssl_expires_on'] ?? null);
    }

    /** An older module order's CA id survives next to a newer order's store id. */
    public function test_the_module_rows_of_two_orders_are_never_mixed(): void
    {
        $ssl = $this->createProduct();
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, servertype TEXT)');
        $whmcs->prepare("INSERT INTO tblproducts VALUES (?, 'thesslstorefullv2')")->execute([$ssl->product->id]);
        $whmcs->exec('CREATE TABLE mod_sslstore_orders_data (id INTEGER PRIMARY KEY, service_id INT, store_order_id INT, vendor_order_id TEXT,
            common_name TEXT, product_code TEXT, ssl_status TEXT, expiration_date TEXT)');
        $whmcs->exec("INSERT INTO mod_sslstore_orders_data VALUES (1, 801, 100, 'CA-OLD', 'a.example.test', 'rapidssl', 'cancelled', '2025-01-01 00:00:00'),
            (2, 801, 200, '', 'a.example.test', 'rapidssl', 'pending', NULL)");
        $client = User::factory()->create()->client();
        DB::table('services')->insert(['id' => 801, 'client_id' => $client->id, 'product_id' => $ssl->product->id, 'plan_id' => $ssl->plan->id,
            'status' => 'active', 'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'domain' => 'a.example.test', 'whmcs_server_id' => 0]);
        $this->import($whmcs);
        $fields = DB::table('properties')->where('model_id', 801)->pluck('value', 'key')->all();
        $this->assertSame('200', $fields['ssl_order_id']);
        $this->assertArrayNotHasKey('ssl_vendor_order_id', $fields, 'the old order\'s CA id next to the new order');
        $this->assertArrayNotHasKey('ssl_expires_on', $fields, 'the old order\'s expiry next to the new order');
    }
}
