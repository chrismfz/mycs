<?php

namespace Tests\Feature;

use App\Models\ConfigOption;
use App\Models\Product;
use App\Services\Whmcs\CatalogImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

/**
 * Review of #662 (WG-15). Fake data only.
 */
class Review662Test extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'EUR']);
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE tblcurrencies (id INTEGER, code TEXT, prefix TEXT, suffix TEXT, format INTEGER, "default" INTEGER)',
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
            'CREATE TABLE tbldomainpricing (id INTEGER, extension TEXT, autoreg TEXT, "order" INTEGER)',
            'CREATE TABLE tblhostingconfigoptions (id INTEGER PRIMARY KEY, relid INT, configid INT, optionid INT, qty INT)',
            "INSERT INTO tblcurrencies VALUES (1, 'EUR', '€', '', 3, 1)",
            "INSERT INTO tblproductgroups VALUES (3, 'Hosting', 'hosting', 'Web hosting', 2, 0)",
            "INSERT INTO tblproducts VALUES (21, 3, 'Hosting S', 'hosting-s', '', 0, 0, 1, 'recurring', 'payment', 0, 0, 0, 0, 0, 0, '', 0, '', '')",
            "INSERT INTO tblpricing (type, currency, relid, msetupfee, qsetupfee, ssetupfee, asetupfee, bsetupfee, tsetupfee, monthly, quarterly, semiannually, annually, biennially, triennially)
                VALUES ('product', 1, 21, 0, 0, 0, 0, 0, 0, 5, -1, -1, -1, -1, -1), ('configoptions', 1, 81, 0, 0, 0, 0, 0, 0, 3, -1, -1, -1, -1, -1)",
            "INSERT INTO tblproductconfigoptions VALUES (80, 2, 'Additional SAN | Additional domains', 4, 0, 0)",
            "INSERT INTO tblproductconfigoptionssub VALUES (81, 80, 'Additional domains', 0)",
            'INSERT INTO tblproductconfiglinks VALUES (1, 2, 21)',
        ] as $sql) {
            $this->whmcs->exec($sql);
        }
    }

    /**
     * R662-1: the catalog's import is re-runnable (app:import-whmcs-catalog on a MyCS that has the catalog already). A
     * quantity option brought by an earlier run (before #662, or while a service still had a quantity of it) is no longer
     * touched once WG-15 skips it: it stays as it was, visible and linked, so the cart keeps offering the option #662 meant
     * to take off it, at no price (CART-9), with no note to staff.
     */
    public function test_r662_1_a_quantity_option_skipped_on_a_rerun_does_not_stay_offered_in_the_cart(): void
    {
        // The earlier run, before #662: brought visible (WHMCS's own hidden flag, 0), linked to its product.
        $this->whmcs->exec('DROP TABLE tblhostingconfigoptions');
        (new CatalogImport($this->whmcs))->run();
        $option = ConfigOption::query()->where('whmcs_ref', 'option:80')->firstOrFail();
        $option->forceFill(['hidden' => false])->save(); // as main's configOptions() brought it
        $product = Product::query()->where('whmcs_id', 21)->firstOrFail();
        $this->assertTrue($product->configOptions->contains('id', $option->id), 'precondition: offered after the first run');

        // The re-run, now with WG-15: nobody ever took it.
        $this->whmcs->exec('CREATE TABLE tblhostingconfigoptions (id INTEGER PRIMARY KEY, relid INT, configid INT, optionid INT, qty INT)');
        $result = (new CatalogImport($this->whmcs))->run();

        $product = Product::query()->whereKey($product->id)->firstOrFail();
        $stillOffered = $product->configOptions->contains('id', $option->id);
        $this->assertFalse($stillOffered && !collect($result['notes'])->contains(fn ($n) => str_contains($n, '#80')),
            'a skipped quantity option brought before stays offered in the cart, and the import says nothing');
    }
}
