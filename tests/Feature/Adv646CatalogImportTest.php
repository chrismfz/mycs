<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Import\WhmcsParity;
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
 * Adversarial review of #646 (WG-2: the full import's catalog through CatalogImport). Same WHMCS fixture as
 * ImportFullCatalogTest (no real data): product 24 «Lost» sits in group #99, which tblproductgroups no longer has.
 */
class Adv646CatalogImportTest extends TestCase
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

    /** A live service on product 24 «Lost» (group #99 deleted in WHMCS, product and its pricing still there). */
    private function liveServiceOnLost(): int
    {
        $client = User::factory()->create()->client();
        $this->whmcs->exec("INSERT INTO tblclients (id, currency) VALUES ({$client->id}, 1)");
        $this->whmcs->exec("INSERT INTO tblpricing (type, currency, relid, msetupfee, qsetupfee, ssetupfee, asetupfee, bsetupfee, tsetupfee, monthly, quarterly, semiannually, annually, biennially, triennially)
            VALUES ('product', 1, 24, 0, 0, 0, 0, 0, 0, 7, -1, -1, -1, -1, -1)");
        $this->whmcs->exec("INSERT INTO tblhosting VALUES (901, {$client->id}, NULL, 24, 0, '2024-01-01', 'lost.example.test', 1, 7, 'Monthly', '2026-12-01',
            'Active', 'lost', '', '', '', '', '', 0, '0000-00-00')");
        // As the import runs (SET foreign_key_checks=0 in handle()): a service on a product not here is written all the same.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }

        return 901;
    }

    /**
     * ADV-646-1: WHMCS renews a service from tblhosting whatever its product's group: a product whose group was deleted
     * still bills its services every cycle. The old full importer brought every product (category_id dangling); now
     * CatalogImport skips it («its group is missing»), so its live service lands on a product that does not exist and on
     * no plan: never renewed nor invoiced here, while WHMCS bills it 7.00 a month.
     */
    public function test_adv_646_1_a_live_service_on_a_product_of_a_deleted_group_keeps_its_product_and_plan(): void
    {
        $id = $this->liveServiceOnLost();

        $this->import('importServers', 'importCatalog', 'importServices');

        $service = Service::query()->findOrFail($id);
        $this->assertNotNull(Product::query()->find(24), 'product #24 is billed by WHMCS (a live service) but was skipped: the service points at nothing');
        $this->assertNotNull($service->plan_id, 'the live service lands on no plan: it is never renewed here');
    }

    /**
     * ADV-646-2: the parity's «Products» row joins tblproductgroups on the WHMCS side, so a product the import skipped
     * (its group missing) is left out on both sides and the row reads «same» while a product WHMCS bills is not here.
     */
    public function test_adv_646_2_parity_products_is_not_same_when_a_whmcs_product_is_missing(): void
    {
        foreach (['CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, status TEXT, subtotal REAL, tax REAL, tax2 REAL, credit REAL, total REAL)',
            'CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INTEGER, type TEXT, relid INTEGER, amount REAL)',
            'CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, invoiceid INTEGER, amountin REAL, amountout REAL, fees REAL)',
            'CREATE TABLE tbltickets (id INTEGER PRIMARY KEY)', 'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY)'] as $sql) {
            $this->whmcs->exec($sql);
        }
        $this->liveServiceOnLost();
        $this->import('importServers', 'importCatalog', 'importServices');

        $row = collect((new WhmcsParity($this->whmcs))->rows())->firstWhere('metric', 'Products');
        $whmcsProducts = (int) $this->whmcs->query('SELECT COUNT(*) FROM tblproducts')->fetchColumn();
        $here = Product::query()->whereNotNull('whmcs_id')->count();

        $this->assertFalse($row['same'] && $here < $whmcsProducts,
            "«Products» reads same ({$row['whmcs']} = {$row['mycs']}) while WHMCS has {$whmcsProducts} products and {$here} are here");
    }
}
