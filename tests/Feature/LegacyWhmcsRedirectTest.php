<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * The cut-over's 301 map (LegacyWhmcsController): WHMCS's addresses, with /clients (myip) and without (nixpal), to ours.
 * Fixtures, no real data.
 */
class LegacyWhmcsRedirectTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'EUR']);
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE tblcurrencies (id INTEGER, code TEXT, prefix TEXT, suffix TEXT, format INTEGER, "default" INTEGER)',
            'CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT, credit REAL DEFAULT 0)',
            'CREATE TABLE tblproductgroups (id INTEGER, name TEXT, slug TEXT, tagline TEXT, "order" INTEGER, hidden INTEGER)',
            'CREATE TABLE tblproducts (id INTEGER, gid INTEGER, name TEXT, slug TEXT, description TEXT, hidden INTEGER, retired INTEGER, tax INTEGER, paytype TEXT,
                autosetup TEXT, proratabilling INTEGER, proratadate INTEGER, proratachargenextmonth INTEGER, stockcontrol INTEGER, qty INTEGER, allowqty INTEGER,
                servertype TEXT, servergroup INTEGER, configoption1 TEXT, configoption2 TEXT)',
            'CREATE TABLE tblpricing (id INTEGER PRIMARY KEY, type TEXT, currency INTEGER, relid INTEGER, msetupfee REAL, qsetupfee REAL, ssetupfee REAL, asetupfee REAL,
                bsetupfee REAL, tsetupfee REAL, monthly REAL, quarterly REAL, semiannually REAL, annually REAL, biennially REAL, triennially REAL)',
            'CREATE TABLE tblproductconfigoptions (id INTEGER, gid INTEGER, optionname TEXT, optiontype INTEGER, "order" INTEGER, hidden INTEGER)',
            'CREATE TABLE tblproductconfigoptionssub (id INTEGER, configid INTEGER, optionname TEXT, sortorder INTEGER)',
            'CREATE TABLE tblproductconfiglinks (id INTEGER, gid INTEGER, pid INTEGER)',
            'CREATE TABLE tblproduct_upgrade_products (product_id INTEGER, upgrade_product_id INTEGER)',
            'CREATE TABLE tblservers (id INTEGER, name TEXT, type TEXT, hostname TEXT, ipaddress TEXT, port INTEGER, username TEXT, maxaccounts INTEGER, active INTEGER, disabled INTEGER)',
            'CREATE TABLE tblservergroups (id INTEGER, name TEXT, filltype INTEGER)',
            'CREATE TABLE tblservergroupsrel (groupid INTEGER, serverid INTEGER)',
            'CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, server INT, regdate TEXT, domain TEXT, qty INT, amount REAL,
                billingcycle TEXT, nextduedate TEXT, domainstatus TEXT, username TEXT, password TEXT, notes TEXT, suspendreason TEXT, dedicatedip TEXT,
                assignedips TEXT, overideautosuspend INT, overidesuspenduntil TEXT)',
            // A small company's catalog (fixtures, no real data). Group 8 hidden; product 23 retired; product 24 of a group not there.
            "INSERT INTO tblcurrencies VALUES (1, 'EUR', '€', '', 3, 1)",
            "INSERT INTO tblproductgroups VALUES (3, 'Hosting', 'hosting', 'Web hosting', 2, 0), (8, 'Partners', 'partners', NULL, 5, 1)",
            "INSERT INTO tblproducts VALUES
                (21, 3, 'Hosting S', 'hosting-s', '', 0, 0, 1, 'recurring', 'payment', 0, 0, 0, 0, 0, 0, 'cpanel', 5, 'nix_small', ''),
                (22, 8, 'Partner Site', 'partner-site', '', 0, 0, 1, 'recurring', 'payment', 0, 0, 0, 0, 0, 0, 'ngm', 0, 'wp-basic', '8.3'),
                (23, 3, 'Hosting Old', 'hosting-old', '', 0, 1, 1, 'recurring', 'payment', 0, 0, 0, 0, 0, 0, 'cpanel', 5, 'nix_old', ''),
                (24, 99, 'Lost', 'lost', '', 0, 0, 1, 'recurring', 'payment', 0, 0, 0, 0, 0, 0, '', 0, '', '')",
            // Hosting S: monthly 5, annually 0.00 (offered, free). Partner Site monthly 3. Hosting Old monthly 4.
            "INSERT INTO tblpricing (type, currency, relid, msetupfee, qsetupfee, ssetupfee, asetupfee, bsetupfee, tsetupfee, monthly, quarterly, semiannually, annually, biennially, triennially)
                VALUES ('product', 1, 21, 0, 0, 0, 0, 0, 0, 5, -1, -1, 0, -1, -1), ('product', 1, 22, 0, 0, 0, 0, 0, 0, 3, -1, -1, -1, -1, -1),
                ('product', 1, 23, 0, 0, 0, 0, 0, 0, 4, -1, -1, -1, -1, -1),
                ('configoptions', 1, 51, 0, 0, 0, 0, 0, 0, 0, -1, -1, -1, -1, -1), ('configoptions', 1, 61, 0, 0, 0, 0, 0, 0, 2, -1, -1, -1, -1, -1)",
            // Two option groups: 1 (Backups) on no product, 2 (Extra IP) on Hosting S. Here the options' ids are new ones.
            "INSERT INTO tblproductconfigoptions VALUES (50, 1, 'backups|Backups', 1, 0, 0), (60, 2, 'ip|Extra IP', 1, 0, 0)",
            "INSERT INTO tblproductconfigoptionssub VALUES (51, 50, 'daily|Daily', 0), (61, 60, 'one|One IP', 0)",
            'INSERT INTO tblproductconfiglinks VALUES (1, 2, 21)',
            'INSERT INTO tblproduct_upgrade_products VALUES (21, 23)',
            "INSERT INTO tblservers VALUES (2, 'cp1', 'cpanel', 'cp1.example.test', '192.0.2.10', 0, 'root', 0, 1, 0)",
            "INSERT INTO tblservergroups VALUES (5, 'Shared', 1)",
            'INSERT INTO tblservergroupsrel VALUES (5, 2)',
            // Its store addresses: the current one, and an old slug of a renamed product.
            'CREATE TABLE tblproducts_slugs (id INTEGER PRIMARY KEY, product_id INT, group_id INT, group_slug TEXT, slug TEXT, active INT, clicks INT)',
            "INSERT INTO tblproducts_slugs VALUES (1, 21, 3, 'hosting', 'hosting-s', 1, 900), (2, 21, 3, 'hosting', 'small-hosting', 0, 40), (3, 999, 3, 'hosting', 'gone', 1, 1)",
        ] as $sql) {
            $this->whmcs->exec($sql);
        }
    }

    /** The full importer's steps, in its order: servers, catalog, services. */
    private function import(string ...$steps): string
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $this->output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));
        foreach ($steps ?: ['importServers', 'importCatalog'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }

        return $this->output->fetch();
    }

    private function assertGoes(string $from, string $to): void
    {
        $this->get($from)->assertStatus(301)->assertRedirect($to);
    }

    public function test_the_store_by_its_slugs_old_ones_too_and_with_or_without_clients(): void
    {
        $this->import('importServers', 'importCatalog');
        $product = route('products.show', ['hosting', 'hosting-s']);

        $this->assertSame(2, DB::table('whmcs_product_slugs')->count(), 'a product not here is left out');
        foreach (['/clients/store/hosting/hosting-s', '/store/hosting/hosting-s', '/clients/store/hosting/small-hosting', '/clients/store/Hosting/HOSTING-S'] as $from) {
            $this->assertGoes($from, $product);
        }
        $this->assertGoes('/clients/store/hosting', route('category.show', 'hosting'));
        $this->assertGoes('/store/hosting/nothing-here', route('category.show', 'hosting'));
        $this->assertGoes('/clients/store', route('products.all'));
        $this->assertGoes('/clients/index.php?rp=/store/hosting/hosting-s', $product);
        $this->assertGoes('/index.php?rp=/store/hosting', route('category.show', 'hosting'));
    }

    public function test_the_carts_add_links(): void
    {
        $this->import('importServers', 'importCatalog');

        $this->assertGoes('/clients/cart.php?a=add&pid=21&billingcycle=annually', route('products.show', ['hosting', 'hosting-s']));
        $this->assertGoes('/cart.php?gid=3', route('category.show', 'hosting'));
        $this->assertGoes('/clients/cart.php?a=add&domain=register&query=example.gr', route('domain.search', ['domain' => 'example.gr']));
        $this->assertGoes('/clients/cart.php?a=add&domain=transfer', route('domain.transfer'));
        $this->assertGoes('/clients/cart.php?a=view', route('cart'));
        $this->assertGoes('/clients/cart.php?pid[]=21', route('products.all'));
    }

    public function test_the_client_areas_pages_by_whmcss_kept_ids(): void
    {
        $client = User::factory()->create()->client();
        $service = Service::factory()->create(['client_id' => $client->id]);
        $invoice = Invoice::factory()->create(['client_id' => $client->id]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        $this->assertGoes("/clients/clientarea.php?action=productdetails&id={$service->id}", route('services.show', $service->id));
        $this->assertGoes('/clients/clientarea.php?action=productdetails&id=999999', route('services'));
        $this->assertGoes('/clients/clientarea.php?action=invoices', route('invoices'));
        $this->assertGoes('/clientarea.php?action=details', route('account'));
        $this->assertGoes('/clients/clientarea.php', route('dashboard'));
        $this->assertGoes("/clients/viewinvoice.php?id={$invoice->id}", route('invoices.show', $invoice->id));
        $this->assertGoes("/viewticket.php?tid={$ticket->reference}&c=abc", route('tickets.show', $ticket->id));
        $this->assertGoes('/clients/viewticket.php?tid=nope', route('tickets'));
        $this->assertGoes('/clients/submitticket.php?step=2&deptid=4', route('tickets.create'));
        $this->assertGoes('/clients/index.php?rp=/login', route('login'));
        $this->assertGoes('/clients/pwreset.php', route('password.request'));
    }

    public function test_the_knowledgebase_keeps_its_own_and_anything_else_goes_home(): void
    {
        $this->get('/clients/knowledgebase')->assertStatus(301)->assertRedirect(route('kb.index'));
        $this->assertGoes('/clients/index.php?rp=/knowledgebase', route('kb.index'));
        foreach (['/clients/serverstatus.php', '/clients/dl.php?id=3', '/clients/some/old/page', '/clients/'] as $from) {
            $this->assertGoes($from, route('home'));
        }
        $this->get('/')->assertOk(); // the home page itself, without rp
    }

    public function test_only_our_own_addresses(): void
    {
        foreach (['/clients/index.php?rp=//evil.example/x', '/clients/cart.php?a=add&domain=register&query=https://evil.example', '/?rp=https://evil.example'] as $from) {
            $location = (string) $this->get($from)->assertStatus(301)->headers->get('Location');
            $this->assertStringStartsWith(rtrim(config('app.url'), '/'), $location, $from);
        }
    }
}
