<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\ConfigOption;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\ServiceUpgrade\ChangeCycle;
use Carbon\Carbon;
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
 * Adversarial review of #653 (WG-11, a service's WHMCS options imported). Fake data only, shaped as myip's live ones:
 *
 * - The Dedicated IP checkbox (WHMCS option group 10) is ticked on the 3 live services that have it, and that group is
 *   linked to none of their products (tblproductconfiglinks: products 11 and 56 only). WHMCS prices, shows and recalculates
 *   only the options linked to the product (getCartConfigOptions): a «Personal2» on 62.00/year with the IP ticked is billed
 *   62.00, the product's price; a «Senior» on 7.00/month is billed 7.00 and its invoice line never says «Dedicated IP».
 * - «Extra Addon domain» (a quantity, 5.00/month, 60.00/year each) ×2 on a MyMail 50 of 21.00/month: WHMCS bills 31.00.
 */
class Adv653ServiceOptionsTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private Product $product;

    /** @var array<string, Plan> */
    private array $plans = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 10:00:00');
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblproductconfigoptions (id INTEGER PRIMARY KEY, optionname TEXT, optiontype INT)');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, domainstatus TEXT)');
        $this->whmcs->exec('CREATE TABLE tblhostingconfigoptions (id INTEGER PRIMARY KEY, relid INT, configid INT, optionid INT, qty INT)');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, float>  $prices  unit => price, a plan of one period each */
    private function product(string $name, array $prices): void
    {
        $this->product = Product::factory()->create(['name' => $name]);
        foreach ($prices as $unit => $price) {
            $this->plans[$unit] = Plan::factory()->create(['priceable_id' => $this->product->id, 'priceable_type' => Product::class,
                'name' => $unit, 'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
            Price::factory()->create(['plan_id' => $this->plans[$unit]->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'EUR']);
        }
    }

    /**
     * A catalog option as CatalogImport brings it (whmcs_ref «option:N», a value «value:N» priced per cycle), linked to
     * the product only when WHMCS links its group.
     *
     * @param  array<string, float>  $prices  unit => price of its one value
     */
    private function option(int $whmcsId, string $type, int $whmcsType, string $name, int $valueId, string $valueName, array $prices, bool $linked): ConfigOption
    {
        $option = new ConfigOption;
        $option->forceFill(['name' => $name, 'type' => $type, 'sort' => 0, 'hidden' => false, 'whmcs_ref' => "option:{$whmcsId}"])->save();
        $this->whmcs->prepare('INSERT INTO tblproductconfigoptions VALUES (?, ?, ?)')->execute([$whmcsId, $name, $whmcsType]);
        $value = new ConfigOption;
        $value->forceFill(['name' => $valueName, 'parent_id' => $option->id, 'sort' => 0, 'whmcs_ref' => "value:{$valueId}"])->save();
        foreach ($prices as $unit => $price) {
            $plan = Plan::factory()->create(['priceable_id' => $value->id, 'priceable_type' => ConfigOption::class, 'name' => $unit,
                'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
            Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'EUR']);
        }
        if ($linked) {
            DB::table('config_option_products')->insert(['product_id' => $this->product->id, 'config_option_id' => $option->id]);
        }

        return $option;
    }

    /** An imported service: WHMCS's amount as its price and undiscounted_price (importServices). */
    private function service(int $id, string $unit, float $price): Service
    {
        $this->whmcs->exec("INSERT INTO tblhosting VALUES ({$id}, 'Active')");

        return Service::factory()->create(['id' => $id, 'client_id' => User::factory()->create()->client()->id, 'product_id' => $this->product->id,
            'plan_id' => $this->plans[$unit]->id, 'currency_code' => 'EUR', 'price' => $price, 'undiscounted_price' => $price, 'quantity' => 1,
            'status' => Service::STATUS_ACTIVE, 'expires_at' => '2027-06-22']);
    }

    private function chose(int $service, int $option, int $value, int $qty): void
    {
        $this->whmcs->prepare('INSERT INTO tblhostingconfigoptions (relid, configid, optionid, qty) VALUES (?, ?, ?, ?)')->execute([$service, $option, $value, $qty]);
    }

    private function import(): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new ReflectionMethod($command, 'importServiceOptions'))->invoke($command);
    }

    /** myip's «Personal2» (62.00/year, 105.00/2 years) with the Dedicated IP ticked, its group not linked to the product. */
    private function personalWithUnlinkedIp(): Service
    {
        $this->product('Personal2', ['year' => 62.00]);
        $this->plans['biennial'] = Plan::factory()->create(['priceable_id' => $this->product->id, 'priceable_type' => Product::class,
            'name' => 'Biennially', 'billing_unit' => 'year', 'billing_period' => 2, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $this->plans['biennial']->id, 'price' => 105.00, 'setup_fee' => 0, 'currency_code' => 'EUR']);
        $ip = $this->option(17, 'checkbox', 3, 'Dedicated IP', 50, 'IP', ['year' => 24.00], linked: false);
        $biennialIp = Plan::factory()->create(['priceable_id' => $ip->children()->sole()->id, 'priceable_type' => ConfigOption::class,
            'name' => 'Biennially', 'billing_unit' => 'year', 'billing_period' => 2, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $biennialIp->id, 'price' => 48.00, 'setup_fee' => 0, 'currency_code' => 'EUR']);
        $service = $this->service(1049, 'year', 62.00);
        $this->chose(1049, 17, 50, 1);
        $this->import();

        return $service->fresh();
    }

    /**
     * ADV-653-1 (high). An imported option whose group WHMCS does not link to the service's product is priced by the
     * catalog as if it were part of the product. WHMCS bills a Personal2 with an unlinked Dedicated IP 62.00 a year (the
     * product's price: the IP is not in its amount); here «Recalculate price» (ServiceResource), a cycle change, a renewal
     * on a plan set to come and the price report all compute 62.00 + 24.00 = 86.00. All 3 live Dedicated IPs on myip are
     * such (none of their products links group 10).
     *
     * Fix: count (and import, or keep apart) only the options linked to the service's product (config_option_products),
     * as WHMCS's getCartConfigOptions does; or import an unlinked one as a property, not a priced service_config.
     */
    public function test_adv_653_1_an_option_its_product_does_not_link_is_not_priced(): void
    {
        $service = $this->personalWithUnlinkedIp();

        $this->assertSame('62.00', number_format((float) $service->price, 2, '.', ''), 'WHMCS\'s amount stays (held)');
        $this->assertSame('62.00', (string) $service->calculatePrice(), 'Recalculate: WHMCS never prices an option its product does not link');
    }

    /**
     * ADV-653-1 (high), through a customer's change of cycle from the next renewal (ChangeCycle, which the cron then
     * bills): WHMCS's 2-year price of the product is 105.00; here 105.00 + 48.00 for an IP WHMCS never billed. Before
     * this PR the quote was 105.00.
     */
    public function test_adv_653_1_a_cycle_change_does_not_charge_an_unlinked_option(): void
    {
        $service = $this->personalWithUnlinkedIp();

        $this->assertSame('105.00', ChangeCycle::quote($service, $this->plans['biennial'], ChangeCycle::NEXT_RENEWAL)['price']);
    }

    /**
     * ADV-653-2 (low). The renewal invoice line now says «Dedicated IP: IP» for an option WHMCS neither bills nor shows on
     * that service (its line said «Senior - … (period)» and the linked options only): the customer reads it as billed
     * while the amount does not have it. Same fix as ADV-653-1 (describeOptions: the product's linked options).
     */
    public function test_adv_653_2_a_renewal_line_does_not_list_an_unlinked_option(): void
    {
        $service = $this->personalWithUnlinkedIp();

        $this->assertStringNotContainsString('Dedicated IP', $service->describeOptions());
    }

    /**
     * ADV-653-3 (low, documented gap CART-9/WG-15, not new money but the claim «catalog prices now include the
     * options»): a quantity option is imported as a property, which no catalog price reads. A MyMail 50 billed 31.00 a
     * month by WHMCS (21.00 + 2 × 5.00 Extra Addon domain) changes to yearly at 231.00 (WHMCS's 231.00 + 2 × 60.00 =
     * 351.00), and «Recalculate price» makes it 21.00.
     *
     * Fix: price a quantity option (value × qty) in calculatePrice, or refuse a cycle change / recalculation of a
     * service with a quantity property the catalog cannot price, and flag it for staff.
     */
    public function test_adv_653_3_a_quantity_option_counts_in_a_cycle_change(): void
    {
        // Left open in #653: a priced quantity option is WG-15 (CART-9), the cart's, upgrades' and recalculation's, not the
        // import's; the amounts are the same as before #653 (21.00 / 231.00). This turns on when WG-15 lands.
        $this->markTestIncomplete('ADV-653-3: a priced quantity option, WG-15 (CART-9).');
        $this->product('MyMail 50', ['month' => 21.00, 'year' => 231.00]);
        $this->option(275, 'number', 4, 'Extra Addon domain', 1633, 'Extra Addon domain', ['month' => 5.00, 'year' => 60.00], linked: true);
        $service = $this->service(2618, 'month', 31.00);
        $this->chose(2618, 275, 1633, 2);
        $this->import();
        $service = $service->fresh();

        $this->assertSame('2', $service->properties()->where('key', 'Extra Addon domain')->value('value'), 'imported (held)');
        $this->assertSame('31.00', (string) $service->calculatePrice(), 'Recalculate keeps WHMCS\'s 21.00 + 2 × 5.00');
        $this->assertSame('351.00', ChangeCycle::quote($service, $this->plans['year'], ChangeCycle::NEXT_RENEWAL)['price']);
    }
}
