<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Helpers\ExtensionHelper;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\ClientGroupPricing;
use App\Services\Billing\RenewalBilling;
use App\Services\ServiceUpgrade\ChangeCycle;
use App\Services\Whmcs\CatalogImport;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
 * The myip rehearsal (2026-11-04): WHMCS's Proxmox servers (pvewhmcs: Pegasus and Vega, one cluster), its Hetzner Cloud
 * one and ngm's were skipped as «no MyCS extension», and their live services came with no server. The Proxmox nodes are
 * one server here, the extension's (staff's own when there is one); Hetzner Cloud is Offline VM, ngm its own extension.
 */
class ImportClusterServersTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['settings.default_currency' => 'USD']);
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblservers (id INTEGER PRIMARY KEY, name TEXT, type TEXT, hostname TEXT, ipaddress TEXT, port INT, username TEXT,
            maxaccounts INT, active INT, disabled INT)');
        $this->whmcs->exec('CREATE TABLE tblservergroups (id INTEGER PRIMARY KEY, name TEXT, filltype INT)');
        $this->whmcs->exec('CREATE TABLE tblservergroupsrel (groupid INT, serverid INT)');
        $this->whmcs->exec('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, name TEXT, servertype TEXT, servergroup INT)');
        $this->whmcs->exec("INSERT INTO tblservers VALUES
            (25, 'Mars', 'cpanel', 'mars.example.test', '192.0.2.25', 0, 'root', 0, 1, 0),
            (54, 'Vega', 'pvewhmcs', 'vega.example.test', '192.0.2.54', 0, 'root@pam', 0, 0, 1),
            (57, 'None', 'HetznerCloud', '', '', 0, '', 0, 1, 0),
            (61, 'Pegasus', 'pvewhmcs', 'pegasus.example.test', '192.0.2.61', 0, 'root@pam', 0, 1, 0),
            (64, 'NGM-test', 'ngm', 'ngm.example.test', '', 443, 'admin', 0, 1, 0)");
    }

    /** @return array{counts: array<string, int>, notes: list<string>, server_ids: array<int, int>} */
    private function servers(): array
    {
        return (new CatalogImport($this->whmcs))->serversForFullImport();
    }

    private function setting(Server $server, string $key): ?string
    {
        return $server->settings()->where('key', $key)->value('value');
    }

    public function test_a_clusters_nodes_are_one_proxmox_server_enabled_by_its_live_node(): void
    {
        $result = $this->servers();

        $proxmox = Server::query()->where('extension', 'Proxmox')->sole();
        $this->assertSame(61, (int) $proxmox->whmcs_id, 'its live node\'s id');
        $this->assertTrue((bool) $proxmox->enabled, 'Pegasus is live, though Vega is disabled');
        $this->assertSame('https://pegasus.example.test:8006', $this->setting($proxmox, 'host'), 'a live node first');
        $this->assertSame('https://vega.example.test:8006', $this->setting($proxmox, 'node2_host'), 'the other for failover');
        $this->assertNull($this->setting($proxmox, 'username'), 'WHMCS\'s root@pam is no API token');
        $this->assertSame([54 => $proxmox->id, 61 => $proxmox->id], array_intersect_key($result['server_ids'], [54 => 0, 61 => 0]));
        $this->assertNotEmpty(array_filter($result['notes'], fn ($note) => str_contains($note, '«Vega»') && str_contains($note, '«Pegasus»') && str_contains($note, 'one cluster')));

        // Re-run: the same one server.
        $this->servers();
        $this->assertSame(1, Server::query()->where('extension', 'Proxmox')->count());
    }

    public function test_the_server_staff_set_up_for_the_cluster_is_adopted_as_it_is(): void
    {
        $own = Server::query()->create(['name' => 'vega-proxmox', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        $own->settings()->create(['key' => 'host', 'value' => 'https://node3.example.test:8006']);

        $result = $this->servers();

        $this->assertSame(1, Server::query()->where('extension', 'Proxmox')->count(), 'no second server for the same cluster');
        $own->refresh();
        $this->assertSame('vega-proxmox', $own->name);
        $this->assertTrue((bool) $own->enabled);
        $this->assertSame('https://node3.example.test:8006', $this->setting($own, 'host'), 'its address is staff\'s');
        $this->assertSame($own->id, $result['server_ids'][61]);
    }

    /** Review of #628 (M1): a re-run keeps the cluster as it is: enabled by its live node, staff's name and state. */
    public function test_a_rerun_keeps_the_cluster_as_it_is(): void
    {
        $this->servers();
        $this->servers();
        $this->assertTrue((bool) Server::query()->where('extension', 'Proxmox')->sole()->enabled, 'still enabled on the second run');

        Server::query()->where('extension', 'Proxmox')->delete();
        Server::query()->withTrashed()->where('extension', 'Proxmox')->forceDelete();
        $own = Server::query()->create(['name' => 'vega-proxmox', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => false]);
        $this->servers();
        $this->servers();
        $own->refresh();
        $this->assertSame(['vega-proxmox', false], [$own->name, (bool) $own->enabled], 'staff\'s, on every run');
        $this->assertSame(1, Server::query()->where('extension', 'Proxmox')->count());
    }

    /** Review of #628 (M2): a cluster's server staff deleted here stays deleted: no node makes another. */
    public function test_a_deleted_cluster_server_stays_deleted(): void
    {
        $this->servers();
        Server::query()->where('extension', 'Proxmox')->sole()->delete();

        $result = $this->servers();

        $this->assertSame(0, Server::query()->where('extension', 'Proxmox')->count());
        $this->assertNotEmpty(array_filter($result['notes'], fn ($note) => str_contains($note, 'deleted here, left deleted')));
    }

    /** Review of #628 (L1): staff's server at any node's address is the one, though another of the extension exists. */
    public function test_staffs_server_at_another_nodes_address_is_adopted(): void
    {
        $own = Server::query()->create(['name' => 'vega-proxmox', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        $own->settings()->create(['key' => 'host', 'value' => 'https://vega.example.test:8006']);
        Server::query()->create(['name' => 'lab', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);

        $result = $this->servers();

        $this->assertSame(2, Server::query()->where('extension', 'Proxmox')->count(), 'no third one');
        $this->assertSame($own->id, $result['server_ids'][61]);
    }

    /** Review of #628 (M3): from its own plan without prices, a change of cycle set for the next renewal can be taken back. */
    public function test_a_cycle_change_from_a_plan_without_prices_can_be_taken_back(): void
    {
        $product = $this->createProduct(); // Monthly, priced
        $quarterly = Plan::query()->findOrFail(CatalogImport::unpricedPlan($product->product, 'Quarterly'));
        $client = User::factory()->create()->client();
        $service = Service::query()->forceCreate(['client_id' => $client->id, 'product_id' => $product->product->id, 'plan_id' => $quarterly->id,
            'status' => 'active', 'price' => 62.90, 'quantity' => 1, 'currency_code' => 'USD', 'expires_at' => now()->addMonth()]);
        $this->assertFalse(ChangeCycle::plans($service)->contains('id', $quarterly->id), 'not offered while nothing is set');

        ChangeCycle::apply($service, ChangeCycle::plans($service)->sole(), ChangeCycle::NEXT_RENEWAL, 'client');
        $service->refresh();
        $this->assertNotNull($service->next_plan_id);
        $this->assertTrue(ChangeCycle::plans($service)->contains('id', $quarterly->id), 'its own plan, to take it back');

        ChangeCycle::apply($service, $quarterly, ChangeCycle::NEXT_RENEWAL, 'client');
        $service->refresh();
        $this->assertNull($service->next_plan_id);
        $this->assertSame('62.90', number_format((float) $service->price, 2, '.', ''), 'its own price kept');
    }

    /**
     * Review of #628: an account in a client group with a discount (nixpal: 10%, 24 live services). WHMCS gave it when
     * ordering and bills the amount it left; the cron's reconcile of group prices took the imported prices for ones without
     * the discount and took 10% more off on its first run (1.80 → 1.62, a custom 5.00 → 4.50).
     */
    public function test_a_groups_discount_is_not_taken_again_from_whmcss_amounts(): void
    {
        $this->hosting();
        $product = $this->createProduct();
        $client = User::factory()->create()->client();
        $group = DB::table('client_groups')->insertGetId(['name' => 'Ten off', 'discount_percent' => 10, 'created_at' => now(), 'updated_at' => now()]);
        $client->forceFill(['client_group_id' => $group])->save();
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$client->id}, 1)");
        foreach ([[911, 1.8], [912, 5.0]] as [$id, $amount]) {
            $this->whmcs->prepare("INSERT INTO tblhosting VALUES (?, ?, NULL, ?, 0, '2024-01-01', 'site.example.test', 1, ?, 'Monthly', '2026-12-01', 'Active', '',
                '', '', '', '', '', 0, '0000-00-00')")->execute([$id, $client->id, $product->product->id, $amount]);
        }

        $this->importServices();
        $this->assertSame(['10.00', '10.00'], Service::query()->orderBy('id')->pluck('group_percent')->map(fn ($p) => number_format((float) $p, 2, '.', ''))->all());

        $this->assertSame(0, ClientGroupPricing::reconcile());
        $this->assertSame(['1.80', '5.00'], Service::query()->orderBy('id')->pluck('price')->map(fn ($p) => number_format((float) $p, 2, '.', ''))->all());
    }

    public function test_hetzner_cloud_comes_as_an_offline_vm_server(): void
    {
        $result = $this->servers();

        $offline = Server::query()->where('extension', 'OfflineVm')->orderBy('whmcs_id')->get();
        $this->assertSame([57 => 'None (Hetzner Cloud, offline)'], $offline->pluck('name', 'whmcs_id')->all());
        $this->assertSame(0, $offline->sum(fn (Server $server) => $server->settings()->count()), 'no address or login: nothing is called');
        $this->assertEmpty(array_filter($result['notes'], fn ($note) => str_contains($note, 'has no MyCS extension')));
    }

    /**
     * ngm is its own extension now: its host bare, so the extension adds its API's port (9601), never WHMCS's 443 where
     * ngm serves the customers' sites; no login (its token is staff's to enter).
     */
    public function test_ngm_comes_as_its_own_extension_with_its_host_only(): void
    {
        $this->servers();

        $ngm = Server::query()->where('whmcs_id', 64)->sole();
        $this->assertSame(['Ngm', 'NGM-test', true], [$ngm->extension, $ngm->name, (bool) $ngm->enabled]);
        $this->assertSame(['host' => 'ngm.example.test'], $ngm->settings()->pluck('value', 'key')->all());
        $this->assertSame('https://ngm.example.test:9601/api/v1', ExtensionHelper::getExtension('server', 'Ngm', $ngm->settings)->api()->baseUrl());
    }

    /** tblhosting and its account's currency, for importServices. */
    private function hosting(): void
    {
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, server INT, regdate TEXT, domain TEXT,
            qty INT, amount REAL, billingcycle TEXT, nextduedate TEXT, domainstatus TEXT, username TEXT, password TEXT, notes TEXT, suspendreason TEXT,
            dedicatedip TEXT, assignedips TEXT, overideautosuspend INT, overidesuspenduntil TEXT)');
        $this->whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");
    }

    private function importServices(): string
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        (new ReflectionMethod($command, 'importServices'))->invoke($command);

        return $output->fetch();
    }

    /**
     * The myip rehearsal: 13 live services billed on a cycle their product does not price (WHMCS bills the service's amount)
     * came with no plan, and the cron would not bill them («set its plan»). A plan of that cycle without prices: never sold,
     * renewed at the service's own price.
     */
    public function test_a_service_on_a_cycle_its_product_does_not_offer_renews_at_its_own_price(): void
    {
        $this->hosting();
        $product = $this->createProduct(); // a Monthly plan only
        $client = User::factory()->create()->client();
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$client->id}, 1)");
        foreach ([[901, 'Quarterly', 'Active', 62.9], [902, 'Quarterly', 'Active', 70.0], [903, 'One Time', 'Active', 50.0]] as [$id, $cycle, $status, $amount]) {
            $this->whmcs->prepare("INSERT INTO tblhosting VALUES (?, ?, NULL, ?, 0, '2024-01-01', 'site.example.test', 1, ?, ?, '2026-12-01', ?, '',
                '', '', '', '', '', 0, '0000-00-00')")->execute([$id, $client->id, $product->product->id, $amount, $cycle, $status]);
        }

        $output = $this->importServices();

        $quarterly = Plan::query()->where('priceable_id', $product->product->id)->where('billing_period', 3)->where('billing_unit', 'month')->sole();
        $this->assertSame('Quarterly', $quarterly->name);
        $this->assertSame(0, Price::query()->where('plan_id', $quarterly->id)->count(), 'never sold');
        $this->assertSame([901 => $quarterly->id, 902 => $quarterly->id, 903 => null], Service::query()->orderBy('id')->pluck('plan_id', 'id')->all());
        $this->assertStringContainsString('2 live service(s) billed on a cycle their product does not offer', $output);

        // The cron bills its next quarter at its own price.
        Carbon::setTestNow('2026-11-25 09:00:00');
        $billed = DB::transaction(fn () => (new RenewalBilling)->billRenewal(901, now()->addDays(7)));
        $this->assertSame(1, $billed);
        $line = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', 901)->sole();
        $this->assertSame('62.90', number_format((float) $line->price, 2, '.', ''));
        $this->assertSame(['2026-12-01', '2027-03-01'], [$line->period_start?->toDateString(), $line->period_end?->toDateString()]);
        Carbon::setTestNow();
    }

    public function test_a_service_on_another_node_comes_on_the_clusters_server(): void
    {
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, server INT, regdate TEXT, domain TEXT,
            qty INT, amount REAL, billingcycle TEXT, nextduedate TEXT, domainstatus TEXT, username TEXT, password TEXT, notes TEXT, suspendreason TEXT,
            dedicatedip TEXT, assignedips TEXT, overideautosuspend INT, overidesuspenduntil TEXT)');
        $this->whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");
        $product = $this->createProduct();
        $client = User::factory()->create()->client();
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$client->id}, 1)");
        foreach ([[801, 61], [802, 57], [803, 59]] as [$id, $server]) { // Pegasus, Hetzner, a server WHMCS deleted
            $this->whmcs->prepare("INSERT INTO tblhosting VALUES (?, ?, NULL, ?, ?, '2024-01-01', 'vm.example.test', 1, 10, 'Monthly', '2026-12-01', 'Active', '',
                '', '', '', '', '', 0, '0000-00-00')")->execute([$id, $client->id, $product->product->id, $server]);
        }

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        (new ReflectionMethod($command, 'importServers'))->invoke($command);
        (new ReflectionMethod($command, 'importServices'))->invoke($command);

        $proxmox = Server::query()->where('extension', 'Proxmox')->sole();
        $hetzner = Server::query()->where('whmcs_id', 57)->sole();
        $this->assertSame([801 => $proxmox->id, 802 => $hetzner->id, 803 => null], Service::query()->orderBy('id')->pluck('server_id', 'id')->all());
        $this->assertStringContainsString('1 live service(s) whose WHMCS server is not here', $output->fetch(), 'only the deleted one');
    }
}
