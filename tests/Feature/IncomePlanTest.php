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
use App\Services\Reports\IncomePlan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Cash planning (owner 2026-10-28): this month received / expected / total, the last month, the year so far and the
 * next months, each against a year before; the overdue apart. Net, one currency, every invoice and period once.
 */
class IncomePlanTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        Queue::fake();
        Cache::flush();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'suffix' => ' EUR', 'format' => '1.000,00']);
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive']);
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

    /** An invoice of one line (net $price, 24% on top), in $status; paid ones on $paidAt. */
    private function invoice(float $price, string $status, ?string $due = null, ?string $paidAt = null, array $line = [], string $currency = 'USD'): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID,
            'currency_code' => $currency, 'due_at' => $due]);
        $invoice->items()->create($line + ['description' => 'x', 'quantity' => 1, 'price' => $price, 'tax_rate' => '24']);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status, 'paid_at' => $paidAt]);

        return $invoice->fresh();
    }

    /** @return array<string, array<string, mixed>> by 'Y-m' */
    private function months(string $from = '2026-10-01', int $count = 4, string $currency = 'USD'): array
    {
        return collect(IncomePlan::months($currency, CarbonImmutable::parse($from), $count))->keyBy(fn ($row) => $row['month']->format('Y-m'))->all();
    }

    public function test_received_is_what_the_invoices_paid_in_the_month_brought_in_net(): void
    {
        $this->invoice(100, Invoice::STATUS_PAID, '2026-10-05', '2026-10-05 09:00:00');           // 100 (124 with tax)
        $this->invoice(40, Invoice::STATUS_PAID, '2026-09-28', '2026-10-02 09:00:00');            // due in September, paid now
        $this->invoice(50, Invoice::STATUS_PAID, '2026-10-01', '2026-10-01 09:00:00', ['reference_type' => Credit::class]); // add funds
        $this->invoice(70, Invoice::STATUS_PAID, '2026-09-01', '2026-09-30 23:59:59');            // last month
        $this->invoice(80, Invoice::STATUS_PAID, '2026-10-01', '2026-10-03 09:00:00', [], 'EUR'); // another currency

        $october = $this->months()['2026-10'];

        $this->assertSame(['140.00', '0.00', '140.00'], [$october['received'], $october['expected'], $october['total']]);
    }

    public function test_expected_is_what_is_left_on_open_invoices_due_in_the_month_net(): void
    {
        $this->invoice(100, Invoice::STATUS_UNPAID, '2026-10-30');
        $partial = $this->invoice(200, Invoice::STATUS_UNPAID, '2026-10-10');      // 248 gross, overdue but this month's
        (new RecordPaymentService)->handle($partial, null, 62.0, transactionId: 'wire-1'); // a quarter paid: 150 net left
        $this->invoice(30, Invoice::STATUS_DRAFT, '2026-10-30');
        $this->invoice(30, Invoice::STATUS_CANCELLED, '2026-10-30');
        $this->invoice(30, Invoice::STATUS_REFUNDED, '2026-10-30');
        $this->invoice(30, Invoice::STATUS_UNPAID, '2026-10-30', null, [], 'EUR');
        $this->invoice(60, Invoice::STATUS_UNPAID, '2026-11-02');                  // November's
        $this->invoice(25, Invoice::STATUS_UNPAID, '2026-10-20', null, ['reference_type' => Credit::class]); // add funds

        $months = $this->months();

        $this->assertSame(Invoice::STATUS_UNPAID, $partial->fresh()->status);
        $this->assertSame(['0.00', '250.00', '0.00', '250.00', '250.00'],
            [$months['2026-10']['received'], $months['2026-10']['invoices'], $months['2026-10']['renewals'], $months['2026-10']['expected'], $months['2026-10']['total']]);
        $this->assertSame('60.00', $months['2026-11']['expected']);
    }

    public function test_a_credit_note_on_an_open_invoice_lowers_what_is_expected(): void
    {
        $invoice = $this->invoice(100, Invoice::STATUS_UNPAID, '2026-10-30');      // 124 gross
        $invoice->adjustmentNotes()->create(['type' => 'credit', 'amount' => -31, 'description' => 'Goodwill']);

        // Its net less the note's net part (as RevenueForecast counts it): 100 − 31 × 100 / 124 = 75, none of it paid.
        $this->assertSame('75.00', $this->months()['2026-10']['invoices']);
    }

    public function test_a_renewal_on_an_open_invoice_is_expected_once_by_its_invoice(): void
    {
        // Monthly 10: November's period invoiced early (due 28 October), December's not yet.
        $invoiced = $this->service(10, 'month', 1, '2026-11-02');
        $this->invoice(10, Invoice::STATUS_UNPAID, '2026-10-28', null,
            ['reference_type' => Service::class, 'reference_id' => $invoiced->id, 'period_start' => '2026-11-02', 'period_end' => '2026-12-02']);
        // Monthly 20 due this month, not invoiced: expected as a renewal.
        $this->service(20, 'month', 1, '2026-10-25');

        $months = $this->months();

        $this->assertSame(['10.00', '20.00', '30.00'], [$months['2026-10']['invoices'], $months['2026-10']['renewals'], $months['2026-10']['expected']]);
        $this->assertSame(['0.00', '20.00'], [$months['2026-11']['invoices'], $months['2026-11']['renewals']], 'November: the invoice counted in October');
        $this->assertSame('30.00', $months['2026-12']['renewals'], 'December: both renew');
    }

    public function test_a_period_on_a_cancelled_invoice_is_a_renewal_to_expect_again(): void
    {
        $service = $this->service(10, 'month', 1, '2026-10-25');
        $this->invoice(10, Invoice::STATUS_CANCELLED, '2026-10-25', null,
            ['reference_type' => Service::class, 'reference_id' => $service->id, 'period_start' => '2026-10-25', 'period_end' => '2026-11-25']);

        $this->assertSame(['0.00', '10.00'], [$this->months()['2026-10']['invoices'], $this->months()['2026-10']['renewals']]);
    }

    public function test_a_renewal_given_up_on_ends_what_is_expected_of_the_service(): void
    {
        // Its invoice cancelled and the period given up on (forgone): the billing bills nothing after it until staff close
        // the gap (RenewalBilling, Δ3); the service falls to suspension (review of #500).
        $service = $this->service(10, 'month', 1, '2026-10-25');
        $invoice = $this->invoice(10, Invoice::STATUS_CANCELLED, '2026-10-25', null,
            ['reference_type' => Service::class, 'reference_id' => $service->id, 'period_start' => '2026-10-25', 'period_end' => '2026-11-25']);
        DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['forgone_at' => '2026-10-20 10:00:00']);

        $months = $this->months();

        $this->assertSame(['0.00', '0.00'], [$months['2026-10']['invoices'], $months['2026-10']['renewals']]);
        $this->assertSame(['0.00', '0.00'], [$months['2026-11']['renewals'], $months['2026-12']['renewals']]);
    }

    public function test_a_period_paid_but_not_applied_yet_is_received_not_expected_again(): void
    {
        // Paid today, the service not renewed yet (BIL-9): its date still 25 October.
        $service = $this->service(10, 'month', 1, '2026-10-25');
        $this->invoice(10, Invoice::STATUS_PAID, '2026-10-25', '2026-10-21 09:00:00',
            ['reference_type' => Service::class, 'reference_id' => $service->id, 'period_start' => '2026-10-25', 'period_end' => '2026-11-25']);

        $october = $this->months()['2026-10'];

        $this->assertSame(['10.00', '0.00', '10.00'], [$october['received'], $october['expected'], $october['total']]);
    }

    public function test_a_domain_renewal_on_an_open_invoice_is_not_counted_twice(): void
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['is_active' => true, 'grace_days' => 15, 'redemption_days' => 0]);
        $domain = Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => 'example', 'tld' => 'gr', 'fqdn' => 'example.gr',
            'domain_tld_id' => $tld->id, 'status' => DomainStatus::Active, 'expires_at' => '2026-11-10', 'years' => 2,
            'currency_code' => 'USD', 'recurring_amount' => '15.00', 'auto_renew' => true]);
        $this->assertSame('15.00', $this->months()['2026-11']['renewals'], 'not invoiced: its renewal');

        $this->invoice(15, Invoice::STATUS_UNPAID, '2026-11-10', null,
            ['reference_type' => Domain::class, 'reference_id' => $domain->id, 'period_start' => '2026-11-10', 'period_end' => '2028-11-10']);

        $november = $this->months()['2026-11'];
        $this->assertSame(['15.00', '0.00', '15.00'], [$november['invoices'], $november['renewals'], $november['expected']]);
    }

    public function test_the_same_month_a_year_before_is_what_was_received_then(): void
    {
        $this->invoice(90, Invoice::STATUS_PAID, '2025-10-01', '2025-10-31 23:59:59');
        $this->invoice(55, Invoice::STATUS_PAID, '2025-11-01', '2025-11-01 00:00:00');
        $this->invoice(99, Invoice::STATUS_UNPAID, '2025-10-01');                    // never paid: nothing then

        $months = $this->months();

        $this->assertSame(['90.00', '55.00', '0.00'], [$months['2026-10']['last_year'], $months['2026-11']['last_year'], $months['2026-12']['last_year']]);
    }

    public function test_the_summary_last_month_year_so_far_overdue_and_the_months_ahead(): void
    {
        $this->invoice(70, Invoice::STATUS_PAID, '2026-09-01', '2026-09-15 09:00:00');      // last month
        $this->invoice(60, Invoice::STATUS_PAID, '2025-09-01', '2025-09-15 09:00:00');      // a year before it
        $this->invoice(10, Invoice::STATUS_PAID, '2026-01-01', '2026-01-01 00:00:00');      // the year so far: 70 + 10 + 5
        $this->invoice(5, Invoice::STATUS_PAID, '2026-10-21', '2026-10-21 23:59:59');       // today, late
        $this->invoice(20, Invoice::STATUS_PAID, '2025-10-21', '2025-10-21 23:59:59');      // the same days last year: 60 + 20
        $this->invoice(40, Invoice::STATUS_PAID, '2025-10-22', '2025-10-22 00:00:00');      // after them
        $this->invoice(30, Invoice::STATUS_UNPAID, '2026-09-30 23:00:00');                   // overdue, apart
        $this->invoice(12, Invoice::STATUS_UNPAID, '2026-03-01');
        $this->invoice(33, Invoice::STATUS_UNPAID, '2026-10-01');                            // this month's
        $this->service(10, 'month', 1, '2026-11-05');

        $summary = IncomePlan::summary('USD');

        $this->assertSame(['5.00', '33.00', '38.00'], [$summary['this_month']['received'], $summary['this_month']['expected'], $summary['this_month']['total']]);
        $this->assertSame(['2026-09', '70.00', '60.00'], [$summary['last_month']['month']->format('Y-m'), $summary['last_month']['received'], $summary['last_month']['last_year']]);
        $this->assertSame(['85.00', '80.00'], [$summary['year']['received'], $summary['year']['last_year']]);
        $this->assertSame(['42.00', 2], [$summary['overdue']['amount'], $summary['overdue']['count']]);
        $this->assertSame(['2026-11', '2026-12', '2027-01'], array_map(fn ($row) => $row['month']->format('Y-m'), $summary['ahead']));
        $this->assertSame(['10.00', '10.00', '10.00'], array_column($summary['ahead'], 'total'));
    }

    public function test_on_29_february_the_year_before_runs_to_the_end_of_28_february(): void
    {
        Carbon::setTestNow('2028-02-29 12:00:00');
        $this->invoice(10, Invoice::STATUS_PAID, '2027-02-28', '2027-02-28 23:00:00');
        $this->invoice(20, Invoice::STATUS_PAID, '2027-03-01', '2027-03-01 00:00:00');

        $this->assertSame('10.00', IncomePlan::summary('USD')['year']['last_year']);
    }

    public function test_the_widget_shows_the_month_the_comparisons_and_the_cycles(): void
    {
        config(['settings.default_currency' => 'USD']);
        $this->invoice(100, Invoice::STATUS_PAID, '2026-10-05', '2026-10-05 09:00:00');
        $this->invoice(80, Invoice::STATUS_PAID, '2025-10-05', '2025-10-05 09:00:00');
        $this->invoice(30, Invoice::STATUS_UNPAID, '2026-10-30');
        $this->invoice(15, Invoice::STATUS_UNPAID, '2026-08-30');
        $this->service(10, 'month', 1, '2026-10-25');

        $this->livewireAs(Staff::factory()->withPermissions(['admin.reports.view', 'admin.invoices.viewAny'])->create())->test(EstimatedIncome::class)
            ->assertSeeInOrder([__('Received'), '$100.00', __('Expected'), '$40.00', __('Month total'), '$140.00'])
            ->assertSee('+$60.00 (+75%)')    // against October 2025's 80
            ->assertSee(trans_choice('Overdue from earlier months: :amount (:count invoice)|Overdue from earlier months: :amount (:count invoices)', 1, ['amount' => '$15.00']))
            ->assertSee(__('Next months, if everyone pays'))
            ->assertSeeInOrder([__('Monthly') . ' (1)', '$10.00', __('Est. Annual'), '$120.00'])
            ->assertSeeInOrder([__('Today'), '$0.00', __('All Time'), '$180.00']);
    }

    public function test_the_year_month_by_month_past_months_expect_nothing_more(): void
    {
        $this->invoice(70, Invoice::STATUS_PAID, '2026-03-01', '2026-03-10 09:00:00');
        $this->invoice(50, Invoice::STATUS_PAID, '2025-03-01', '2025-03-10 09:00:00');
        $this->invoice(30, Invoice::STATUS_UNPAID, '2026-09-15');                     // September left it unpaid: overdue
        $this->invoice(20, Invoice::STATUS_PAID, '2026-10-01', '2026-10-02 09:00:00');
        $this->invoice(40, Invoice::STATUS_UNPAID, '2026-10-30');
        $this->service(10, 'month', 1, '2026-11-05');

        $plan = IncomePlan::year('USD', 2026);
        $months = collect($plan['months'])->keyBy(fn ($row) => $row['month']->format('Y-m'));

        $this->assertCount(12, $plan['months']);
        $this->assertSame(['70.00', '0.00', '70.00', '50.00', '20.00'], [$months['2026-03']['received'], $months['2026-03']['expected'],
            $months['2026-03']['total'], $months['2026-03']['last_year'], $months['2026-03']['difference']]);
        $this->assertSame(['0.00', '0.00'], [$months['2026-09']['expected'], $months['2026-09']['total']], 'a month gone: its unpaid is overdue');
        $this->assertSame(['20.00', '40.00', '60.00'], [$months['2026-10']['received'], $months['2026-10']['expected'], $months['2026-10']['total']]);
        $this->assertSame(['10.00', '10.00'], [$months['2026-11']['expected'], $months['2026-12']['expected']]);
        $this->assertSame(['130.00', '150.00', '50.00'], [$months['2026-10']['cumulative'], $months['2026-12']['cumulative'], $months['2026-12']['cumulative_last_year']]);
        $this->assertSame(['90.00', '60.00', '150.00', '50.00', '100.00'], [$plan['totals']['received'], $plan['totals']['expected'],
            $plan['totals']['total'], $plan['totals']['last_year'], $plan['totals']['difference']]);
        $this->assertSame(['2027-01', '2027-02', '2027-03', '2027-04'], array_map(fn ($row) => $row['month']->format('Y-m'), $plan['after']));
        $this->assertSame('10.00', $plan['after'][0]['total']);
    }

    public function test_a_past_year_is_what_came_in_and_a_coming_one_what_is_expected(): void
    {
        $this->invoice(50, Invoice::STATUS_PAID, '2025-03-01', '2025-03-10 09:00:00');
        $this->invoice(25, Invoice::STATUS_PAID, '2024-03-01', '2024-03-10 09:00:00');
        $this->invoice(99, Invoice::STATUS_UNPAID, '2025-06-01');                     // never paid: nothing in 2025
        $this->service(120, 'year', 1, '2027-02-10');

        $past = IncomePlan::year('USD', 2025);
        $this->assertSame(['50.00', '0.00', '50.00', '25.00'], [$past['totals']['received'], $past['totals']['expected'], $past['totals']['total'], $past['totals']['last_year']]);
        $this->assertSame([], $past['after']);

        $next = IncomePlan::year('USD', 2027);
        $this->assertSame(['0.00', '120.00', '120.00'], [$next['totals']['received'], $next['totals']['expected'], $next['totals']['total']]);
        $this->assertSame('120.00', $next['months'][1]['renewals']);
        $this->assertSame([], $next['after']);
    }

    public function test_the_page_shows_the_year_and_another_year_without_this_months_cards(): void
    {
        config(['settings.default_currency' => 'USD']);
        $this->invoice(100, Invoice::STATUS_PAID, '2026-10-05', '2026-10-05 09:00:00');
        $this->invoice(30, Invoice::STATUS_UNPAID, '2026-10-30');
        $this->invoice(15, Invoice::STATUS_UNPAID, '2026-08-30');
        $this->invoice(80, Invoice::STATUS_PAID, '2025-10-05', '2025-10-05 09:00:00');
        $staff = Staff::factory()->withPermissions(['admin.reports.view'])->create();

        $this->actingAs($staff)->get(RevenueForecast::getUrl())->assertOk()
            ->assertSee(__('Income & forecast'))
            ->assertSeeInOrder([__('This year to :date', ['date' => '21/10']), '$100.00', __('All :year, if everyone pays', ['year' => 2026]), '$130.00'])
            ->assertSeeInOrder([__('Month by month'), 'October 2026', '$100.00', '$30.00', '$130.00', '$80.00', '+$50.00', '+63%'])
            ->assertSee(trans_choice('Overdue from earlier months: :amount (:count invoice)|Overdue from earlier months: :amount (:count invoices)', 1, ['amount' => '$15.00']))
            ->assertSee(__('After the end of the year'))
            ->assertSee(__('Forecast by billing cycle, the next 12 months'));

        $this->livewireAs($staff)->test(RevenueForecast::class)->set('year', 2025)
            ->assertDontSee(__('This year to :date', ['date' => '21/10']))
            ->assertDontSee(__('After the end of the year'))
            ->assertSeeInOrder([__('The whole year'), '$80.00'])
            ->callAction('year_csv')->assertFileDownloaded('income-USD-2025-2026-10-21.csv');
    }

    public function test_a_year_not_offered_falls_back_to_this_one(): void
    {
        $staff = Staff::factory()->withPermissions(['admin.reports.view'])->create();

        $page = $this->livewireAs($staff)->test(RevenueForecast::class)->set('year', 1999);

        $this->assertSame(2026, $page->instance()->chosenYear());
    }
}
