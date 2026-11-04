<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\ConfigOption;
use App\Models\Plan;
use App\Models\Price;
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
 * WG-11: a service's options come from tblhostingconfigoptions, by the catalog's whmcs_ref: myip's Dedicated IP (a
 * checkbox with a price, 3 live), Extra Addon domain (a quantity, 2), a VPS's OS and location (dropdowns). Its price
 * stays WHMCS's. Fake data only.
 */
class ImportServiceOptionsTest extends TestCase
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

        $this->product = Product::factory()->create(['name' => 'VPS']);
        $this->plan = Plan::factory()->create(['priceable_id' => $this->product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $this->plan->id, 'price' => 20.00, 'setup_fee' => 0, 'currency_code' => 'EUR']);
    }

    /** A catalog option as CatalogImport brings it: whmcs_ref «option:N», its values «value:N». */
    private function option(int $whmcsId, string $type, int $whmcsType, string $name, array $values, ?string $env = null): ConfigOption
    {
        $option = new ConfigOption;
        $option->forceFill(['name' => $name, 'env_variable' => $env, 'type' => $type, 'sort' => 0, 'hidden' => false, 'whmcs_ref' => "option:{$whmcsId}"])->save();
        $this->whmcs->prepare('INSERT INTO tblproductconfigoptions VALUES (?, ?, ?)')->execute([$whmcsId, $name, $whmcsType]);
        foreach ($values as $valueId => [$valueName, $price]) {
            $value = new ConfigOption;
            $value->forceFill(['name' => $valueName, 'parent_id' => $option->id, 'sort' => 0, 'whmcs_ref' => "value:{$valueId}"])->save();
            if ($price !== null) {
                $plan = Plan::factory()->create(['priceable_id' => $value->id, 'priceable_type' => ConfigOption::class, 'name' => 'Monthly',
                    'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
                Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'EUR']);
            }
        }

        return $option;
    }

    private function service(int $id, float $price): Service
    {
        $this->whmcs->exec("INSERT INTO tblhosting VALUES ({$id}, 'Active')");

        return Service::factory()->create(['id' => $id, 'client_id' => User::factory()->create()->client()->id, 'product_id' => $this->product->id,
            'plan_id' => $this->plan->id, 'currency_code' => 'EUR', 'price' => $price, 'undiscounted_price' => $price, 'status' => Service::STATUS_ACTIVE]);
    }

    private function chose(int $service, int $option, int $value, int $qty): void
    {
        $this->whmcs->prepare('INSERT INTO tblhostingconfigoptions (relid, configid, optionid, qty) VALUES (?, ?, ?, ?)')->execute([$service, $option, $value, $qty]);
    }

    private function import(): string
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output = new BufferedOutput));
        (new ReflectionMethod($command, 'importServiceOptions'))->invoke($command);

        return $output->fetch();
    }

    public function test_a_service_gets_its_options_and_keeps_its_whmcs_price(): void
    {
        $ip = $this->option(10, 'checkbox', 3, 'Dedicated IP', [100 => ['IP', 4.00]]);
        $os = $this->option(11, 'select', 1, 'OS', [110 => ['Ubuntu 22.04', null], 111 => ['Debian 12', null]], 'vmOSimage');
        $this->option(12, 'number', 4, 'Extra Addon domain', [120 => ['Extra Addon domain', null]]);
        $withIp = $this->service(501, 24.00);
        $without = $this->service(502, 20.00);
        $this->chose(501, 10, 100, 1);
        $this->chose(501, 11, 111, 0);
        $this->chose(501, 12, 120, 2);
        $this->chose(502, 10, 100, 0); // not ticked
        $this->chose(502, 12, 120, 0); // none

        $out = $this->import();

        $configs = fn (Service $service) => $service->fresh()->configs->pluck('config_value_id', 'config_option_id')->all();
        $this->assertSame([$ip->id => $ip->children()->sole()->id, $os->id => $os->children()->where('name', 'Debian 12')->sole()->id], $configs($withIp));
        $this->assertSame([], $configs($without), 'an unticked checkbox, a quantity of 0: nothing');
        $this->assertSame('2', $withIp->properties()->where('key', 'Extra Addon domain')->value('value'));
        $this->assertSame(0, $without->properties()->count());
        $this->assertSame('24.00', number_format((float) $withIp->fresh()->price, 2, '.', ''), 'WHMCS\'s amount stays');
        $this->assertSame('24.00', (string) $withIp->fresh()->calculatePrice(), 'the catalog now counts its Dedicated IP');
        $this->assertStringContainsString('Service options: 2 chosen, 1 quantities.', $out);
    }

    public function test_an_option_or_value_whmcs_no_longer_has_is_said_and_parity_counts_them(): void
    {
        $os = $this->option(11, 'select', 1, 'OS', [110 => ['Ubuntu 22.04', null]]);
        $this->service(601, 20.00);
        $this->service(602, 20.00);
        $this->chose(601, 11, 999, 0); // a value deleted
        $this->chose(602, 77, 770, 1); // an option deleted
        $this->chose(602, 11, 110, 0);

        $out = $this->import();

        $this->assertStringContainsString('2 service option(s) whose option or value WHMCS no longer has', $out);
        $this->assertSame(1, DB::table('service_configs')->count());
        // Parity: WHMCS counts the deleted value's row (its option is there), here it is missing: shown.
        foreach (['CREATE TABLE tblclients (id INTEGER PRIMARY KEY, credit REAL DEFAULT 0)', 'CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, status TEXT, subtotal REAL, tax REAL, tax2 REAL, credit REAL, total REAL)',
            'CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INTEGER, type TEXT, relid INTEGER, amount REAL)',
            'CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, invoiceid INTEGER, amountin REAL, amountout REAL, fees REAL)',
            'CREATE TABLE tbltickets (id INTEGER PRIMARY KEY)', 'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY)'] as $sql) {
            $this->whmcs->exec($sql);
        }
        $rows = collect((new WhmcsParity($this->whmcs))->rows())->keyBy('metric');
        $this->assertSame([2, 1, false], [$rows['Service options']['whmcs'], $rows['Service options']['mycs'], $rows['Service options']['same']]);
        $this->assertTrue($rows['Service quantity options']['same']);
        $this->assertNotNull($os);
    }
}
