<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\ConfigOption;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
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
 * Review of #653 (WG-11). Fake data only.
 */
class Review653Test extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private Product $product;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblproductconfigoptions (id INTEGER PRIMARY KEY, optionname TEXT, optiontype INT)');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, domainstatus TEXT)');
        $this->whmcs->exec('CREATE TABLE tblhostingconfigoptions (id INTEGER PRIMARY KEY, relid INT, configid INT, optionid INT, qty INT)');

        $this->product = Product::factory()->create(['name' => 'Senior']);
        $this->plan = Plan::factory()->create(['priceable_id' => $this->product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $this->plan->id, 'price' => 7.00, 'setup_fee' => 0, 'currency_code' => 'EUR']);
    }

    private function option(int $whmcsId, int $valueId, string $name, string $price): ConfigOption
    {
        $option = new ConfigOption;
        $option->forceFill(['name' => $name, 'type' => 'checkbox', 'sort' => 0, 'hidden' => false, 'whmcs_ref' => "option:{$whmcsId}"])->save();
        $this->whmcs->prepare('INSERT INTO tblproductconfigoptions VALUES (?, ?, 3)')->execute([$whmcsId, $name]);
        $value = new ConfigOption;
        $value->forceFill(['name' => 'IP', 'parent_id' => $option->id, 'sort' => 0, 'whmcs_ref' => "value:{$valueId}"])->save();
        $plan = Plan::factory()->create(['priceable_id' => $value->id, 'priceable_type' => ConfigOption::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'EUR']);

        return $option;
    }

    private function import(): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new ReflectionMethod($command, 'importServiceOptions'))->invoke($command);
    }

    /**
     * R653-1. myip: «Dedicated IP» (a checkbox priced 2.00/month) is ticked on services of products its option group is
     * not linked to (tblproductconfiglinks): WHMCS never charged it (their amount is the product's price alone, e.g.
     * 7.00 on a 7.00 product) and never offered it to them. Imported as a service_config it is priced by
     * Service::calculatePrice, which every catalog price of the service uses: a cycle change (ChangeCycle::quote),
     * an upgrade's newRecurringPrice, «Recalculate», the price mismatch report. The customer would pay for it here.
     */
    public function test_an_option_not_linked_to_the_service_product_is_not_priced_after_import(): void
    {
        $this->option(17, 50, 'Dedicated IP', '2.00');
        // Not linked to the product (no config_option_products row), as WHMCS's group 10 is not linked to it.
        $this->whmcs->exec("INSERT INTO tblhosting VALUES (1237, 'Active')");
        $service = Service::factory()->create(['id' => 1237, 'client_id' => User::factory()->create()->client()->id, 'product_id' => $this->product->id,
            'plan_id' => $this->plan->id, 'currency_code' => 'EUR', 'price' => 7.00, 'undiscounted_price' => 7.00, 'status' => Service::STATUS_ACTIVE]);
        $this->whmcs->exec('INSERT INTO tblhostingconfigoptions (relid, configid, optionid, qty) VALUES (1237, 17, 50, 1)');

        $this->import();

        $this->assertSame(0, DB::table('config_option_products')->count());
        $this->assertSame('7.00', (string) $service->fresh()->calculatePrice(),
            'WHMCS charged 7.00 (the product alone): an option its product does not link must not raise the catalog price');
    }
}
