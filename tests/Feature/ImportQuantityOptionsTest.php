<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\ConfigOption;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * WG-15: WHMCS's «Quantity» options no service ever took are not brought (myip: 133 of 134, SSL SANs and servers and VPS
 * sizes the modules made); one a service took comes hidden, as MyCS does not charge a quantity yet (CART-9). Fake data only.
 */
class ImportQuantityOptionsTest extends TestCase
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
            // WG-15: on group 2 (Hosting S) a dropdown, a quantity a service took and one nobody ever took.
            "INSERT INTO tblproductconfigoptions VALUES (50, 1, 'backups|Backups', 1, 0, 0), (60, 2, 'ip|Extra IP', 1, 0, 0),
                (70, 2, 'Extra Addon domain', 4, 0, 0), (80, 2, 'Additional SAN | Additional domains', 4, 0, 0)",
            "INSERT INTO tblproductconfigoptionssub VALUES (51, 50, 'daily|Daily', 0), (61, 60, 'one|One IP', 0), (71, 70, 'Extra Addon domain', 0),
                (81, 80, 'Additional domains', 0)",
            'CREATE TABLE tblhostingconfigoptions (id INTEGER PRIMARY KEY, relid INT, configid INT, optionid INT, qty INT)',
            'INSERT INTO tblhostingconfigoptions (relid, configid, optionid, qty) VALUES (1, 70, 71, 2), (2, 80, 81, 0), (3, 70, 71, 0)',
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

    public function test_only_the_quantity_options_a_service_took_come_and_hidden(): void
    {
        $out = $this->import();

        $option = fn (int $id) => ConfigOption::query()->where('whmcs_ref', "option:{$id}")->first();
        $this->assertNotNull($option(60), 'a dropdown: as before');
        $this->assertFalse((bool) $option(60)->hidden);
        $this->assertNull($option(80), 'a quantity nobody took: not brought');
        $this->assertNotNull($option(70), 'a quantity a service took: brought');
        $this->assertTrue((bool) $option(70)->hidden, 'hidden: MyCS does not charge a quantity yet');
        $this->assertStringContainsString('1 «Quantity» option(s) no service ever took in WHMCS: not brought (WG-15).', $out);
        $this->assertStringContainsString('Quantity option #70 «Extra Addon domain»: brought hidden', $out);
    }
}
