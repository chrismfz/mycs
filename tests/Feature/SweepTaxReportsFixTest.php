<?php

namespace Tests\Feature;

use App\Classes\Settings;
use App\Events\Service\RenewalTaxChanged;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Invoice\CreateInvoiceSnapshotService;
use App\Services\Reports\IncomeReport;
use App\Services\Reports\RevenueForecast;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** The fixes of the tax-reports sweep (TAX-REPORTS-1..4): what the sweep's own tests do not cover. */
class SweepTaxReportsFixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'suffix' => ' EUR', 'format' => '1.000,00']);
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive', 'settings.default_currency' => 'EUR']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function client(string $country): Client
    {
        $client = User::factory()->create()->client();
        $client->primaryContact->update(['country' => $country]);

        return $client->fresh();
    }

    private function service(Client $client, array $extra = [], string $unit = 'month'): Service
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'p',
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'setup_fee' => 0, 'currency_code' => 'EUR']);

        return Service::factory()->create($extra + ['client_id' => $client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'EUR', 'price' => 10, 'quantity' => 1, 'expires_at' => '2026-11-05']);
    }

    /** Many rates saved in one commit (a seed of the EU rates): each subscription is repriced once, not once per rate. */
    public function test_rates_changed_in_one_commit_reprice_each_subscription_once(): void
    {
        $a = $this->service($this->client('EE'), ['subscription_id' => 'sub_1']);
        $b = $this->service($this->client('DE'), ['subscription_id' => 'sub_2']);
        $this->service($this->client('DE')); // no subscription: nothing to reprice
        Event::fake([RenewalTaxChanged::class]);

        DB::transaction(function () {
            TaxRate::create(['name' => 'VAT', 'rate' => 22, 'country' => 'EE']);
            TaxRate::create(['name' => 'MwSt', 'rate' => 19, 'country' => 'DE']);
        });

        Event::assertDispatchedTimes(RenewalTaxChanged::class, 2);
        Event::assertDispatched(RenewalTaxChanged::class, fn (RenewalTaxChanged $event) => $event->service->is($a));
        Event::assertDispatched(RenewalTaxChanged::class, fn (RenewalTaxChanged $event) => $event->service->is($b));
    }

    /** The listener prices with the new rate; the rest of the request keeps the rates it started with (its memo). */
    public function test_the_listener_sees_the_new_rate_and_the_request_keeps_its_own(): void
    {
        $rate = TaxRate::create(['name' => 'VAT', 'rate' => 22, 'country' => 'EE']);
        $client = $this->client('EE');
        $this->service($client, ['subscription_id' => 'sub_1']);
        $this->assertEquals(22, Settings::tax($client)->rate);
        $seen = [];
        Event::listen(RenewalTaxChanged::class, function () use (&$seen, $client) {
            $seen[] = (float) Settings::tax($client)->rate;
        });

        $rate->update(['rate' => 24]);

        $this->assertSame([24.0], $seen);
        $this->assertEquals(22, Settings::tax($client)->rate, 'memoised for the request, as before');
    }

    /** A rate removed reprices too; a rename alone does not change what anyone pays. */
    public function test_a_removed_rate_reprices_and_a_renamed_one_does_not(): void
    {
        $rate = TaxRate::create(['name' => 'VAT', 'rate' => 22, 'country' => 'EE']);
        $this->service($this->client('EE'), ['subscription_id' => 'sub_1']);
        Event::fake([RenewalTaxChanged::class]);

        $rate->update(['name' => 'KM']);
        Event::assertNotDispatched(RenewalTaxChanged::class);

        $rate->delete();
        Event::assertDispatchedTimes(RenewalTaxChanged::class, 1);
    }

    /** A rolled-back rate change fires nothing; the next committed one still does. */
    public function test_a_rolled_back_change_does_not_stop_the_next_one(): void
    {
        $rate = TaxRate::create(['name' => 'VAT', 'rate' => 22, 'country' => 'EE']);
        $this->service($this->client('EE'), ['subscription_id' => 'sub_1']);
        Event::fake([RenewalTaxChanged::class]);

        try {
            DB::transaction(function () use ($rate) {
                $rate->update(['rate' => 23]);
                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }
        Event::assertNotDispatched(RenewalTaxChanged::class);

        $rate->refresh()->update(['rate' => 24]);
        Event::assertDispatchedTimes(RenewalTaxChanged::class, 1);
    }

    /**
     * A tax setting changed (EU VAT on, its home country): the subscriptions are repriced, and the listener already
     * prices with the new settings and rates (this process's config is reloaded only when the app terminates).
     */
    public function test_a_changed_tax_setting_reprices_with_the_new_setting(): void
    {
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'EE']);
        $client = $this->client('EE');
        $this->service($client, ['subscription_id' => 'sub_1']);
        Setting::query()->updateOrCreate(['key' => 'tax_enabled', 'settingable_type' => null], ['value' => true, 'type' => 'boolean']);
        $seen = [];
        Event::listen(RenewalTaxChanged::class, function () use (&$seen, $client) {
            $seen[] = [config('settings.tax_enabled'), config('settings.tax_type'), Invoice::factory()->create(['client_id' => $client->id,
                'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR'])->items()->create(['description' => 'x', 'price' => '10.00', 'quantity' => 1])->tax_rate];
        });

        DB::transaction(function () {
            Setting::query()->where('key', 'tax_enabled')->whereNull('settingable_type')->first()->update(['value' => false]);
            Setting::query()->updateOrCreate(['key' => 'tax_type', 'settingable_type' => null], ['value' => 'inclusive', 'type' => 'string']);
        });

        $this->assertCount(1, $seen, 'once for both settings');
        $this->assertFalse((bool) $seen[0][0]);
        $this->assertSame('inclusive', $seen[0][1]);
        $this->assertSame('0.0000', (string) $seen[0][2], 'tax off: no VAT on the line the listener sees');
    }

    /** A setting that is not about tax, or one for a product, fires nothing. */
    public function test_other_settings_do_not_reprice(): void
    {
        $this->service($this->client('EE'), ['subscription_id' => 'sub_1']);
        Event::fake([RenewalTaxChanged::class]);

        Setting::query()->updateOrCreate(['key' => 'company_name', 'settingable_type' => null], ['value' => 'Another example', 'type' => 'string']);
        Setting::query()->create(['key' => 'tax_enabled', 'value' => false, 'type' => 'boolean', 'settingable_type' => Product::class, 'settingable_id' => 1]);

        Event::assertNotDispatched(RenewalTaxChanged::class);
    }

    /** A rule for one country stays the issued country, even when the customer moved since (F4 of #280). */
    public function test_the_export_keeps_a_country_rules_country(): void
    {
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'EE']);
        $client = $this->client('EE');
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $invoice->items()->create(['description' => 'x', 'quantity' => 1, 'price' => 100, 'tax_rate' => '24']);
        app(CreateInvoiceSnapshotService::class)->handle($invoice->fresh());
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => '2026-03-01 10:00:00']);
        $client->primaryContact->update(['country' => 'DE']);

        $this->assertSame('EE', IncomeReport::year('EUR', 2026)['invoices'][0]['country']);
    }

    /** The annual estimate: a weekly plan 52 times a year, a quantity of 0 nothing (as ClientIncome). */
    public function test_the_annual_estimate_counts_weekly_plans_and_quantity_zero_as_the_billing(): void
    {
        $client = $this->client('US');
        $this->service($client, [], 'week');
        $this->service($client, ['quantity' => 0]);

        $estimate = RevenueForecast::annualEstimate()['EUR'];

        $this->assertSame('520.00', $estimate['annual']);
        $this->assertSame(['amount' => '10.00', 'count' => 1], $estimate['cycles']['other']);
        $this->assertSame(['amount' => '0.00', 'count' => 1], $estimate['cycles'][1]);
    }

    /** A daily plan renews every day of the month in the forecast. */
    public function test_a_daily_plan_is_in_the_forecast_every_day(): void
    {
        $this->service($this->client('US'), ['expires_at' => '2026-11-01'], 'day');

        $this->assertSame('300.00', RevenueForecast::for('EUR', CarbonImmutable::parse('2026-11-01'), 1)['months'][0]['total']);
    }
}
