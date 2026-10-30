<?php

namespace Tests\Feature;

use App\Console\Commands\CronJob;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Round-3 (final, combined) review of #551 (fix/downgrade-never-negative @ 01682a30): the round-2 code
 * (`chosenValuesPriced`, `needsStaff` in ServiceUpgradeService::handle) and the whole diff.
 *
 * `test_bug_*` fail on the branch as reviewed (a finding, ADV-551-R3-N); `test_holds_*` are attacks that held.
 *
 * Catalog (setUp): Hosting, yearly, 20.00 USD / 50.00 EUR; Bigger, yearly, 100.00 USD / 100.00 EUR, an upgrade of
 * Hosting; option Backup (on both): None 0.00, Weekly 10.00, Daily 30.00 (USD and EUR). Today is 2026-07-02; the period
 * paid runs 2026-01-01 → 2027-01-01 (183 of 365 days left).
 */
class DowngradeNeverNegativeAdversarialR3Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private Plan $plan;

    private Product $bigger;

    private Plan $biggerPlan;

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
        [$this->product, $this->plan] = $this->product('Hosting', ['USD' => 20, 'EUR' => 50]);
        [$this->bigger, $this->biggerPlan] = $this->product('Bigger', ['USD' => 100, 'EUR' => 100]);
        DB::table('product_upgrades')->insert(['product_id' => $this->product->id, 'upgrade_id' => $this->bigger->id]);
        $this->backup = ConfigOption::create(['name' => 'Backup', 'type' => 'select', 'sort' => 0, 'upgradable' => true, 'hidden' => false]);
        $this->backup->products()->attach([$this->product->id, $this->bigger->id]);
        foreach ([['None', 0], ['Weekly', 10], ['Daily', 30]] as $sort => [$name, $price]) {
            $this->values[$name] = $this->value($this->backup, $name, $sort, ['USD' => $price, 'EUR' => $price]);
        }
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

    /** @return array{Product, Plan} */
    private function product(string $name, array $prices, string $type = 'recurring'): array
    {
        $product = Product::factory()->create(['name' => $name, 'server_id' => null, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Yearly',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => $type]);
        foreach ($prices as $currency => $price) {
            Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => $currency]);
        }

        return [$product, $plan];
    }

    /** A value of $option with one plan ($unit, $type) priced in the currencies given. */
    private function value(ConfigOption $option, string $name, int $sort, array $prices, string $unit = 'year', string $type = 'recurring'): int
    {
        $value = ConfigOption::create(['name' => $name, 'sort' => $sort, 'parent_id' => $option->id]);
        $plan = Plan::factory()->create(['priceable_id' => $value->id, 'priceable_type' => ConfigOption::class, 'name' => $name,
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => $type]);
        foreach ($prices as $currency => $price) {
            Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => $currency]);
        }
        $option->load('children');

        return $value->id;
    }

    /** A service at $price per unit with backups $value, its year 2026 paid. */
    private function service(float $price, string $value = 'Daily', array $extra = []): Service
    {
        $service = Service::factory()->create($extra + ['client_id' => $this->user->client()->id, 'product_id' => $this->product->id,
            'plan_id' => $this->plan->id, 'price' => $price, 'quantity' => 1, 'currency_code' => 'EUR',
            'status' => Service::STATUS_ACTIVE, 'expires_at' => '2027-01-01']);
        $service->configs()->create(['config_option_id' => $this->backup->id, 'config_value_id' => $this->values[$value]]);
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID,
            'currency_code' => $service->currency_code]);
        $invoice->items()->create(['description' => 'Period', 'price' => $price, 'quantity' => 1,
            'reference_id' => $service->id, 'reference_type' => Service::class, 'period_start' => '2026-01-01', 'period_end' => '2027-01-01']);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);

        return $service->fresh();
    }

    /** The customer moves to $product (null: stays) with backups $value on the Upgrade page. */
    private function choose(Service $service, string $value, ?Product $product = null)
    {
        $page = $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service->fresh()]);
        if ($product !== null) {
            $page->set('upgrade', $product->id);
        }

        return $page->set("configOptions.{$this->backup->id}", $this->values[$value])->call('doUpgrade');
    }

    private function pending(Service $service): ServiceUpgrade
    {
        return ServiceUpgrade::query()->where('service_id', $service->id)->where('status', ServiceUpgrade::STATUS_PENDING)->sole();
    }

    /** Its invoice paid, then applied as the payment applies it. */
    private function pay(ServiceUpgrade $upgrade): bool
    {
        DB::table('invoices')->where('id', $upgrade->invoice_id)->update(['status' => Invoice::STATUS_PAID]);

        return (new ServiceUpgradeService)->handle($upgrade->fresh());
    }

    private function price(Service $service): string
    {
        return number_format((float) $service->fresh()->price, 2, '.', '');
    }

    private function credit(): float
    {
        return round((float) Credit::query()->where('client_id', $this->user->client()->id)->sum('amount'), 2);
    }

    // ------------------------------------------------------------------------------------------------ findings

    /**
     * ADV-551-R3-1 (Low, as R2-2). R2-2's guard in handle() covers an options-only change whose plan lost the service's
     * currency, not a change of product: `needsStaff` asks `catalogPricesIt` only `changesOptionsOnly()`. An EUR service
     * (Hosting 50 + Daily 30 = 80) moves to Bigger (100 EUR + Daily 30), invoiced for the days left; staff remove Bigger's
     * EUR price before it is paid. On payment it is applied, and `$service->calculatePrice()` reads the plan as 0.00: the
     * service renews at 30.00 (the option only), below the 80.00 it had, with no notice. docs/decisions.md says a paid
     * change the catalog can no longer price is not applied and goes to staff.
     */
    public function test_bug_a_paid_product_change_whose_new_plan_lost_the_currency_renews_at_the_options_only(): void
    {
        $service = $this->service(80.00);
        $this->choose($service, 'Daily', $this->bigger);
        $upgrade = $this->pending($service);
        $this->assertGreaterThan(0, (float) InvoiceItem::query()->where('invoice_id', $upgrade->invoice_id)->sum('price'));

        Price::query()->where('plan_id', $this->biggerPlan->id)->where('currency_code', 'EUR')->delete();
        $this->pay($upgrade);

        $this->assertFalse($upgrade->fresh()->status === ServiceUpgrade::STATUS_COMPLETED && $this->price($service) === '30.00',
            'a paid product change is not applied at a plan price of 0.00: cancelled for staff, as R2-2');
    }

    /**
     * ADV-551-R3-2 (Low). `catalogPricesIt` asks for a Price row in the currency; a plan of type `free` has none, and is
     * priced 0.00 by Plan::price (free: true), not «unpriced». A service on a free plan that pays for a Daily backup (30)
     * and drops it to None (kept 30 − 30 = 0, so the fallback) is refused with «needs our staff», though the catalog
     * prices the change perfectly well: 0.00, the plan's. No money is at risk (a free plan is never billed or credited),
     * but an ordinary change is sent to staff.
     */
    public function test_bug_an_options_change_on_a_free_plan_is_refused_as_needing_staff(): void
    {
        [$free, $freePlan] = $this->product('Free', [], 'free');
        $this->backup->products()->attach($free->id);
        $service = $this->service(30.00, 'Daily', ['product_id' => $free->id, 'plan_id' => $freePlan->id, 'expires_at' => null]);

        $this->assertFalse(ServiceUpgrade::query()->make(['service_id' => $service->id, 'product_id' => $free->id, 'plan_id' => $freePlan->id])
            ->setRelation('configs', collect([new ServiceConfig(['config_option_id' => $this->backup->id, 'config_value_id' => $this->values['None']])]))
            ->needsStaff(), 'a free plan is priced (0.00), not missing a price');
    }

    /**
     * ADV-551-R3-3 (Nit). The renewal row says «then X per renewal (now Y)» on a one-time plan, which never renews
     * (Service: a one-time or free plan is never billed again). The row should be hidden, or say nothing of renewals,
     * where the plan does not recur.
     */
    public function test_bug_the_renewal_row_is_shown_on_a_plan_that_never_renews(): void
    {
        [$once, $oncePlan] = $this->product('Once', ['USD' => 20, 'EUR' => 50], 'one-time');
        $this->backup->products()->attach($once->id);
        $service = $this->service(60.00, 'Weekly', ['product_id' => $once->id, 'plan_id' => $oncePlan->id, 'expires_at' => null]);

        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service->fresh()])
            ->set("configOptions.{$this->backup->id}", $this->values['Daily'])
            ->assertDontSeeHtml('data-renewal');
    }

    // ------------------------------------------------------------------------------------------------ held

    /**
     * No false positive in handle(): ordinary paid changes, with values on a free-type plan and values with no plan for
     * the cycle (both free, as ConfigOption::price reads them), are applied, not cancelled for staff.
     */
    public function test_holds_ordinary_paid_upgrades_are_applied_with_free_and_cycle_less_values(): void
    {
        $location = ConfigOption::create(['name' => 'Location', 'type' => 'select', 'sort' => 1, 'upgradable' => true, 'hidden' => false]);
        $location->products()->attach([$this->product->id, $this->bigger->id]);
        $athens = $this->value($location, 'Athens', 0, [], 'year', 'free');
        $monthly = $this->value($location, 'Frankfurt', 1, ['USD' => 5], 'month');

        foreach ([[$athens, null], [$monthly, null], [$athens, $this->bigger], [$monthly, $this->bigger]] as [$where, $product]) {
            $service = $this->service(80.00, 'Weekly');
            $service->configs()->create(['config_option_id' => $location->id, 'config_value_id' => $where]);
            $page = $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service->fresh()]);
            if ($product !== null) {
                $page->set('upgrade', $product->id);
            }
            $page->set("configOptions.{$this->backup->id}", $this->values['Daily'])->set("configOptions.{$location->id}", $where)->call('doUpgrade');
            $upgrade = $this->pending($service);
            $this->assertFalse($upgrade->needsStaff());

            $this->assertTrue($this->pay($upgrade));
            $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);
        }
    }

    /**
     * A value priced only in another currency is refused on a product change too (chosen), and an unchanged one the
     * service already has counts as chosen (the page sends every upgradable option): never a credit or a lower price.
     */
    public function test_holds_a_product_change_to_a_value_unpriced_in_the_currency_is_refused(): void
    {
        $this->values['Hourly'] = $this->value($this->backup, 'Hourly', 3, ['USD' => 100]);
        $service = $this->service(80.00);

        $this->choose($service, 'Hourly', $this->bigger);

        $this->assertSame(0, ServiceUpgrade::query()->where('service_id', $service->id)->count());
        $this->assertSame('80.00', $this->price($service));
        $this->assertSame(0.0, $this->credit());
    }

    /**
     * The cron's refreshUpgrade on a pending change that now needs staff lowers (or keeps) its line, but the payment
     * never applies it: cancelled, the price as it was. A zero line is not settled (ChangeOpenLine skips upgrades).
     */
    public function test_holds_the_cron_refresh_then_payment_of_a_change_needing_staff_is_not_applied(): void
    {
        $service = $this->service(60.00, 'Weekly');
        $this->choose($service, 'Daily');
        $upgrade = $this->pending($service);

        Price::query()->whereIn('plan_id', Plan::query()->where('priceable_type', ConfigOption::class)
            ->where('priceable_id', $this->values['Daily'])->pluck('id'))->where('currency_code', 'EUR')->delete();
        $this->assertTrue($upgrade->fresh()->needsStaff());
        $refresh = new ReflectionMethod(CronJob::class, 'refreshUpgrade');
        DB::transaction(fn () => $refresh->invoke(app(CronJob::class), $upgrade->id));
        $this->assertSame(Invoice::STATUS_UNPAID, $upgrade->fresh()->invoice->status, 'a zero upgrade line is never settled');

        $this->assertFalse($this->pay($upgrade));
        $this->assertSame(ServiceUpgrade::STATUS_CANCELLED, $upgrade->fresh()->status);
        $this->assertSame('60.00', $this->price($service));
        $this->assertTrue($service->fresh()->configs()->where('config_value_id', $this->values['Weekly'])->exists());
    }

    /** The paid_applied path calls handle() a second time: once cancelled, it stays cancelled (no second notice, no apply). */
    public function test_holds_a_second_handle_after_the_staff_cancel_does_nothing(): void
    {
        $service = $this->service(25.00);
        $this->choose($service, 'None'); // the fallback: 50 + 0 for the days left
        $upgrade = $this->pending($service);
        Price::query()->where('plan_id', $this->plan->id)->where('currency_code', 'EUR')->delete();

        $this->assertFalse($this->pay($upgrade));
        $this->assertFalse((new ServiceUpgradeService)->handle($upgrade->fresh()));
        $this->assertSame(ServiceUpgrade::STATUS_CANCELLED, $upgrade->fresh()->status);
        $this->assertSame('25.00', $this->price($service));
    }

    /** needsStaff is a constant number of queries whatever the number of values (no N+1), and is not asked on render. */
    public function test_holds_needs_staff_queries_are_bounded(): void
    {
        $service = $this->service(80.00);
        $this->choose($service, 'Weekly', $this->bigger);
        $upgrade = $this->pending($service)->fresh();
        $upgrade->load('service', 'configs', 'plan');

        DB::enableQueryLog();
        $upgrade->needsStaff();
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();

        $more = ConfigOption::create(['name' => 'More', 'type' => 'select', 'sort' => 2, 'upgradable' => true, 'hidden' => false]);
        foreach (range(1, 5) as $i) {
            $upgrade->configs()->create(['config_option_id' => $more->id, 'config_value_id' => $this->value($more, "V{$i}", $i, ['EUR' => $i])]);
        }
        $upgrade->load('configs');
        DB::flushQueryLog();
        $upgrade->needsStaff();
        $this->assertSame($one, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    /** A fallback from a staff price below the catalog is an upgrade to the catalog: invoiced, never credited. */
    public function test_holds_no_credit_beyond_what_was_paid_on_a_fallback_downgrade(): void
    {
        // Paid 25 for the year; Daily → None from 25 is the fallback to 50: an upgrade, never a credit.
        $service = $this->service(25.00);
        $this->choose($service, 'None');

        $this->assertSame(0.0, $this->credit());
        $this->assertGreaterThan(0, (float) InvoiceItem::query()->where('invoice_id', $this->pending($service)->invoice_id)->sum('price'));
    }
}
