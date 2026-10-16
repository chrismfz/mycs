<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ConfigOption;
use App\Models\Currency;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Server;
use App\Models\Setting;
use App\Services\Licensing\WhmcsLicensingImport;
use App\Services\Whmcs\CatalogImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * Adversarial review of app:import-whmcs-catalog (PR #162). Same fixture as WhmcsCatalogImportTest; each test is one
 * attack. A failing test is a finding.
 */
class WhmcsCatalogImportAdversarialTest extends TestCase
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

    /** @return array<string, array<string, string>> plan name => [currency => price] of an imported product */
    private function pricesOf(int $whmcsId): array
    {
        $product = Product::query()->where('whmcs_id', $whmcsId)->sole();
        $out = [];
        foreach (Plan::query()->where('priceable_type', $product->getMorphClass())->where('priceable_id', $product->id)->orderBy('id')->get() as $plan) {
            $out[$plan->name] = Price::query()->where('plan_id', $plan->id)->orderBy('currency_code')->pluck('price', 'currency_code')
                ->map(fn ($p) => number_format((float) $p, 2, '.', ''))->all();
        }

        return $out;
    }

    // ---- findings ----------------------------------------------------------------------------------------------

    /** F1: a WHMCS product retired (tblproducts.retired = 1, not hidden) becomes orderable here at its old prices. */
    public function test_f1_a_retired_whmcs_product_is_not_sellable(): void
    {
        $this->whmcs->exec('ALTER TABLE tblproducts ADD COLUMN retired INTEGER DEFAULT 0');
        $this->whmcs->exec('UPDATE tblproducts SET retired = 1 WHERE id = 12');

        $this->import();

        $old = Product::query()->where('whmcs_id', 12)->first();
        $this->assertTrue($old === null || $old->hidden, 'a retired product must not be offered for order');
    }

    /** F2: WHMCS stops offering a cycle (price set to -1) — the re-run keeps selling it here at the old price. */
    public function test_f2_a_cycle_withdrawn_in_whmcs_is_not_still_priced_after_a_rerun(): void
    {
        $this->import();
        $this->assertSame('50.00', $this->pricesOf(11)['Annually']['EUR']);
        $this->whmcs->exec("UPDATE tblpricing SET annually = -1 WHERE type = 'product' AND relid = 11 AND currency = 1");

        $this->import();

        $this->assertArrayNotHasKey('EUR', $this->pricesOf(11)['Annually'] ?? [], 'annually is -1 in WHMCS: not offered');
    }

    /** F3: the product becomes free in WHMCS; the re-run adds a Free plan and keeps the paid ones. */
    public function test_f3_paytype_changed_to_free_does_not_keep_the_paid_plans(): void
    {
        $this->import();
        $this->whmcs->exec("UPDATE tblproducts SET paytype = 'free' WHERE id = 12");

        $this->import();

        $this->assertSame(['Free'], array_keys($this->pricesOf(12)));
    }

    /** F4: plans are matched by name: staff rename «Monthly» and the re-run adds a second monthly plan. */
    public function test_f4_a_renamed_plan_is_not_duplicated_on_rerun(): void
    {
        $this->import();
        $product = Product::query()->where('whmcs_id', 11)->sole();
        Plan::query()->where('priceable_type', $product->getMorphClass())->where('priceable_id', $product->id)->where('name', 'Monthly')->update(['name' => 'Μηνιαία']);

        $this->import();

        $monthly = Plan::query()->where('priceable_type', $product->getMorphClass())->where('priceable_id', $product->id)
            ->where('type', 'recurring')->where('billing_period', 1)->where('billing_unit', 'month')->count();
        $this->assertSame(1, $monthly, 'one monthly plan, not two');
    }

    /** F5: a TLD that was already sold here (by hand, before the import) has its prices and settings overwritten. */
    public function test_f5_a_pre_existing_live_tld_keeps_its_prices_and_settings(): void
    {
        Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'prefix' => null, 'suffix' => ' €', 'format' => '1.000,00']);
        $com = DomainTld::query()->create(['tld' => 'com', 'epp_code_required' => false, 'grace_days' => 10, 'is_active' => true, 'sort' => 99]);
        DomainTldPrice::query()->create(['domain_tld_id' => $com->id, 'operation' => 'register', 'years' => 1, 'currency_code' => 'EUR', 'price' => '9.99']);

        $this->import();

        $com->refresh()->load('prices');
        $this->assertSame(['9.99', false, 10, 99], [$com->priceFor('register', 1, 'EUR'), $com->epp_code_required, $com->grace_days, (int) $com->sort],
            'what was here before stays untouched (docs/importer.md «Κατάλογος»)');
    }

    /** F6: an existing currency's display (prefix/suffix/format) is overwritten by WHMCS's. */
    public function test_f6_a_pre_existing_currency_keeps_its_display(): void
    {
        Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'prefix' => null, 'suffix' => ' €', 'format' => '1 000,00']);

        $this->import();

        $eur = Currency::query()->find('EUR');
        $this->assertSame([null, ' €', '1 000,00'], [$eur->prefix, $eur->suffix, $eur->format]);
    }

    /** F7: a dry run flushes the whole application cache (Currency::saved → Cache::flush): settings, locks, rate limits. */
    public function test_f7_a_dry_run_leaves_the_cache_alone(): void
    {
        Cache::put('review-canary', 'still-here', 600);
        $lock = Cache::lock('service-create:999', 600);
        $this->assertTrue($lock->get());

        $this->import(dryRun: true);

        $this->assertSame('still-here', Cache::get('review-canary'), 'dry run wrote to (flushed) the cache');
        $this->assertFalse(Cache::lock('service-create:999', 600)->get(), 'a held service-create lock was released by the import');
    }

    /** F8: the catalog lands with new ids; the licensing import (L3) still finds products by WHMCS id = MyCS id and takes over an unrelated live product. */
    public function test_f8_licensing_import_after_catalog_import_does_not_hijack_an_unrelated_product(): void
    {
        $unrelated = Product::factory()->create(['id' => 21, 'name' => 'Live product #21']);
        $this->import();
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER, packageid INTEGER, billingcycle TEXT)');
        $this->whmcs->exec('CREATE TABLE mod_licensing (id INTEGER, serviceid INTEGER)');

        (new WhmcsLicensingImport($this->whmcs))->run(0);

        $licensing = Server::query()->where('extension', 'NixpalLicensing')->value('id');
        $this->assertNotSame($licensing, $unrelated->fresh()->server_id, 'the live product #21 is not WHMCS product 21, yet it was moved to the licensing server');
        $this->assertSame(0, Setting::query()->where(['settingable_type' => $unrelated->getMorphClass(), 'settingable_id' => $unrelated->id, 'key' => 'secret_key'])->count(),
            'WHMCS product 21\'s PSK went to an unrelated live product');
    }

    /** F9: the re-run deletes config-option links (and upgrades) staff added to an imported product. */
    public function test_f9_a_rerun_does_not_delete_links_staff_added(): void
    {
        $this->import();
        $hosting = Product::query()->where('whmcs_id', 12)->sole();
        $s = Product::query()->where('whmcs_id', 11)->sole();
        $own = ConfigOption::query()->forceCreate(['name' => 'Backups (added here)', 'type' => 'select', 'sort' => 0, 'hidden' => false]);
        DB::table('config_option_products')->insert(['product_id' => $hosting->id, 'config_option_id' => $own->id]);
        $hosting->upgrades()->attach($s->id);

        $this->import();

        $this->assertTrue(DB::table('config_option_products')->where(['product_id' => $hosting->id, 'config_option_id' => $own->id])->exists(), 'config option link deleted');
        $this->assertContains($s->id, $hosting->upgrades()->pluck('products.id')->all(), 'upgrade staff added was removed');
    }

    /** F10: a server already configured here (same host) is added a second time, without its password, and the group points at the copy. */
    public function test_f10_an_existing_server_with_the_same_host_is_not_duplicated(): void
    {
        $live = Server::query()->forceCreate(['name' => 'cp1', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        Setting::query()->create(['settingable_type' => $live->getMorphClass(), 'settingable_id' => $live->id, 'key' => 'host', 'value' => 'cp1.example.test', 'type' => 'string']);

        $this->import();

        $this->assertSame(1, Server::query()->where('extension', 'CPanel')->count(), 'cp1 now exists twice; the imported one has no password');
    }

    /**
     * F11: a stale TLD price: WHMCS withdraws a period (-1) or the redemption fee and the re-run keeps selling it.
     * (Fix round: a fee of 0.00 is free and offered (D-6, code review 8); WHMCS withdraws one with -1.)
     */
    public function test_f11_a_tld_price_withdrawn_in_whmcs_is_gone_after_a_rerun(): void
    {
        $this->import();
        $this->whmcs->exec("UPDATE tblpricing SET qsetupfee = -1 WHERE type = 'domainregister' AND relid = 31");
        $this->whmcs->exec('UPDATE tbldomainpricing SET redemption_grace_period_fee = -1 WHERE id = 31');

        $this->import();

        $com = DomainTld::query()->where('tld', 'com')->sole()->load('prices');
        $this->assertSame([null, null], [$com->priceFor('register', 2, 'EUR'), $com->priceFor('redemption', 1, 'EUR')]);
    }

    // ---- what holds ------------------------------------------------------------------------------------------------

    public function test_h_failure_midway_rolls_everything_back(): void
    {
        $this->whmcs->exec('DROP TABLE tbldomainpricing'); // the last step fails
        $settings = Setting::query()->count();
        try {
            $this->import();
            $this->fail('expected an exception');
        } catch (\PDOException) {
        }
        $this->assertSame([0, 0, 0, 0], [Product::query()->count(), Category::query()->count(), Server::query()->count(), Setting::query()->count() - $settings]);
    }

    public function test_h_an_existing_category_and_product_with_the_same_slug_are_untouched(): void
    {
        $cat = Category::factory()->create(['name' => 'Ours', 'slug' => 'hosting']);
        $prod = Product::factory()->create(['category_id' => $cat->id, 'name' => 'Ours S', 'slug' => 'hosting-s']);

        $this->import();

        $this->assertSame(['Ours', 'hosting', 'Ours S', 'hosting-s', $cat->id], [$cat->fresh()->name, $cat->fresh()->slug, $prod->fresh()->name, $prod->fresh()->slug, $prod->fresh()->category_id]);
        $this->assertNotSame('hosting', Category::query()->where('whmcs_id', 3)->value('slug'));
    }

    public function test_h_no_secret_in_settings_audits_or_notes(): void
    {
        Config::set('audit.console', true);
        $result = $this->import();

        $blob = DB::table('settings')->pluck('value')->implode(' ') . json_encode(DB::table('audits')->get()) . implode(' ', $result['notes']);
        foreach (['Encrypted-Pass', 'Encrypted-Hash', 'Fixture-Psk-1'] as $secret) {
            $this->assertStringNotContainsString($secret, $blob);
        }
    }

    public function test_h_a_value_moved_to_another_option_and_odd_tlds(): void
    {
        $this->import();
        $this->whmcs->exec("INSERT INTO tblproductconfigoptions VALUES (50, 9, 'disk|Disk', 1, 1, 0)");
        $this->whmcs->exec('UPDATE tblproductconfigoptionssub SET configid = 50 WHERE id = 41');
        $this->whmcs->exec("INSERT INTO tbldomainpricing VALUES (33, '.CO.UK', 0, 0, 0, 1, '', 3, -1, 0, -1, 0), (34, '.ελ', 0, 0, 0, 1, '', 4, -1, 0, -1, 0), (35, 'bad tld!', 0, 0, 0, 1, '', 5, -1, 0, -1, 0)");
        $count = ConfigOption::query()->count();

        $result = $this->import();

        $this->assertSame($count + 1, ConfigOption::query()->count());
        $this->assertSame(ConfigOption::query()->where('whmcs_ref', 'option:50')->value('id'), ConfigOption::query()->where('whmcs_ref', 'value:41')->value('parent_id'));
        $this->assertTrue(DomainTld::query()->where('tld', 'co.uk')->exists());
        $this->assertTrue(DomainTld::query()->where('tld', 'xn--qxam')->exists());
        $this->assertNotEmpty(array_filter($result['notes'], fn ($n) => str_contains($n, 'bad tld!')));
    }
}
