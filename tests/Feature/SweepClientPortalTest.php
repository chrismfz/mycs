<?php

namespace Tests\Feature;

use App\Admin\Resources\CouponResource\Pages\CreateCoupon;
use App\Classes\Cart as CartClass;
use App\Console\Commands\ImportFromWhmcs;
use App\Exceptions\DisplayException;
use App\Livewire\Auth\Tfa;
use App\Livewire\Products\Checkout;
use App\Models\Cart;
use App\Models\Category;
use App\Models\ConfigOption;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Support\AuthCookies;
use Filament\Facades\Filament;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use RobThree\Auth\Providers\Qr\EndroidQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Adversarial sweep of the client portal (2026-11-07). Each test fails on main (00268d7f) for the defect it names.
 * Fake data only.
 */
class SweepClientPortalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * CLIENT-PORTAL-1. The checkout opens on the product's first plan even when it has no price (a cycle withdrawn, #628):
     * with one plan that can be ordered and nothing to choose, mount() checks out on the unpriced plan, validation
     * refuses it, and no plan select is drawn to pick another: the product cannot be ordered.
     */
    public function test_client_portal_1_checkout_starts_on_a_plan_that_can_be_ordered(): void
    {
        config(['settings.default_currency' => 'USD']);
        $category = Category::factory()->create(['slug' => 'hosting']);
        $product = Product::factory()->create(['category_id' => $category->id, 'slug' => 'starter']);
        Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Quarterly',
            'type' => 'recurring', 'billing_period' => 3, 'billing_unit' => 'month', 'sort' => 0]); // withdrawn: no prices
        $annual = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Annually',
            'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'year', 'sort' => 1]);
        Price::factory()->create(['plan_id' => $annual->id, 'price' => 60, 'currency_code' => 'USD']);

        Livewire::test(Checkout::class, ['category' => $category, 'product' => 'starter'])
            ->assertHasNoErrors()
            ->assertRedirect(route('cart'));
    }

    /**
     * CLIENT-PORTAL-2. A coupon saved in the admin with «Expires At» = a day (a date-only picker on a datetime) expires at
     * 00:00 of that day, so it is refused all its last day; the import counts WHMCS's date as the last valid day (endOfDay).
     */
    public function test_client_portal_2_a_coupon_from_the_admin_is_valid_all_its_last_day(): void
    {
        Carbon::setTestNow('2026-12-31 08:00:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]), 'staff');

        Livewire::test(CreateCoupon::class)
            ->fillForm(['code' => 'XMAS', 'value' => '10', 'type' => 'percentage', 'applies_to' => 'all', 'expires_at' => '2026-12-31'])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertNotNull(Coupon::query()->where('code', 'XMAS')->first());

        Carbon::setTestNow('2026-12-31 12:00:00');
        try {
            CartClass::validateCoupon('XMAS');
        } catch (DisplayException $e) {
            $this->fail('refused on its last day: ' . $e->getMessage());
        } finally {
            Carbon::setTestNow();
        }
        $this->assertTrue(true);
    }

    /**
     * CLIENT-PORTAL-3a. ?currency= switches the session but not the existing (empty) cart: the shop then prices in USD
     * while the cart (and its invoice) stay in EUR. CurrencySwitch/LocaleSwitch update the cart; the middleware does not.
     */
    public function test_client_portal_3_currency_in_the_link_moves_the_empty_cart_too(): void
    {
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $category = Category::factory()->create(['slug' => 'hosting']);
        $this->createProduct(['category_id' => $category->id]);
        $cart = Cart::create(['currency_code' => 'EUR']); // e.g. made by ?coupon= on an empty cart

        $this->withCookie(AuthCookies::cart(), $cart->ulid)->withSession(['currency' => 'EUR'])
            ->get('/products/hosting?currency=USD')->assertOk();

        $this->assertSame('USD', session('currency'));
        $this->assertSame(session('currency'), $cart->fresh()->currency_code, 'the shop shows USD, the cart charges EUR');
    }

    /** CLIENT-PORTAL-3b. ?currency[]=x hands an array to shouldBlockCurrencyChange(string): a TypeError, a 500. */
    public function test_client_portal_3_an_array_currency_is_not_a_server_error(): void
    {
        $category = Category::factory()->create(['slug' => 'hosting']);
        $this->createProduct(['category_id' => $category->id]);
        $this->assertSame(200, $this->get('/products/hosting')->getStatusCode(), 'the page itself opens');

        try {
            $status = $this->withoutExceptionHandling()->get('/products/hosting?currency[]=USD')->getStatusCode();
        } catch (\TypeError $e) {
            $this->fail('TypeError: ' . $e->getMessage());
        }
        $this->assertLessThan(500, $status);
    }

    /**
     * CLIENT-PORTAL-4. A WHMCS sub-option marked hidden (tblproductconfigoptionssub.hidden) comes as a value customers can
     * choose: the importer does not read the column and nothing filters a hidden child at checkout.
     */
    public function test_client_portal_4_a_hidden_whmcs_sub_option_cannot_be_ordered(): void
    {
        config(['settings.default_currency' => 'EUR']);
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
            'CREATE TABLE tblproductconfigoptionssub (id INTEGER, configid INTEGER, optionname TEXT, sortorder INTEGER, hidden INTEGER)',
            'CREATE TABLE tblproductconfiglinks (id INTEGER, gid INTEGER, pid INTEGER)',
            'CREATE TABLE tblproduct_upgrade_products (product_id INTEGER, upgrade_product_id INTEGER)',
            'CREATE TABLE tblservers (id INTEGER, name TEXT, type TEXT, hostname TEXT, ipaddress TEXT, port INTEGER, username TEXT, maxaccounts INTEGER, active INTEGER, disabled INTEGER)',
            'CREATE TABLE tblservergroups (id INTEGER, name TEXT, filltype INTEGER)',
            'CREATE TABLE tblservergroupsrel (groupid INTEGER, serverid INTEGER)',
            'CREATE TABLE tblhostingconfigoptions (id INTEGER PRIMARY KEY, relid INT, configid INT, optionid INT, qty INT)',
            "INSERT INTO tblcurrencies VALUES (1, 'EUR', '€', '', 3, 1)",
            "INSERT INTO tblproductgroups VALUES (3, 'Hosting', 'hosting', 'Web hosting', 2, 0)",
            "INSERT INTO tblproducts VALUES (21, 3, 'Hosting S', 'hosting-s', '', 0, 0, 1, 'recurring', 'payment', 0, 0, 0, 0, 0, 0, '', 0, '', '')",
            // Hosting S monthly 5; Extra IP: «None» free, «One IP» 2.00, «Legacy free IP» hidden in WHMCS, 0.00.
            "INSERT INTO tblpricing (type, currency, relid, msetupfee, qsetupfee, ssetupfee, asetupfee, bsetupfee, tsetupfee, monthly, quarterly, semiannually, annually, biennially, triennially)
                VALUES ('product', 1, 21, 0, 0, 0, 0, 0, 0, 5, -1, -1, -1, -1, -1),
                ('configoptions', 1, 61, 0, 0, 0, 0, 0, 0, 0, -1, -1, -1, -1, -1), ('configoptions', 1, 62, 0, 0, 0, 0, 0, 0, 2, -1, -1, -1, -1, -1),
                ('configoptions', 1, 63, 0, 0, 0, 0, 0, 0, 0, -1, -1, -1, -1, -1)",
            "INSERT INTO tblproductconfigoptions VALUES (60, 2, 'ip|Extra IP', 1, 0, 0)",
            "INSERT INTO tblproductconfigoptionssub VALUES (61, 60, 'none|None', 0, 0), (62, 60, 'one|One IP', 1, 0), (63, 60, 'legacy|Legacy free IP', 2, 1)",
            'INSERT INTO tblproductconfiglinks VALUES (1, 2, 21)',
        ] as $sql) {
            $whmcs->exec($sql);
        }
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        foreach (['importServers', 'importCatalog'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }

        $option = ConfigOption::query()->where('whmcs_ref', 'option:60')->sole();
        $hidden = ConfigOption::query()->where('whmcs_ref', 'value:63')->sole();
        $product = Product::query()->where('whmcs_id', 21)->sole();

        Livewire::test(Checkout::class, ['category' => $product->category, 'product' => $product->slug])
            ->set("configOptions.{$option->id}", $hidden->id)
            ->call('checkout')
            ->assertHasErrors("configOptions.{$option->id}");
    }

    /** CLIENT-PORTAL-5. A wrong 2FA code is told in English to a Greek customer: 'Invalid code.' has no __(). */
    public function test_client_portal_5_a_wrong_2fa_code_is_told_in_the_customers_language(): void
    {
        $tfa = new TwoFactorAuth(new EndroidQrCodeProvider, config('app.name'));
        $secret = $tfa->createSecret();
        $user = User::factory()->create(['tfa_secret' => $secret]);
        Session::put('2fa', ['user_id' => $user->id, 'remember' => false, 'expires' => now()->addMinutes(5)]);
        app()->setLocale('el');
        $wrong = $tfa->getCode($secret) === '000000' ? '111111' : '000000';

        $errors = Livewire::test(Tfa::class)->set('code', $wrong)->call('verify')->assertHasErrors('code')->errors();

        $this->assertNotSame('Invalid code.', $errors->first('code'), 'English to a Greek customer');
    }
}
