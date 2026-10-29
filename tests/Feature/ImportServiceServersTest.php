<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Jobs\Server\TerminateJob;
use App\Models\ModuleAction;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Service\DeleteService;
use Exception;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Each imported account's own server (AUD4-1) and the suspension marks of the modules that want them (AUD4-2): an account
 * from WHMCS acts on its WHMCS server or on none, never on its product's; one WHMCS's module suspended is enabled here
 * when paid.
 */
class ImportServiceServersTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['settings.default_currency' => 'USD']);
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, server INT, regdate TEXT, domain TEXT,
            qty INT, amount REAL, billingcycle TEXT, nextduedate TEXT, domainstatus TEXT, username TEXT, password TEXT, notes TEXT, suspendreason TEXT,
            dedicatedip TEXT, assignedips TEXT, overideautosuspend INT, overidesuspenduntil TEXT)');
        $this->whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");
    }

    private function server(string $extension, ?int $whmcsId, string $host): Server
    {
        $server = Server::query()->create(['name' => $extension . $whmcsId, 'extension' => $extension, 'type' => 'server', 'enabled' => true]);
        $server->forceFill(['whmcs_id' => $whmcsId])->save();
        foreach (['host' => $host, 'username' => 'reseller', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }

        return $server;
    }

    private function account(int $id, int $productId, int $server, string $status, string $username = 'acct'): void
    {
        $client = User::factory()->create()->client();
        $this->whmcs->exec("INSERT OR IGNORE INTO tblclients VALUES ({$client->id}, 1)");
        $this->whmcs->prepare("INSERT INTO tblhosting VALUES (?, ?, NULL, ?, ?, '2024-01-01', 'site.example.test', 1, 10, 'Monthly', '2026-12-01', ?, ?,
            '', '', ?, '', '', 0, '0000-00-00')")->execute([$id, $client->id, $productId, $server, $status, $username, $status === 'Suspended' ? 'Overdue on Payment' : '']);
    }

    private function import(): string
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $this->output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));
        (new ReflectionMethod($command, 'importServices'))->invoke($command);

        return $this->output->fetch();
    }

    public function test_an_account_whose_whmcs_server_is_not_here_has_none_and_goes_to_staff(): void
    {
        $productServer = $this->server('CPanel', 1, 'https://orion.test:2087');
        $product = $this->createProduct();
        $product->product->update(['server_id' => $productServer->id]);
        $this->account(701, $product->product->id, 9, 'Active', 'shopgr'); // server 9: deleted in WHMCS, or skipped
        $this->account(702, $product->product->id, 0, 'Active', 'other');  // no server in WHMCS

        $output = $this->import();

        $this->assertSame([701 => [null, 9], 702 => [null, 0]],
            Service::query()->orderBy('id')->get()->mapWithKeys(fn (Service $s) => [$s->id => [$s->server_id, $s->whmcs_server_id]])->all());
        $this->assertNull(Service::query()->find(701)->provisioningServer(), 'never its product\'s server');
        $this->assertStringContainsString('1 live service(s) whose WHMCS server is not here', $output);

        DB::table('services')->where('id', 701)->update(['status' => Service::STATUS_TERMINATED]);
        try {
            (new TerminateJob(Service::query()->find(701), false))->handle();
            $this->fail('a terminate with no server known is refused, not «nothing to do»');
        } catch (Exception $e) {
            $this->assertStringContainsString('came from WHMCS without its server', $e->getMessage());
        }
        Http::assertNothingSent();
        $this->assertStringContainsString('WHMCS server #9', (string) ModuleAction::query()->where('service_id', 701)->value('last_error'));
    }

    public function test_the_cpanel_sync_and_bulk_mail_see_an_imported_account_on_its_own_server_only(): void
    {
        $productServer = $this->server('CPanel', 1, 'https://orion.test:2087');
        $ownServer = $this->server('CPanel', 2, 'https://rigel.test:2087');
        $product = $this->createProduct();
        $product->product->update(['server_id' => $productServer->id]);
        $this->account(711, $product->product->id, 2, 'Active');
        $this->account(712, $product->product->id, 9, 'Active');
        $this->import();
        // One made here before a service kept its server: its product's, as before.
        $made = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'server_id' => null, 'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD']);

        $this->assertSame([$made->id], Service::query()->onServers([$productServer->id])->pluck('id')->all());
        $this->assertSame([711], Service::query()->onServers([$ownServer->id])->pluck('id')->all());
        $this->assertSame($productServer->id, $made->provisioningServer()?->id);
    }

    public function test_a_suspended_centova_or_mailcow_account_is_marked_suspended_by_us(): void
    {
        $centova = $this->server('CentovaCast', 3, 'https://stream.test:2199');
        $mailcow = $this->server('Mailcow', 4, 'https://mail.test');
        $cpanel = $this->server('CPanel', 1, 'https://orion.test:2087');
        $radio = $this->createProduct();
        $radio->product->update(['server_id' => $centova->id]);
        $mail = $this->createProduct();
        $mail->product->update(['server_id' => $mailcow->id]);
        $hosting = $this->createProduct();
        $hosting->product->update(['server_id' => $cpanel->id]);
        $this->account(721, $radio->product->id, 3, 'Suspended', 'radio1');
        $this->account(722, $mail->product->id, 4, 'Suspended', 'mailadmin');
        $this->account(723, $radio->product->id, 0, 'Suspended', 'radio2'); // no server in WHMCS: its product's module
        $this->account(724, $radio->product->id, 3, 'Active', 'radio3');
        $this->account(725, $hosting->product->id, 1, 'Suspended', 'web1');

        $output = $this->import();

        $this->assertSame([
            [721, 'centova_disabled_by_us', 'down'],
            [722, 'mailcow_locked_by_us', '1'],
            [723, 'centova_disabled_by_us', 'down'],
        ], DB::table('properties')->where('model_type', Service::class)->orderBy('model_id')->get()
            ->map(fn ($p) => [(int) $p->model_id, $p->key, $p->value])->all());
        $this->assertStringContainsString('3 suspended service(s) marked as suspended by us', $output);
        $this->assertMatchesRegularExpression('/722\s*\|\s*mailcow\s*\|\s*mailadmin/', $output);
    }

    public function test_the_full_import_brings_whmcs_servers_and_puts_each_account_on_its_own(): void
    {
        $this->whmcs->exec('CREATE TABLE tblservers (id INTEGER PRIMARY KEY, name TEXT, type TEXT, hostname TEXT, ipaddress TEXT, port INT, username TEXT,
            maxaccounts INT, active INT, disabled INT)');
        $this->whmcs->exec("INSERT INTO tblservers VALUES (1, 'orion', 'cpanel', 'orion.example.test', '', 0, 'root', 0, 1, 0),
            (2, 'rigel', 'cpanel', 'rigel.example.test', '', 0, 'root', 500, 0, 0), (3, 'stream', 'centovacast', 'stream.example.test', '', 0, 'admin', 0, 1, 0),
            (4, 'other', 'ngm', 'ngm.example.test', '', 0, '', 0, 1, 0)");
        $this->whmcs->exec('CREATE TABLE tblservergroups (id INTEGER PRIMARY KEY, name TEXT, filltype INT)');
        $this->whmcs->exec("INSERT INTO tblservergroups VALUES (1, 'shared', 1)");
        $this->whmcs->exec('CREATE TABLE tblservergroupsrel (groupid INT, serverid INT)');
        $this->whmcs->exec('INSERT INTO tblservergroupsrel VALUES (1, 2), (1, 1)');
        $hosting = $this->createProduct();
        $radio = $this->createProduct();
        $this->whmcs->exec('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, name TEXT, servertype TEXT, servergroup INT)');
        $this->whmcs->prepare("INSERT INTO tblproducts VALUES (?, 'Hosting', 'cpanel', 1), (?, 'Radio', 'centovacast', 0)")
            ->execute([$hosting->product->id, $radio->product->id]);
        $this->account(731, $hosting->product->id, 1, 'Active');
        $this->account(732, $hosting->product->id, 2, 'Active');
        $this->account(733, $radio->product->id, 3, 'Suspended', 'radio1');
        $this->account(734, $hosting->product->id, 4, 'Active'); // a module with no extension: not here

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new ReflectionMethod($command, 'importServers'))->invoke($command);
        $this->import();

        $servers = Server::query()->pluck('id', 'whmcs_id');
        $this->assertSame([1, 2, 3], $servers->keys()->sort()->values()->all());
        $this->assertSame([731 => $servers[1], 732 => $servers[2], 733 => $servers[3], 734 => null],
            Service::query()->orderBy('id')->pluck('server_id', 'id')->all());
        $this->assertSame('https://stream.example.test:2199', Server::query()->find($servers[3])->settings()->where('key', 'host')->value('value'));
        // The product: its group, and the group's first server of its module by WHMCS's order (server id), as the catalog gives it.
        $this->assertNotNull($hosting->product->fresh()->server_group_id);
        $this->assertSame($servers[1], $hosting->product->fresh()->server_id);
        $this->assertSame($servers[3], $radio->product->fresh()->server_id);
        $this->assertSame('down', DB::table('properties')->where('model_id', 733)->where('key', 'centova_disabled_by_us')->value('value'));
    }

    /** Review of #530, R1 and R3: no account yet (pending), or none on a server in WHMCS (0): its product's, as before. */
    public function test_a_pending_account_or_one_on_no_whmcs_server_takes_its_products_server(): void
    {
        $productServer = $this->server('CPanel', 1, 'https://orion.test:2087');
        $product = $this->createProduct();
        $product->product->update(['server_id' => $productServer->id]);
        $this->account(741, $product->product->id, 9, 'Pending'); // ordered on a server since deleted: its creation picks one
        $this->account(742, $product->product->id, 0, 'Active');
        $this->import();

        foreach ([741, 742] as $id) {
            $service = Service::query()->find($id);
            $this->assertFalse($service->serverLeftInWhmcs());
            $this->assertSame($productServer->id, $service->provisioningServer()?->id, "#{$id}");
        }
        $this->assertSame([741, 742], Service::query()->onServers([$productServer->id])->orderBy('id')->pluck('id')->all());
    }

    /** Review of #530, R2: a delete with terminate is refused, not done without its account (a gate that skipped silently). */
    public function test_deleting_an_account_left_on_a_whmcs_server_fails_to_staff(): void
    {
        $productServer = $this->server('CPanel', 1, 'https://orion.test:2087');
        $product = $this->createProduct();
        $product->product->update(['server_id' => $productServer->id]);
        $this->account(751, $product->product->id, 9, 'Active');
        $this->import();
        $service = Service::query()->find(751);
        $this->assertTrue($service->hasServerActions());

        try {
            app(DeleteService::class)->handle($service, terminate: true);
            $this->fail('the delete goes on without its account terminated');
        } catch (Exception $e) {
            $this->assertStringContainsString('came from WHMCS without its server', $e->getMessage());
        }
        $this->assertNotNull(Service::query()->find(751), 'nothing deleted');
        $this->assertSame(1, ModuleAction::query()->where('service_id', 751)->count());
        Http::assertNothingSent();
    }
}
