<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ConfigOption;
use App\Models\Currency;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\Setting;
use App\Services\Whmcs\CatalogImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;
use Tests\TestCase;

/**
 * The catalog of a WHMCS installation, copied (app:import-whmcs-catalog): currencies, groups, products and prices,
 * config options, servers and groups without passwords, licensing products, TLDs and their prices; no customer data.
 * Re-runnable, never destructive, matched by WHMCS ids; a dry run writes nothing.
 */
class WhmcsCatalogImportTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE tblcurrencies (id INTEGER, code TEXT, prefix TEXT, suffix TEXT, format INTEGER, rate REAL, "default" INTEGER)',
            'CREATE TABLE tblproductgroups (id INTEGER, name TEXT, slug TEXT, tagline TEXT, "order" INTEGER)',
            'CREATE TABLE tblproducts (id INTEGER, gid INTEGER, name TEXT, slug TEXT, description TEXT, hidden INTEGER, tax INTEGER, paytype TEXT, autosetup TEXT,
                proratabilling INTEGER, proratadate INTEGER, proratachargenextmonth INTEGER, stockcontrol INTEGER, qty INTEGER, allowqty INTEGER, servertype TEXT,
                servergroup INTEGER, configoption1 TEXT, configoption2 TEXT, configoption3 TEXT, configoption5 TEXT, configoption8 TEXT)',
            'CREATE TABLE tblpricing (id INTEGER PRIMARY KEY, type TEXT, currency INTEGER, relid INTEGER, msetupfee REAL, qsetupfee REAL, ssetupfee REAL, asetupfee REAL,
                bsetupfee REAL, tsetupfee REAL, monthly REAL, quarterly REAL, semiannually REAL, annually REAL, biennially REAL, triennially REAL)',
            'CREATE TABLE tblproductconfigoptions (id INTEGER, gid INTEGER, optionname TEXT, optiontype INTEGER, "order" INTEGER, hidden INTEGER)',
            'CREATE TABLE tblproductconfigoptionssub (id INTEGER, configid INTEGER, optionname TEXT, sortorder INTEGER)',
            'CREATE TABLE tblproductconfiglinks (id INTEGER, gid INTEGER, pid INTEGER)',
            'CREATE TABLE tblproduct_upgrade_products (product_id INTEGER, upgrade_product_id INTEGER)',
            'CREATE TABLE tblservers (id INTEGER, name TEXT, type TEXT, hostname TEXT, ipaddress TEXT, username TEXT, password TEXT, accesshash TEXT, maxaccounts INTEGER, disabled INTEGER)',
            'CREATE TABLE tblservergroups (id INTEGER, name TEXT, filltype INTEGER)',
            'CREATE TABLE tblservergroupsrel (groupid INTEGER, serverid INTEGER)',
            'CREATE TABLE tbldomainpricing (id INTEGER, extension TEXT, dnsmanagement INTEGER, emailforwarding INTEGER, idprotection INTEGER, eppcode INTEGER, autoreg TEXT,
                "order" INTEGER, grace_period INTEGER, grace_period_fee REAL, redemption_grace_period INTEGER, redemption_grace_period_fee REAL)',
            'CREATE TABLE tblcustomfields (id INTEGER, type TEXT, relid INTEGER, fieldname TEXT, description TEXT, sortorder INTEGER)',
            // The WHMCS catalog of a small company (fixtures, no real data).
            "INSERT INTO tblcurrencies VALUES (1, 'EUR', '€', ' EUR', 3, 1, 1), (2, 'USD', '$', ' USD', 2, 1.18, 0)",
            "INSERT INTO tblproductgroups VALUES (3, 'Hosting', 'hosting', 'Web hosting', 1), (7, 'Licenses', 'licenses', NULL, 2)",
            "INSERT INTO tblproducts VALUES (11, 3, 'Hosting S', 'hosting-s', 'Small', 0, 1, 'recurring', 'payment', 0, 0, 0, 0, 0, 0, 'cpanel', 5, 'pkg_small', '', '', '', ''),
                (12, 3, 'Hosting M', 'hosting-m', 'Medium', 0, 1, 'recurring', 'payment', 0, 0, 0, 0, 0, 0, 'cpanel', 5, 'pkg_medium', '', '', '', ''),
                (21, 7, 'Web Controller', '', 'Licence', 0, 1, 'free', 'payment', 0, 0, 0, 0, 0, 0, 'licensing', 0, '16', 'ZCWeb-', 'on', '', 'Fixture-Psk-1')",
            // Hosting S: monthly 5, annually 50, quarterly -1 (not offered), semiannually 0.00 (offered free) in EUR; monthly 6 in USD.
            "INSERT INTO tblpricing (type, currency, relid, msetupfee, qsetupfee, ssetupfee, asetupfee, bsetupfee, tsetupfee, monthly, quarterly, semiannually, annually, biennially, triennially)
                VALUES ('product', 1, 11, 2, 0, 0, 0, 0, 0, 5, -1, 0, 50, -1, -1), ('product', 2, 11, 0, 0, 0, 0, 0, 0, 6, -1, -1, -1, -1, -1),
                ('product', 1, 12, 0, 0, 0, 0, 0, 0, 9, -1, -1, 90, -1, -1),
                ('configoptions', 1, 41, 0, 0, 0, 0, 0, 0, 1.5, -1, -1, 15, -1, -1),
                ('domainregister', 1, 31, 16.5, 30, -1, -1, -1, 0, -1, -1, -1, -1, -1, -1), ('domainrenew', 1, 31, 16.5, 33, -1, -1, -1, 0, -1, -1, -1, -1, -1, -1),
                ('domaintransfer', 1, 31, 16.5, -1, -1, -1, -1, 0, -1, -1, -1, -1, -1, -1),
                ('domainregister', 1, 32, -1, 19, -1, 38, -1, 0, -1, -1, -1, -1, -1, -1), ('domaintransfer', 1, 32, 0, -1, -1, -1, -1, 0, -1, -1, -1, -1, -1, -1)",
            "INSERT INTO tblproductconfigoptions VALUES (40, 9, 'ips|Extra IPs', 1, 0, 0)",
            "INSERT INTO tblproductconfigoptionssub VALUES (41, 40, 'one|One IP', 1), (42, 40, 'none|None', 0)",
            'INSERT INTO tblproductconfiglinks VALUES (1, 9, 11)',
            'INSERT INTO tblproduct_upgrade_products VALUES (11, 12)',
            "INSERT INTO tblservers VALUES (2, 'cp1', 'cpanel', 'cp1.example.test', '192.0.2.10', 'root', 'Encrypted-Pass', 'Encrypted-Hash', 200, 0),
                (3, 'old', 'plesk', 'old.example.test', '', 'admin', '', '', 0, 1)",
            "INSERT INTO tblservergroups VALUES (5, 'Shared', 1)",
            'INSERT INTO tblservergroupsrel VALUES (5, 2)',
            "INSERT INTO tbldomainpricing VALUES (31, '.com', 1, 1, 1, 1, 'openprovider', 1, 40, 0, 30, 100.30), (32, '.gr', 0, 0, 0, 1, 'grepp', 2, -1, 0, -1, 0)",
        ] as $sql) {
            $this->whmcs->exec($sql);
        }
    }

    private function import(bool $dryRun = false, ?int $connection = null): array
    {
        return (new CatalogImport($this->whmcs, true, $connection))->run($dryRun);
    }

    public function test_the_catalog_lands_without_customer_data_or_secrets(): void
    {
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $result = $this->import(connection: $op->id);

        $this->assertSame('EUR', Setting::query()->where('key', 'default_currency')->whereNull('settingable_type')->value('value'));
        $this->assertTrue(Currency::query()->whereKey('USD')->exists(), 'nothing deleted');
        $hosting = Product::query()->where('whmcs_id', 11)->sole();
        $this->assertSame(['Hosting S', Category::query()->where('whmcs_id', 3)->value('id'), ServerGroup::query()->where('whmcs_id', 5)->value('id')],
            [$hosting->name, $hosting->category_id, $hosting->server_group_id]);
        $plans = Plan::query()->where('priceable_type', $hosting->getMorphClass())->where('priceable_id', $hosting->id)->pluck('name')->sort()->values()->all();
        $this->assertSame(['Annually', 'Monthly', 'Semiannually'], $plans, 'quarterly -1 is not offered; semiannually 0.00 is (free)');
        $monthly = Plan::query()->where('priceable_id', $hosting->id)->where('priceable_type', $hosting->getMorphClass())->where('name', 'Monthly')->sole();
        $this->assertSame(['EUR' => '5.00', 'USD' => '6.00'], Price::query()->where('plan_id', $monthly->id)->orderBy('currency_code')->pluck('price', 'currency_code')->map(fn ($p) => number_format((float) $p, 2, '.', ''))->all());
        $this->assertSame('pkg_small', Setting::query()->where(['settingable_type' => $hosting->getMorphClass(), 'settingable_id' => $hosting->id, 'key' => 'package'])->value('value'));
        $this->assertSame([Product::query()->where('whmcs_id', 12)->value('id')], $hosting->upgrades()->pluck('products.id')->all());
        $this->assertSame([ConfigOption::query()->where('whmcs_ref', 'option:40')->value('id')], DB::table('config_option_products')->where('product_id', $hosting->id)->pluck('config_option_id')->all(),
            'linked to the option it brought, not to a WHMCS id');

        $server = Server::query()->where('whmcs_id', 2)->sole();
        $this->assertSame(['CPanel', 200], [$server->extension, $server->max_accounts]);
        $this->assertSame(['host' => 'https://cp1.example.test:2087', 'username' => 'root'], $server->settings()->pluck('value', 'key')->sort()->all());
        $this->assertStringNotContainsString('Encrypted-', DB::table('settings')->pluck('value')->implode(' '), 'no password, no token');
        $this->assertNull(Server::query()->where('whmcs_id', 3)->first(), 'no module for it: skipped and said');
        $this->assertContains($server->id, ServerGroup::query()->where('whmcs_id', 5)->sole()->servers()->pluck('extensions.id')->all());

        $licence = Product::query()->where('whmcs_id', 21)->sole();
        $this->assertSame('ZCWeb-', Setting::query()->where(['settingable_type' => $licence->getMorphClass(), 'settingable_id' => $licence->id, 'key' => 'key_prefix'])->value('value'));
        $this->assertTrue((bool) Setting::query()->where(['settingable_type' => $licence->getMorphClass(), 'settingable_id' => $licence->id, 'key' => 'secret_key'])->value('encrypted'));

        $com = DomainTld::query()->where('tld', 'com')->sole()->load('prices');
        $this->assertSame([$op->id, 40, 30], [$com->registrar_connection_id, $com->grace_days, $com->redemption_days]);
        $this->assertSame(['16.50', '33.00', '16.50', '100.30'], [$com->priceFor('register', 1, 'EUR'), $com->priceFor('renew', 2, 'EUR'), $com->priceFor('transfer', 1, 'EUR'), $com->priceFor('redemption', 1, 'EUR')]);
        $gr = DomainTld::query()->where('tld', 'gr')->sole()->load('prices');
        $this->assertSame([null, null, null, '19.00', '38.00', '0.00'], [$gr->registrar_connection_id, $gr->grace_days, $gr->priceFor('register', 1, 'EUR'),
            $gr->priceFor('register', 2, 'EUR'), $gr->priceFor('register', 4, 'EUR'), $gr->priceFor('transfer', 1, 'EUR')]);
        $this->assertNotEmpty(array_filter($result['notes'], fn ($n) => str_contains($n, 'grepp')));
    }

    public function test_a_rerun_updates_what_it_brought_and_touches_nothing_else(): void
    {
        $existing = Product::factory()->create(['name' => 'Zonecloud Agent (test)']);
        $this->import();
        $counts = [Product::query()->count(), Plan::query()->count(), Price::query()->count(), ConfigOption::query()->count(), Server::query()->count()];
        $this->whmcs->exec("UPDATE tblproducts SET name = 'Hosting Small' WHERE id = 11");
        $this->whmcs->exec("UPDATE tblpricing SET monthly = 5.5 WHERE type = 'product' AND relid = 11 AND currency = 1");

        $this->import();

        $this->assertSame($counts, [Product::query()->count(), Plan::query()->count(), Price::query()->count(), ConfigOption::query()->count(), Server::query()->count()], 'nothing added twice');
        $hosting = Product::query()->where('whmcs_id', 11)->sole();
        $this->assertSame('Hosting Small', $hosting->name);
        $monthly = Plan::query()->where('priceable_id', $hosting->id)->where('priceable_type', $hosting->getMorphClass())->where('name', 'Monthly')->sole();
        $this->assertSame('5.50', number_format((float) Price::query()->where(['plan_id' => $monthly->id, 'currency_code' => 'EUR'])->value('price'), 2, '.', ''));
        $this->assertSame('Zonecloud Agent (test)', $existing->fresh()->name, 'what was here before is untouched');
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $before = [Product::query()->count(), Currency::query()->count(), DomainTld::query()->count(), Setting::query()->count()];

        $result = $this->import(dryRun: true);

        $this->assertSame(3, $result['counts']['products']);
        $this->assertSame($before, [Product::query()->count(), Currency::query()->count(), DomainTld::query()->count(), Setting::query()->count()]);
    }

    /** Code review 1: a cPanel product without a server group gets WHMCS's default cPanel server, so it has a module. */
    public function test_a_hosting_product_without_a_group_gets_the_default_server_of_its_module(): void
    {
        $this->whmcs->exec('UPDATE tblproducts SET servergroup = 0 WHERE id = 12');

        $this->import();

        $server = Server::query()->where('whmcs_id', 2)->sole();
        $this->assertSame([$server->id, $server->id], [Product::query()->where('whmcs_id', 11)->value('server_id'), Product::query()->where('whmcs_id', 12)->value('server_id')]);
        $this->assertNull(Product::query()->where('whmcs_id', 21)->value('server_group_id'));
    }

    /** Code review 2: a client group's domain price (its id in tsetupfee) never overwrites the default one. */
    public function test_a_client_group_domain_price_is_not_the_price(): void
    {
        $this->whmcs->exec("INSERT INTO tblpricing (type, currency, relid, msetupfee, qsetupfee, ssetupfee, asetupfee, bsetupfee, tsetupfee, monthly, quarterly, semiannually, annually, biennially, triennially)
            VALUES ('domainregister', 1, 31, 9.99, -1, -1, -1, -1, 4, -1, -1, -1, -1, -1, -1)");

        $this->import();

        $this->assertSame('16.50', DomainTld::query()->where('tld', 'com')->sole()->load('prices')->priceFor('register', 1, 'EUR'));
    }

    /** Code reviews 3, 8: a server's host is a URL the extension can call, and staff's correction stays; a fee of 0.00 is free and offered. */
    public function test_the_server_host_is_a_url_staff_may_correct_and_a_zero_fee_is_offered(): void
    {
        $this->import();
        $server = Server::query()->where('whmcs_id', 2)->sole();
        Setting::query()->where(['settingable_type' => $server->getMorphClass(), 'settingable_id' => $server->id, 'key' => 'host'])->update(['value' => 'https://cp1.example.test:2083']);

        $this->import();

        $this->assertSame('https://cp1.example.test:2083', Setting::query()->where(['settingable_type' => $server->getMorphClass(), 'settingable_id' => $server->id, 'key' => 'host'])->value('value'));
        $this->assertSame('0.00', DomainTld::query()->where('tld', 'com')->sole()->load('prices')->priceFor('grace', 1, 'EUR'));
    }

    /** Code review 6: an imported server staff deleted stays deleted, and the re-run does not fail on its WHMCS id. */
    public function test_a_server_deleted_here_does_not_break_a_rerun(): void
    {
        $this->import();
        Product::query()->whereNotNull('server_id')->update(['server_id' => null]); // staff move its products off it first
        Server::query()->where('whmcs_id', 2)->sole()->delete();

        $result = $this->import();

        $this->assertSame(0, Server::query()->where('whmcs_id', 2)->count());
        $this->assertNotEmpty(array_filter($result['notes'], fn ($n) => str_contains($n, 'left deleted')));
        $this->assertNull(Product::query()->where('whmcs_id', 11)->value('server_id'), 'not its deleted server');
    }

    /** Code review 12: a registrar connection that does not exist is an error, not «no connection». */
    public function test_an_unknown_registrar_connection_fails_the_import(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->import(connection: 999);
    }

    /** Code review 14: a WHMCS group order past a tinyint is clamped, not a failed import; a TLD price withdrawn keeps its registrar cost. */
    public function test_a_large_group_order_and_a_withdrawn_tld_price_keeps_its_cost(): void
    {
        $this->whmcs->exec('UPDATE tblproductgroups SET "order" = 900 WHERE id = 3');
        $this->import();
        $com = DomainTld::query()->where('tld', 'com')->sole();
        DomainTldPrice::query()->where(['domain_tld_id' => $com->id, 'operation' => 'register', 'years' => 2])->update(['cost' => '12.00', 'cost_currency' => 'USD']);
        $this->whmcs->exec("UPDATE tblpricing SET qsetupfee = -1 WHERE type = 'domainregister' AND relid = 31");

        $this->import();

        $this->assertSame(255, (int) Category::query()->where('whmcs_id', 3)->value('sort'));
        $row = DomainTldPrice::query()->where(['domain_tld_id' => $com->id, 'operation' => 'register', 'years' => 2])->sole();
        $this->assertSame([null, '12.00'], [$row->price, number_format((float) $row->cost, 2, '.', '')]);
    }

    /** A hidden WHMCS group is off the menu, still opened by its link; a visible one is in it (docs/admin-ux.md). */
    public function test_a_hidden_group_is_imported_off_the_menu(): void
    {
        $this->whmcs->exec('ALTER TABLE tblproductgroups ADD COLUMN hidden INTEGER DEFAULT 0');
        $this->whmcs->exec('UPDATE tblproductgroups SET hidden = 1 WHERE id = 7');

        $this->import();

        $this->assertTrue(Category::query()->where('whmcs_id', 3)->value('show_in_menu'));
        $this->assertFalse(Category::query()->where('whmcs_id', 7)->value('show_in_menu'));
    }
}
