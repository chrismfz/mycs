<?php

namespace Tests\Feature;

use App\Admin\Pages\RevenueForecast;
use App\Admin\Widgets\EstimatedIncome;
use App\Enums\DomainStatus;
use App\Models\Credit;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Reports\RevenueForecast as Forecast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * What is coming in (ROADMAP 6, owner 2026-10-20): the renewals of the next 12 months by cycle, as WHMCS's Income
 * Forecast, beside what was paid the same month a year before; net; and WHMCS's billing widget's annual estimate.
 */
class RevenueForecastTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'suffix' => ' EUR', 'format' => '1.000,00']);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(float $price, string $unit, int $period, string $expires, array $extra = []): Service
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'p',
            'billing_unit' => $unit, 'billing_period' => $period, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return Service::factory()->create($extra + ['client_id' => $this->user->client()->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => $price, 'quantity' => 1, 'expires_at' => $expires]);
    }

    /** @return array<string, array<string, mixed>> by 'Y-m' */
    private function months(array $forecast): array
    {
        return collect($forecast['months'])->keyBy(fn ($row) => $row['month']->format('Y-m'))->all();
    }

    public function test_every_active_renewal_falls_in_its_months_by_cycle(): void
    {
        $this->service(10.00, 'month', 1, '2026-10-25');              // every month
        $this->service(120.00, 'year', 1, '2027-01-10');              // January (with the monthly 10 and 5 × 3: 145)
        $this->service(30.00, 'month', 3, '2026-08-01');              // overdue: from November, every 3 months
        $this->service(5.00, 'month', 1, '2026-11-01', ['quantity' => 3]);
        $this->service(99.00, 'month', 1, '2026-11-01', ['status' => Service::STATUS_CANCELLED]);
        $this->service(99.00, 'month', 1, '2026-11-01', ['currency_code' => 'EUR']);

        $months = $this->months(Forecast::for('USD'));

        $this->assertCount(12, $months);
        $this->assertSame(['10.00', '0.00', '0.00'], [$months['2026-10']['cycles'][1], $months['2026-10']['cycles'][3], $months['2026-10']['cycles'][12]]);
        $this->assertSame(['25.00', '30.00'], [$months['2026-11']['cycles'][1], $months['2026-11']['cycles'][3]], '10 + 5 × 3; the overdue quarter');
        $this->assertSame(['120.00', '145.00'], [$months['2027-01']['cycles'][12], $months['2027-01']['total']]);
        $this->assertSame('30.00', $months['2027-02']['cycles'][3]);
    }

    public function test_an_auto_renewing_domain_counts_at_its_years(): void
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['is_active' => true, 'grace_days' => 15, 'redemption_days' => 0]);
        foreach ([[true, '15.00'], [false, '99.00']] as [$auto, $amount]) {
            Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => 'example' . (int) $auto, 'tld' => 'gr', 'fqdn' => 'example' . (int) $auto . '.gr',
                'domain_tld_id' => $tld->id, 'status' => DomainStatus::Active, 'expires_at' => '2026-12-05', 'years' => 2,
                'currency_code' => 'USD', 'recurring_amount' => $amount, 'auto_renew' => $auto]);
        }

        $months = $this->months(Forecast::for('USD'));

        $this->assertSame(['15.00', '15.00'], [$months['2026-12']['domains'], $months['2026-12']['total']]);
        $this->assertSame('0.00', $months['2027-12']['domains'] ?? '0.00', 'every 2 years');
    }

    public function test_the_same_month_last_year_is_what_was_paid_then_net_without_credit_purchases(): void
    {
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive']);
        $paid = function (string $when, float $price, ?string $reference = null) {
            $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
            $invoice->items()->create(array_filter(['description' => 'x', 'quantity' => 1, 'price' => $price, 'tax_rate' => '24', 'reference_type' => $reference]));
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => $when]);
        };
        $paid('2025-11-03 10:00:00', 100.00);            // net 100, tax 24: 100 counts
        $paid('2025-11-20 10:00:00', 50.00, Credit::class); // add funds: not income
        $paid('2025-12-01 10:00:00', 40.00);

        $months = $this->months(Forecast::for('USD'));

        $this->assertSame(['100.00', '40.00'], [$months['2026-11']['last_year'], $months['2026-12']['last_year']]);
    }

    public function test_this_month_expected_invoiced_and_collected(): void
    {
        $this->service(10.00, 'month', 1, '2026-10-25');
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-10-28']);
        $invoice->items()->create(['description' => 'x', 'quantity' => 1, 'price' => 30]);
        (new RecordPaymentService)->handle($invoice->fresh(), null, 30.0, transactionId: 'wire-1');

        $current = Forecast::for('USD')['current'];

        // Due this month: the 30 invoiced (and paid) and the 10 renewal still to invoice; collected: the 30.
        $this->assertSame(['40.00', '30.00', '30.00'], [$current['expected'], $current['invoiced'], $current['collected']]);
        $this->assertSame('2026-10-21 10:00:00', $invoice->fresh()->paid_at?->format('Y-m-d H:i:s'), 'paid_at set when it became paid');
    }

    public function test_the_annual_estimate_is_whmcs_widgets(): void
    {
        // nixpal's WHMCS widget (2026-10-20): 548.70 monthly, 5,039.96 annually, 3,072 biennially, 1,116 triennially.
        $this->service(548.70, 'month', 1, '2026-11-01');
        $this->service(5039.96, 'year', 1, '2027-01-01');
        $this->service(3072.00, 'year', 2, '2027-01-01');
        $this->service(1116.00, 'year', 3, '2027-01-01');

        $estimate = Forecast::annualEstimate()['USD'];

        $this->assertSame('13532.36', $estimate['annual']);
        $this->assertSame(['548.70', 1], [$estimate['cycles'][1]['amount'], $estimate['cycles'][1]['count']]);
    }

    public function test_only_staff_allowed_see_it_and_export_it(): void
    {
        $this->service(10.00, 'month', 1, '2026-10-25');
        $this->actingAs(Staff::factory()->withPermissions(['admin.clients.viewAny'])->create())->get(RevenueForecast::getUrl())->assertForbidden();
        $staff = Staff::factory()->withPermissions(['admin.reports.view'])->create();
        $this->actingAs($staff)->get(RevenueForecast::getUrl())->assertOk()->assertSee(__('Same month last year'));

        $this->livewireAs($staff)->test(RevenueForecast::class)->callAction('csv')->assertFileDownloaded('revenue-forecast-USD-2026-10-21.csv');
    }

    public function test_the_widget_hides_with_nothing_to_show(): void
    {
        Cache::flush();
        $this->actingAs(Staff::factory()->withPermissions(['admin.reports.view'])->create());
        $this->assertFalse(EstimatedIncome::canView(), 'an empty heading with no recurring service');

        $this->service(10.00, 'month', 1, '2026-11-01');
        Cache::flush();
        $this->assertTrue(EstimatedIncome::canView());
    }
}
