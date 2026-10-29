<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Jobs\Server\TerminateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Pre-launch audit, flow 4 (provisioning and irreversible actions, docs/prelaunch-audit.md). Failing tests of the
 * reviewer's findings: no real server is called (Http::fake, stray requests refused).
 */
class AuditFlow4Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['settings.default_currency' => 'USD']);
    }

    private function cpanelServer(string $name, string $host, int $whmcsId): Server
    {
        $server = Server::query()->create(['name' => $name, 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $server->forceFill(['whmcs_id' => $whmcsId])->save();
        foreach (['host' => $host, 'username' => 'reseller', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }

        return $server;
    }

    /** One WHMCS account (tblhosting) on WHMCS server $server, imported here. */
    private function importService(int $id, int $clientId, int $productId, int $server, string $status, string $username): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, server INT, regdate TEXT, domain TEXT, qty INT,
            amount REAL, billingcycle TEXT, nextduedate TEXT, domainstatus TEXT, username TEXT, password TEXT, notes TEXT, suspendreason TEXT, dedicatedip TEXT,
            assignedips TEXT, overideautosuspend INT, overidesuspenduntil TEXT)');
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");
        $whmcs->exec("INSERT INTO tblclients VALUES ({$clientId}, 1)");
        $whmcs->prepare("INSERT INTO tblhosting VALUES (?, ?, NULL, ?, ?, '2024-01-01', 'shop.example.test', 1, 10, 'Monthly', '2026-12-01', ?,
            ?, '', '', ?, '', '', 0, '0000-00-00')")->execute([$id, $clientId, $productId, $server, $status, $username, $status === 'Suspended' ? 'Overdue on Payment' : '']);

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        (new ReflectionMethod($command, 'importServices'))->invoke($command);
    }

    /**
     * AUD4-1 (High). WHMCS keeps each account's server (tblhosting.server: myip has 6 cPanel servers, 657 accounts); the
     * importer never brings it, so every imported service has server_id null and Service::provisioningServer() falls back
     * to its product's server. A product sold on orion whose older accounts live on rigel: the cron's terminate of one of
     * them sends WHM removeacct for its username to orion, where that username may be another customer's account (WHMCS
     * usernames come from the domain: «shopgr» on two servers is two customers). Suspend and Change Package likewise.
     */
    public function test_aud4_1_an_imported_service_is_terminated_on_its_own_whmcs_server_not_its_products(): void
    {
        Server::query()->create(['name' => 'unrelated', 'extension' => 'Offline', 'type' => 'server', 'enabled' => true]); // ids differ from WHMCS's
        $productServer = $this->cpanelServer('orion', 'https://orion.test:2087', 1);
        $ownServer = $this->cpanelServer('rigel', 'https://rigel.test:2087', 2);
        $client = User::factory()->create()->client();
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $product->product->update(['server_id' => $productServer->id]);

        $this->importService(501, $client->id, $product->product->id, 2, 'Active', 'shopexam');

        $service = Service::query()->findOrFail(501);
        $this->assertSame($ownServer->id, $service->server_id, 'the account\'s own WHMCS server (tblhosting.server), not none');

        // Terminated for non-payment later (the cron ends it, then its job runs).
        DB::table('services')->where('id', 501)->update(['status' => Service::STATUS_TERMINATED]);
        Http::fake(['*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK']])]);
        (new TerminateJob($service->fresh(), false))->handle();

        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://orion.test'));
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://rigel.test:2087/json-api/removeacct') && $request['user'] === 'shopexam');
    }

    /**
     * AUD4-2 (High, at the cut-over). A Centova station suspended by WHMCS (its module disabled the account) came in
     * suspended, without the «disabled by us» mark (centova_disabled_by_us) the module wants before it enables anything.
     * Its customer pays: UnsuspendJob runs, CentovaCast::unsuspendServer returned true with no call, the module action
     * «completed», the customer was mailed «unsuspended», and the station stayed disabled on Centova. Mailcow's
     * mailcow_locked_by_us has the same shape for a domain admin locked under WHMCS. Through the importer (the fix: it
     * sets the mark), not a service made by hand without one: one staff disabled by hand stays disabled (by design).
     */
    public function test_aud4_2_an_imported_suspended_centova_station_is_enabled_again_when_paid(): void
    {
        $server = Server::query()->create(['name' => 'stream', 'extension' => 'CentovaCast', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://stream.test:2199', 'username' => 'admin', 'password' => 'admin-secret'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $server->forceFill(['whmcs_id' => 7])->save();
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        // Suspended by WHMCS (its module disabled the station), imported; then paid here (active again).
        $this->importService(601, User::factory()->create()->client()->id, $product->product->id, 7, 'Suspended', 'radio1');
        DB::table('services')->where('id', 601)->update(['status' => Service::STATUS_ACTIVE]);
        $service = Service::query()->findOrFail(601);

        $calls = [];
        Http::fake(function (Request $request) use (&$calls) {
            $xml = simplexml_load_string($request->body());
            $method = (string) $xml->request['method'];
            $args = [];
            foreach ($xml->request->children() as $name => $value) {
                $args[$name] = (string) $value;
            }
            $calls[] = [$method, $args];
            $data = $method === 'listaccounts' ? '<data><row><username>radio1</username><email>x@example.test</email></row></data>' : '';

            return Http::response('<?xml version="1.0" encoding="UTF-8"?><centovacast version="3.3.2"><response type="success"><message>OK</message>'
                . $data . '</response></centovacast>');
        });

        (new UnsuspendJob($service->fresh()))->handle();

        $this->assertContains(['setstatus', 'enabled'], array_map(fn ($call) => [$call[0], $call[1]['status'] ?? null], $calls),
            'the station WHMCS disabled is enabled on Centova once its customer paid');
    }
}
