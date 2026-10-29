<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\OfflineMachine;
use App\Console\Commands\ImportFromWhmcs;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\TerminateJob;
use App\Models\ModuleAction;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
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
 * Offline SSL (owner 2026-10-29): a certificate bought by hand at a provider with no module here (myip's 12 TheSSLStore
 * certificates under WHMCS). Its order is kept on the service, every server action is a task for staff, and the importer
 * puts WHMCS's certificates of such a module on it, never «nothing to do».
 */
class OfflineSslTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['settings.default_currency' => 'USD']);
    }

    public function test_it_is_offered_and_its_actions_are_tasks_for_staff_about_a_certificate(): void
    {
        $this->assertContains('OfflineSsl', array_column(ExtensionHelper::getExtensions('server'), 'name'));

        $server = Server::query()->create(['name' => 'TheSSLStore (offline)', 'extension' => 'OfflineSsl', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => Service::STATUS_TERMINATED, 'currency_code' => 'USD', 'price' => 10,
            'domain' => 'shop.example.test']);

        (new TerminateJob($service, false))->handle();

        $task = ModuleAction::query()->open()->where('service_id', $service->id)->sole();
        $this->assertSame(['terminate', 'OfflineSsl'], [$task->action, $task->module]);
        $this->assertStringContainsString('By hand: terminate this certificate shop.example.test', (string) $task->last_error);
        $this->assertSame('Certificate', OfflineMachine::title($service));
        $this->assertContains('ssl_expires_on', array_column(OfflineMachine::fields($service), 'name'));
        Http::assertNothingSent();
    }

    public function test_the_import_puts_whmcs_certificates_of_a_module_with_no_extension_on_offline_ssl(): void
    {
        $ssl = $this->createProduct();
        $hosting = $this->createProduct();
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, servertype TEXT)');
        $whmcs->prepare("INSERT INTO tblproducts VALUES (?, 'thesslstorefullv2'), (?, 'cpanel')")->execute([$ssl->product->id, $hosting->product->id]);
        $whmcs->exec('CREATE TABLE tblsslorders (id INTEGER PRIMARY KEY, serviceid INT, remoteid TEXT, certtype TEXT, configdata TEXT, authdata TEXT,
            status TEXT, certificate_expiry_date TEXT)');
        $whmcs->exec("INSERT INTO tblsslorders VALUES (1, 801, 'old', 'rapidssl', '', '', 'Cancelled', NULL),
            (2, 801, '77001', 'rapidssl', 'csr-and-more', 'secret-auth', 'Completed', '2027-03-01 00:00:00')");
        $whmcs->exec('CREATE TABLE mod_sslstore_orders_data (id INTEGER PRIMARY KEY, service_id INT, store_order_id INT, vendor_order_id TEXT,
            common_name TEXT, product_code TEXT, ssl_status TEXT, expiration_date TEXT)');
        $whmcs->exec("INSERT INTO mod_sslstore_orders_data VALUES (1, 802, 88002, 'CA-9', 'www.other.example.test', 'positivessl', 'active', '2027-05-02 10:00:00'),
            (2, 801, NULL, '', 'shop.example.test', '', 'active', NULL)");
        $client = User::factory()->create()->client();
        foreach ([[801, $ssl, 'shop.example.test'], [802, $ssl, 'other.example.test'], [803, $hosting, 'web.example.test']] as [$id, $product, $domain]) {
            DB::table('services')->insert(['id' => $id, 'client_id' => $client->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id,
                'status' => 'active', 'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'domain' => $domain, 'whmcs_server_id' => 0]);
        }

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        (new ReflectionMethod($command, 'importOfflineSsl'))->invoke($command);

        $server = Server::query()->where('extension', 'OfflineSsl')->sole();
        $this->assertSame('TheSSLStore (offline)', $server->name);
        $this->assertSame($server->id, $ssl->product->fresh()->server_id);
        $this->assertNull($hosting->product->fresh()->server_id, 'another module\'s product untouched');
        $this->assertSame([801 => $server->id, 802 => $server->id, 803 => null], Service::query()->orderBy('id')->pluck('server_id', 'id')->all());
        $fields = fn (int $id) => DB::table('properties')->where('model_type', Service::class)->where('model_id', $id)->orderBy('key')->pluck('value', 'key')->all();
        $this->assertSame([
            'ssl_expires_on' => '2027-03-01', 'ssl_order_id' => '77001', 'ssl_product' => 'rapidssl', 'ssl_provider' => 'TheSSLStore', 'ssl_status' => 'active',
        ], $fields(801), 'its latest WHMCS order, the module\'s status; its common name is its domain, so not repeated; never its CSR or auth data');
        $this->assertSame([
            'ssl_common_name' => 'www.other.example.test', 'ssl_expires_on' => '2027-05-02', 'ssl_order_id' => '88002', 'ssl_product' => 'positivessl',
            'ssl_provider' => 'TheSSLStore', 'ssl_status' => 'active', 'ssl_vendor_order_id' => 'CA-9',
        ], $fields(802));
        $this->assertSame([], $fields(803));
        $this->assertStringContainsString('2 TheSSLStore certificate(s) on «TheSSLStore (offline)»', $output->fetch());
        $this->assertTrue(Service::query()->find(801)->hasServerActions(), 'its actions reach staff, never «nothing to do»');
    }

    /** Reviews of #532: a paid renewal of a certificate is a task, of a machine none; its first start is its creation's. */
    public function test_a_paid_renewal_is_a_task_for_a_certificate_only(): void
    {
        foreach (['OfflineSsl' => 1, 'OfflineVm' => 0] as $module => $tasks) {
            $server = Server::query()->create(['name' => $module, 'extension' => $module, 'type' => 'server', 'enabled' => true]);
            $product = $this->createProduct();
            $product->product->update(['server_id' => $server->id]);
            $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
                'product_id' => $product->product->id, 'server_id' => $server->id, 'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD',
                'price' => 10, 'expires_at' => now()->addDays(5), 'domain' => 'shop.example.test']);

            ExtensionHelper::renewByHand($service, '2027-11-01');

            $this->assertSame($tasks, ModuleAction::query()->open()->where('service_id', $service->id)->where('action', ModuleAction::RENEW)->count(), $module);
        }
        $task = ModuleAction::query()->where('action', ModuleAction::RENEW)->sole();
        $this->assertStringContainsString('By hand: renew this certificate shop.example.test at its provider (paid until 2027-11-01)', (string) $task->last_error);
        $this->assertSame(ModuleAction::SUBJECT_BY_HAND, $task->subject);
    }

    public function test_the_imported_products_are_retired_and_every_service_of_them_is_moved(): void
    {
        $ssl = $this->createProduct();
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, servertype TEXT)');
        $whmcs->prepare("INSERT INTO tblproducts VALUES (?, 'TheSSLStoreFullV2 ')")->execute([$ssl->product->id]);
        $cpanel = Server::query()->create(['name' => 'web1', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        DB::table('services')->insert(['id' => 811, 'client_id' => User::factory()->create()->client()->id, 'product_id' => $ssl->product->id,
            'plan_id' => $ssl->plan->id, 'status' => 'active', 'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'server_id' => $cpanel->id]);

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        (new ReflectionMethod($command, 'importOfflineSsl'))->invoke($command);

        $product = $ssl->product->fresh();
        $this->assertTrue($product->hidden && $product->retired, 'not sold any more: new certificates are Openprovider SSL\'s');
        $this->assertSame(Server::query()->where('extension', 'OfflineSsl')->value('id'), Service::query()->find(811)->server_id);
        $this->assertStringContainsString('1 TheSSLStore service(s) moved from another module\'s server', $output->fetch());
    }
}
