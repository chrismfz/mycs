<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Category;
use App\Models\ConfigOption;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Import\WhmcsParity;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * WG-2: the full import's catalog is the catalog import's (CatalogImport), not an older copy of its own: the cPanel and ngm
 * packages, the hidden groups, the retired products, the free prices, the order and the config options' links come, and
 * the products and groups keep their WHMCS ids, which the services point at.
 */
class ImportFullCatalogTest extends TestCase
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

    private function setting(Product $product, string $key): ?string
    {
        return Setting::query()->where(['settingable_type' => $product->getMorphClass(), 'settingable_id' => $product->id, 'key' => $key])->value('value');
    }

    public function test_products_and_groups_keep_their_whmcs_ids_and_their_packages(): void
    {
        $output = $this->import();

        $this->assertSame([21, 22, 23], Product::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame([21, 22, 23], Product::query()->orderBy('id')->pluck('whmcs_id')->all());
        $this->assertSame([3, 8], Category::query()->orderBy('id')->pluck('id')->all());
        $this->assertStringContainsString('Product #24 «Lost»: its group #99 is missing, skipped.', $output);

        $hosting = Product::query()->findOrFail(21);
        $this->assertSame('nix_small', $this->setting($hosting, 'package'));
        $this->assertNotNull($hosting->server_id, 'its server from the servers step');
        $this->assertNotNull($hosting->server_group_id);
        $partner = Product::query()->findOrFail(22);
        $this->assertSame(['wp-basic', '8.3'], [$this->setting($partner, 'package'), $this->setting($partner, 'php')]);
        $this->assertSame([21 => [23]], [21 => $hosting->upgrades()->pluck('products.id')->all()]);
    }

    public function test_hidden_groups_retired_products_and_order_come(): void
    {
        $this->import();

        $partners = Category::query()->findOrFail(8);
        $this->assertFalse((bool) $partners->show_in_menu);
        $this->assertSame([2, 5], Category::query()->orderBy('id')->pluck('sort')->all());
        $this->assertTrue((bool) Product::query()->findOrFail(22)->hidden, 'a hidden group\'s product is not listed');
        $old = Product::query()->findOrFail(23);
        $this->assertTrue((bool) $old->retired);
        $this->assertTrue((bool) $old->hidden);
        $this->assertFalse((bool) Product::query()->findOrFail(21)->hidden);
    }

    public function test_free_prices_and_the_config_options_links_come(): void
    {
        $this->import();

        // Annually at 0.00 is offered (free), as WHMCS sells it; quarterly -1 is not.
        $plans = Plan::query()->where(['priceable_type' => (new Product)->getMorphClass(), 'priceable_id' => 21])->orderBy('id')->pluck('name')->all();
        $this->assertSame(['Monthly', 'Annually'], $plans);
        $annual = Plan::query()->where(['priceable_id' => 21, 'name' => 'Annually'])->sole();
        $this->assertSame(['0.00'], Price::query()->where('plan_id', $annual->id)->pluck('price')->map(fn ($p) => number_format((float) $p, 2))->all());
        // An option value at 0.00 is offered free too.
        $daily = ConfigOption::query()->where('whmcs_ref', 'value:51')->sole();
        $this->assertSame(['0.00'], Price::query()->whereIn('plan_id', $daily->plans()->pluck('id'))->pluck('price')->map(fn ($p) => number_format((float) $p, 2))->all());

        // Hosting S's option is Extra IP, by its id here (not WHMCS's 60, nor 2 by position).
        $extraIp = ConfigOption::query()->where('whmcs_ref', 'option:60')->sole();
        $this->assertNotSame(60, $extraIp->id);
        $this->assertSame([[21, $extraIp->id]], DB::table('config_option_products')->get()->map(fn ($l) => [(int) $l->product_id, (int) $l->config_option_id])->all());
    }

    public function test_a_service_on_a_free_cycle_lands_on_its_plan_and_product(): void
    {
        $this->import();
        $client = User::factory()->create()->client();
        $this->whmcs->exec("INSERT INTO tblclients (id, currency) VALUES ({$client->id}, 1)");
        $this->whmcs->exec("INSERT INTO tblhosting VALUES (801, {$client->id}, NULL, 21, 2, '2024-01-01', 'site.example.test', 1, 0, 'Annually', '2026-12-01',
            'Active', 'site', '', '', '', '', '', 0, '0000-00-00')");

        $this->import('importServices');

        $service = Service::query()->findOrFail(801);
        $this->assertSame(21, (int) $service->product_id);
        $this->assertSame(Plan::query()->where(['priceable_id' => 21, 'name' => 'Annually'])->value('id'), $service->plan_id);
    }

    public function test_parity_counts_the_products_listed_ones_and_packages(): void
    {
        foreach (['CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, status TEXT, subtotal REAL, tax REAL, tax2 REAL, credit REAL, total REAL)',
            'CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INTEGER, type TEXT, relid INTEGER, amount REAL)',
            'CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, invoiceid INTEGER, amountin REAL, amountout REAL, fees REAL)',
            'CREATE TABLE tbltickets (id INTEGER PRIMARY KEY)', 'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY)'] as $sql) {
            $this->whmcs->exec($sql);
        }
        $this->import();
        $catalog = fn () => collect((new WhmcsParity($this->whmcs))->rows())->filter(fn (array $row) => str_starts_with($row['metric'], 'Products'))
            ->mapWithKeys(fn (array $row) => [$row['metric'] => [$row['whmcs'], $row['mycs'], $row['same']]])->all();

        // Lost's group is not there (skipped on both sides); only Hosting S is listed; all three have a package.
        $this->assertSame(['Products' => [3, 3, true], 'Products listed' => [1, 1, true], 'Products with a package' => [3, 3, true]], $catalog());

        Setting::query()->where('key', 'package')->where('settingable_id', 23)->delete();
        $this->assertSame([3, 2, false], $catalog()['Products with a package']);
    }

    public function test_an_id_taken_here_is_an_error_never_another_id(): void
    {
        $taken = Category::query()->create(['name' => 'Staff\'s', 'slug' => 'staffs']);
        DB::table('categories')->where('id', $taken->id)->update(['id' => 3]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Category #3 is here already, not from WHMCS');
        $this->import();
    }
}
