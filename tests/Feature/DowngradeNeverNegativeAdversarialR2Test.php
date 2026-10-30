<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Livewire\Services\Upgrade;
use App\Models\ConfigOption;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceConfig;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\User;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Round-2 adversarial review of #551 (fix/downgrade-never-negative @ 8bab77e0): the round-1 fixes (`needsStaff`,
 * `catalogPricesIt`, `repricesFromCatalog`, the renewal shown on the Upgrade page, the importer's floor).
 *
 * `test_bug_*` fail on the branch as reviewed (a finding, ADV-551-N); `test_holds_*` are attacks that held.
 *
 * Catalog (setUp): Hosting, yearly, 20.00 USD; option Backup: None 0.00, Weekly 10.00, Daily 30.00 (USD and EUR). Today is
 * 2026-07-02; the period paid runs 2026-01-01 → 2027-01-01 (183 of 365 days left).
 */
class DowngradeNeverNegativeAdversarialR2Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private Plan $plan;

    private ConfigOption $backup;

    /** @var array<string, int> value name => id */
    private array $values = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-02 10:00:00');
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'prefix' => '€', 'format' => '1.000,00']);
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_on_downgrade' => [true, 'boolean'],
            'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']]);
        $this->user = User::factory()->create();
        $this->user->client()->primaryContact->update(['country' => 'GR']);
        $this->product = Product::factory()->create(['name' => 'Hosting', 'server_id' => null, 'stock' => null]);
        $this->plan = Plan::factory()->create(['priceable_id' => $this->product->id, 'priceable_type' => Product::class, 'name' => 'Yearly',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $this->plan->id, 'price' => 20.00, 'setup_fee' => 0, 'currency_code' => 'USD']);
        $this->backup = ConfigOption::create(['name' => 'Backup', 'type' => 'select', 'sort' => 0, 'upgradable' => true, 'hidden' => false]);
        $this->backup->products()->attach($this->product->id);
        foreach ([['None', 0], ['Weekly', 10], ['Daily', 30]] as $sort => [$name, $price]) {
            $value = ConfigOption::create(['name' => $name, 'sort' => $sort, 'parent_id' => $this->backup->id]);
            $valuePlan = Plan::factory()->create(['priceable_id' => $value->id, 'priceable_type' => ConfigOption::class, 'name' => $name,
                'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
            foreach (['USD', 'EUR'] as $currency) {
                Price::factory()->create(['plan_id' => $valuePlan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => $currency]);
            }
            $this->values[$name] = $value->id;
        }
        $this->backup->load('children');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(array $values): void
    {
        foreach ($values as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
    }

    /** A service at $price per unit with backups $value, its year 2026 paid ($price × quantity). */
    private function service(float $price, string $value = 'Daily', array $extra = [], bool $paid = true): Service
    {
        $service = Service::factory()->create($extra + ['client_id' => $this->user->client()->id, 'product_id' => $this->product->id,
            'plan_id' => $this->plan->id, 'price' => $price, 'quantity' => 1, 'currency_code' => 'USD',
            'status' => Service::STATUS_ACTIVE, 'expires_at' => '2027-01-01']);
        $service->configs()->create(['config_option_id' => $this->backup->id, 'config_value_id' => $this->values[$value]]);
        if ($paid) {
            $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID,
                'currency_code' => $service->currency_code]);
            $invoice->items()->create(['description' => 'Period', 'price' => $price, 'quantity' => (int) $service->quantity,
                'reference_id' => $service->id, 'reference_type' => Service::class, 'period_start' => '2026-01-01', 'period_end' => '2027-01-01']);
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        }

        return $service->fresh();
    }

    /** The customer sets the backups to $value on the Upgrade page. */
    private function choose(Service $service, string $value): Service
    {
        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service->fresh()])
            ->set("configOptions.{$this->backup->id}", $this->values[$value])->call('doUpgrade');

        return $service->fresh();
    }

    private function price(Service $service): string
    {
        return number_format((float) $service->fresh()->price, 2, '.', '');
    }

    private function credit(): float
    {
        return round((float) Credit::query()->where('client_id', $this->user->client()->id)->sum('amount'), 2);
    }

    /** An EUR service on a plan priced 50.00 EUR, with backups $value (Daily 30 EUR), paid for 2026 at $price. */
    private function eurService(float $price, string $value = 'Daily'): Service
    {
        Price::factory()->create(['plan_id' => $this->plan->id, 'price' => 50.00, 'setup_fee' => 0, 'currency_code' => 'EUR']);

        return $this->service($price, $value, ['currency_code' => 'EUR']);
    }

    /** A Backup value «Hourly» priced 100.00, only in USD (none in EUR): a value staff priced in one currency. */
    private function hourlyInUsdOnly(): int
    {
        $value = ConfigOption::create(['name' => 'Hourly', 'sort' => 3, 'parent_id' => $this->backup->id]);
        $plan = Plan::factory()->create(['priceable_id' => $value->id, 'priceable_type' => ConfigOption::class, 'name' => 'Hourly',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 100, 'setup_fee' => 0, 'currency_code' => 'USD']);
        $this->backup->load('children');

        return $this->values['Hourly'] = $value->id;
    }

    // ------------------------------------------------------------------------------------------------ findings

    /**
     * ADV-551-R2-1 (High). `catalogPricesIt` checks the plan only; an option VALUE with no price in the service's currency
     * still reads as 0.00 (ConfigOption::price → a null price). Upgrading TO such a value is then a «downgrade»: an EUR
     * service at 80 (plan 50 + Daily 30) that moves to Hourly (100, USD only) is priced 80 − 30 = 50, applied at once, and
     * credited 30 € for the days left; from None it is applied free at the same price. Round 1's
     * test_holds_an_option_priced_only_in_another_currency covered only the value it leaves, not the one it takes.
     */
    public function test_bug_an_option_value_without_a_price_in_the_services_currency_is_taken_as_free_and_credited(): void
    {
        $this->hourlyInUsdOnly();
        $daily = $this->eurService(80.00, 'Daily');
        $none = $this->service(50.00, 'None', ['currency_code' => 'EUR']);

        $daily = $this->choose($daily, 'Hourly');
        $none = $this->choose($none, 'Hourly');

        $this->assertFalse(ServiceUpgrade::query()->where('service_id', $none->id)->where('status', ServiceUpgrade::STATUS_COMPLETED)->exists(),
            'None → Hourly: not applied at once, free, at the price it had');

        $this->assertSame(0.0, $this->credit(), 'no credit for moving up to an option the catalog cannot price in EUR');
        $this->assertNotSame('50.00', $this->price($daily), 'nor a lower price for a bigger option');
        $this->assertFalse(ServiceUpgrade::query()->where('service_id', $daily->id)->where('status', ServiceUpgrade::STATUS_COMPLETED)->exists(),
            'refused for staff, as needsStaff does for a plan without a price');
    }

    /**
     * ADV-551-R2-2 (Low). `needsStaff` is only asked on the customer's page. A fallback change made while the plan was priced
     * in EUR (25 − 30 < 0: the catalog's 50, invoiced 25 for the days left) and paid after the EUR price was removed is
     * applied by ServiceUpgradeService::handle (the payment, the cron's paid_applied) without asking it: newRecurringPrice
     * then keeps 25.00, so the customer paid the jump to 50 and the price never moves; no staff notice either.
     */
    public function test_bug_a_paid_fallback_upgrade_is_applied_without_needs_staff_after_its_plan_lost_the_currency(): void
    {
        $service = $this->choose($this->eurService(25.00, 'Daily'), 'None');
        $upgrade = ServiceUpgrade::query()->where('service_id', $service->id)->where('status', ServiceUpgrade::STATUS_PENDING)->sole();
        $this->assertGreaterThan(0, (float) InvoiceItem::query()->where('invoice_id', $upgrade->invoice_id)->sum('price'));

        Price::query()->where('plan_id', $this->plan->id)->where('currency_code', 'EUR')->delete();
        $this->assertTrue($upgrade->fresh()->needsStaff());
        DB::table('invoices')->where('id', $upgrade->invoice_id)->update(['status' => Invoice::STATUS_PAID]);
        (new ServiceUpgradeService)->handle($upgrade->fresh());

        $applied = $upgrade->fresh()->status === ServiceUpgrade::STATUS_COMPLETED;
        $this->assertFalse($applied && $this->price($service) === '25.00',
            'a paid change to the catalog price is not applied at the old price without staff');
    }

    // ------------------------------------------------------------------------------------------------ held

    /** A plan without an EUR price: refused on the page, nothing saved, no credit, the price as it was (ADV-551-1 fix). */
    public function test_holds_needs_staff_rolls_everything_back(): void
    {
        $service = $this->choose($this->service(25.00, 'Daily', ['currency_code' => 'EUR']), 'None');

        $this->assertSame('25.00', $this->price($service));
        $this->assertSame(0, ServiceUpgrade::query()->where('service_id', $service->id)->count());
        $this->assertSame(0, ServiceConfig::query()->where('configurable_type', ServiceUpgrade::class)->count());
        $this->assertSame(0.0, $this->credit());
        $this->assertTrue($service->configs()->where('config_value_id', $this->values['Daily'])->exists());
    }

    /**
     * `repricesFromCatalog` is read in handle() before the service changes, as newRecurringPrice: a locked staff price
     * that falls back is stored at the catalog's and unlocked; one that stays above zero keeps its lock.
     */
    public function test_holds_reprices_from_catalog_is_read_before_the_change(): void
    {
        $fallback = $this->choose($this->service(25.00, 'Daily', ['price_locked' => true]), 'None');
        $kept = $this->choose($this->service(45.00, 'Daily', ['price_locked' => true]), 'None');

        $this->assertSame('20.00', $this->price($fallback));
        $this->assertFalse((bool) $fallback->fresh()->price_locked);
        $this->assertSame('15.00', $this->price($kept));
        $this->assertTrue((bool) $kept->fresh()->price_locked);
    }

    /** The page renders on step 1 and step 2, also on a product with no plan for the cycle (doUpgrade refuses it). */
    public function test_holds_the_renewal_renders_on_any_step_and_any_product(): void
    {
        $other = Product::factory()->create(['name' => 'Bigger', 'server_id' => null, 'stock' => null]);
        Plan::factory()->create(['priceable_id' => $other->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        DB::table('product_upgrades')->insert(['product_id' => $this->product->id, 'upgrade_id' => $other->id]);
        $service = $this->service(25.00);

        $page = $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service->fresh()])->assertOk();
        $page->set('upgrade', $other->id)->assertOk();
        $page->call('doUpgrade')->assertOk();

        $this->assertSame(0, ServiceUpgrade::query()->where('service_id', $service->id)->count());
        $this->assertSame('25.00', $this->price($service));
    }

    /** EUR formatting (1.000,00): the renewal row is shown when the price changes, hidden when it stays, in the right currency. */
    public function test_holds_the_renewal_row_compares_and_formats_in_the_services_currency(): void
    {
        $service = $this->eurService(1250.00, 'Daily');
        $page = $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service->fresh()]);

        $page->set("configOptions.{$this->backup->id}", $this->values['Daily'])->assertDontSeeHtml('data-renewal');
        $page->set("configOptions.{$this->backup->id}", $this->values['Weekly'])->assertSeeHtml('data-renewal')
            ->assertSee('1.230,00')->assertSee('1.250,00');
    }

    /** The importer: a negative amount is floored and named; an ordinary one keeps its cents. */
    public function test_holds_the_importer_floors_only_below_zero(): void
    {
        $command = app(ImportFromWhmcs::class);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        $amount = new ReflectionMethod($command, 'recurringAmount');

        $this->assertSame('0.00', $amount->invoke($command, ['id' => 7, 'amount' => '-5.00']));
        $this->assertStringContainsString('#7', $output->fetch());
        $this->assertSame('12.50', $amount->invoke($command, ['id' => 8, 'amount' => '12.5']));
        $this->assertSame('0.00', $amount->invoke($command, ['id' => 9, 'amount' => '0.00']));
        $this->assertSame('', $output->fetch());
    }
}
