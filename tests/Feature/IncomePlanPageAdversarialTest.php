<?php

namespace Tests\Feature;

use App\Admin\Pages\RevenueForecast;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Reports\IncomePlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #501 (IncomePlan::year and the «Income & forecast» page): the same figure, read from two places
 * (two years' views, before and after a payment, the table and its sign), should agree. Each test fails on f0d2169e.
 */
class IncomePlanPageAdversarialTest extends TestCase
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
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive', 'settings.default_currency' => 'USD']);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(float $price, string $unit, int $period, string $expires): Service
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'p',
            'billing_unit' => $unit, 'billing_period' => $period, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return Service::factory()->create(['client_id' => $this->user->client()->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => $price, 'quantity' => 1, 'expires_at' => $expires]);
    }

    /** An invoice of one line (net $price, 24% on top), in $status; paid ones on $paidAt. */
    private function invoice(float $price, string $status, ?string $due = null, ?string $paidAt = null, array $line = []): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID,
            'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create($line + ['description' => 'x', 'quantity' => 1, 'price' => $price, 'tax_rate' => '24']);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status, 'paid_at' => $paidAt]);

        return $invoice->fresh();
    }

    private function staff(): Staff
    {
        return Staff::factory()->withPermissions(['admin.reports.view'])->create();
    }

    /**
     * ADV-501-1 (medium). January 2027 read in two views. A monthly $10 service whose 25 October renewal was given up on
     * (invoice cancelled, line forgone; the service still active until the suspension runs): 2026's view shows nothing
     * from it after the end of the year (its «after» row for January: 0.00, as the author's test of #500 says). 2027's
     * view of the same January shows $10.00: IncomePlan::invoicedPeriods only reads lines whose period ends after
     * from − 32 days, so from 2027-01-01 the forgone period [25 Oct, 25 Nov) is not seen and the renewals go on.
     * Expected: 0.00 in both. Fix: read the forgone lines regardless of the window (a forgone period ends everything
     * after it), or start renewalCells' skip window at the service's own due date.
     */
    public function test_a_renewal_given_up_on_ends_the_service_in_next_years_view_too(): void
    {
        $service = $this->service(10, 'month', 1, '2026-10-25');
        $invoice = $this->invoice(10, Invoice::STATUS_CANCELLED, '2026-10-25', null,
            ['reference_type' => Service::class, 'reference_id' => $service->id, 'period_start' => '2026-10-25', 'period_end' => '2026-11-25']);
        DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['forgone_at' => '2026-10-20 10:00:00']);

        $this->assertSame('0.00', IncomePlan::year('USD', 2026)['after'][0]['expected'], 'January 2027 in 2026\'s view');
        $this->assertSame('0.00', IncomePlan::year('USD', 2027)['months'][0]['expected'], 'January 2027 in 2027\'s view');
        $this->assertSame('0.00', IncomePlan::year('USD', 2027)['totals']['expected']);
    }

    /**
     * ADV-501-2 (medium). «All 2026, if everyone pays» moves when someone pays. An open invoice of 200 net (248 gross)
     * due 30 October, a quarter of it (62) paid on 15 October: the year's total is 150 (expected: what is left; the 62
     * already in is received nowhere, since «received» is only invoices paid in full). When the customer pays the other
     * 186 the invoice is received in October, 200 net, and the same total becomes 200. Expected: 200.00 before and after
     * (the part paid counted as received in the month it came in, or the open invoice expected in full with its paid
     * part as received). Same with a partial payment on an overdue invoice: it is in no month until paid off.
     */
    public function test_the_years_total_if_everyone_pays_does_not_move_when_the_rest_is_paid(): void
    {
        // Left as is by the owner's choice (2026-10-28, docs/income-planning.md «Μερικώς πληρωμένο»): received by the
        // invoice paid in full, so that it agrees with the accountant's Income report; the part paid shows when it is.
        $this->markTestIncomplete('ADV-501-2: a partial payment is received with its invoice, by the owner\'s choice (2026-10-28).');
        $invoice = $this->invoice(200, Invoice::STATUS_UNPAID, '2026-10-30');
        Carbon::setTestNow('2026-10-15 10:00:00');
        (new RecordPaymentService)->handle($invoice, null, 62.0, transactionId: 'wire-1');
        Carbon::setTestNow('2026-10-21 10:00:00');

        $before = IncomePlan::year('USD', 2026)['totals']['total'];

        (new RecordPaymentService)->handle($invoice->fresh(), null, 186.0, transactionId: 'wire-2');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $after = IncomePlan::year('USD', 2026)['totals']['total'];

        $this->assertSame('200.00', $after);
        $this->assertSame($after, $before, 'the year if everyone pays, before and after the rest is paid');
    }

    /**
     * ADV-501-3 (low, a design question). 2026 read in two views. In 2026's view December 2026 is 0 received + 50
     * expected = 50.00 and the whole year 150.00 «if everyone pays». In 2027's view the «2026» column (same month last
     * year, cumulative last year, the chart's grey bars) is received only: December 2026 = 0.00, the year 100.00, so
     * every 2027 month after today compares a forecast with an unfinished month (+100% / «—»). Expected: a month a year
     * before that has not ended counts as its own view counts it (received + expected), or the page says «received so
     * far» for it.
     */
    public function test_next_years_comparison_reads_this_year_as_this_years_view_does(): void
    {
        $this->invoice(100, Invoice::STATUS_PAID, '2026-03-01', '2026-03-10 09:00:00');
        $this->invoice(50, Invoice::STATUS_UNPAID, '2026-12-10');

        $this->assertSame('150.00', IncomePlan::year('USD', 2026)['totals']['total']);
        $next = IncomePlan::year('USD', 2027);
        $this->assertSame('50.00', $next['months'][11]['last_year'], 'December 2026 as 2027 compares with it');
        $this->assertSame('150.00', $next['totals']['last_year']);
    }

    /**
     * ADV-501-4 (low). A difference below last year shows «$-30.00» beside «+$50.00» for a rise: the sign after the
     * currency's prefix (Price::format of a negative), in the table, the «to date» row and the whole year. Expected:
     * «-$30.00» (the sign first, as the + of a rise). Fix: $signed = sign . $money(abs($amount)).
     */
    public function test_a_fall_is_signed_before_the_currency_as_a_rise(): void
    {
        $this->invoice(50, Invoice::STATUS_PAID, '2026-03-01', '2026-03-10 09:00:00');
        $this->invoice(80, Invoice::STATUS_PAID, '2025-03-01', '2025-03-10 09:00:00');

        $this->livewireAs($this->staff())->test(RevenueForecast::class)->set('year', 2026)
            ->assertSeeInOrder(['March 2026', '$50.00', '$80.00', '-$30.00', '-38%'])
            ->assertDontSee('$-30.00');
    }

    /**
     * ADV-501-5 (low). A month 0.4% below last year (99.60 against 100.00) shows «-0%» in red: round(-0.4) is -0.0,
     * printed «-0». Expected: «0%». Fix: compare the rounded change, `$change == 0 ? 0 : $change` (or (int) round()).
     */
    public function test_a_change_that_rounds_to_zero_is_not_minus_zero(): void
    {
        $this->invoice(99.60, Invoice::STATUS_PAID, '2026-03-01', '2026-03-10 09:00:00');
        $this->invoice(100, Invoice::STATUS_PAID, '2025-03-01', '2025-03-10 09:00:00');

        $this->livewireAs($this->staff())->test(RevenueForecast::class)->set('year', 2026)
            ->assertSeeInOrder(['March 2026', '$99.60', '$100.00', '-$0.40', '0%'])
            ->assertDontSee('-0%');
    }

    /**
     * ADV-501-6 (low). A year not offered (tampered Livewire input, 1999) falls back to this year's figures, but the
     * property keeps 1999, so the year <select> (wire:model="year") matches no option and the browser shows the first one,
     * 2027, above 2026's figures. Expected: the property set back to the year shown (an updatedYear() that normalises,
     * and the same for currency, which code() falls back the same way).
     */
    public function test_a_year_not_offered_is_set_back_to_the_year_shown(): void
    {
        $page = $this->livewireAs($this->staff())->test(RevenueForecast::class)->set('year', 1999)->set('currency', 'XXX');

        $this->assertSame(2026, $page->instance()->chosenYear());
        $this->assertSame(2026, (int) $page->get('year'), 'the select shows the year the figures are of');
        $this->assertSame('USD', $page->get('currency'), 'the select shows the currency the figures are in');
    }
}
