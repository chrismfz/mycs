<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Round 3 review of PR #32 (docs/billing-cursor.md), accountant's view: every euro paid turns into
 * exactly the service time it bought, and no unpaid time is given away. A FAILING test is a confirmed
 * mismatch; each docblock gives the ledger (money in / money out / days granted).
 */
class BillingCursorMoneyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 10:00:00');
        config([
            'settings.cronjob_invoice' => 7,
            'settings.credits_enabled' => false,
            'settings.cronjob_order_suspend' => 2,
            'settings.cronjob_order_terminate' => 14,
        ]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(float $price = 10.00): object
    {
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => $price]);

        return $product;
    }

    private function service(array $attributes = [], ?object $product = null): Service
    {
        $product ??= $this->product();

        return Service::factory()->create(array_merge([
            'client_id' => $this->user->client()->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => '2026-10-15',
            'currency_code' => 'USD',
            'price' => 10.00,
        ], $attributes));
    }

    private function invoiceFor(Service $service, ?string $start, ?string $end, string $status = 'unpaid', float $price = 10.00): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => $status === Invoice::STATUS_PAID ? Invoice::STATUS_UNPAID : $status, 'currency_code' => 'USD']);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'description' => 'Renewal',
            'quantity' => 1,
            'price' => $price,
            'period_start' => $start,
            'period_end' => $end,
        ]);

        // A paid fixture (a period paid in the past): stored paid, its lines added while it was open (MT-1c: a paid
        // invoice keeps its lines, and is never created paid).
        if ($status === Invoice::STATUS_PAID) {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        }

        return $invoice->fresh();
    }

    private function pay(Invoice $invoice, ?float $amount = null): void
    {
        $invoice->transactions()->create(['amount' => $amount ?? $invoice->fresh()->remaining]);
    }

    private function expires(Service $service): ?string
    {
        return $service->fresh()->expires_at?->toDateString();
    }

    private function serviceLines(Service $service)
    {
        return InvoiceItem::query()
            ->where('reference_type', Service::class)->where('reference_id', $service->id)
            ->get()->sortBy(fn ($l) => $l->period_start?->timestamp ?? 0)->values();
    }

    private function cronOn(string $now): void
    {
        Carbon::setTestNow($now);
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    private function coupon(float $percent, int $recurring): Coupon
    {
        return Coupon::create([
            'type' => 'percentage', 'applies_to' => 'all', 'code' => 'C' . $percent . 'x' . $recurring,
            'value' => $percent, 'recurring' => $recurring,
        ]);
    }

    // ---------------------------------------------------------------- confirmed-mismatch candidates

    /**
     * Coupon "50% for 2 billing cycles". Cart stores the discounted price on the service (recurring >= 2).
     * The cron only recalculates the price when invoices()->count()+1 == recurring, and calculatePrice()
     * then still sees paid+1 <= recurring and keeps the discount; from cycle 3 on nothing recalculates.
     * Ledger: cycle 3 should bill 10.00; it bills 5.00, and every cycle after it too (discount forever).
     */
    public function test_a_two_cycle_coupon_stops_discounting_on_the_third_cycle(): void
    {
        $service = $this->service(['price' => 5.00, 'coupon_id' => $this->coupon(50, 2)->id]);
        $this->invoiceFor($service, '2026-09-15', '2026-10-15', 'paid', 5.00); // cycle 1, the order

        $this->cronOn('2026-10-12 10:00:00'); // cycle 2: discounted, correct
        $this->pay($service->invoices()->where('status', 'unpaid')->sole());
        $this->cronOn('2026-11-12 10:00:00'); // cycle 3: full price

        $prices = $this->serviceLines($service)->pluck('price')->map(fn ($p) => (string) $p)->all();
        $this->assertSame(['5.00', '5.00', '10.00'], $prices, 'coupon for 2 cycles discounted cycle 3');
    }

    /**
     * Coupon "100% for 2 billing cycles": the order has total 0, so no invoice; the service price is 0 and
     * every cron run renews for free without an invoice. The coupon counter counts invoices, so it never
     * moves: the service is free forever. Ledger: money in 0, days granted unbounded (3+ periods here).
     */
    public function test_a_two_cycle_free_coupon_bills_the_third_cycle(): void
    {
        $service = $this->service(['price' => 0, 'coupon_id' => $this->coupon(100, 2)->id, 'expires_at' => '2026-11-12']);
        // cycle 1 was the free order (no invoice); cycle 2:
        $this->cronOn('2026-11-08 10:00:00');
        $this->assertSame('2026-12-12', $this->expires($service));
        // cycle 3 must be billed at full price
        $this->cronOn('2026-12-08 10:00:00');

        $this->assertSame('2026-12-12', $this->expires($service), 'third cycle granted for free');
        $this->assertSame('10.00', (string) $this->serviceLines($service)->sole()->price);
    }

    // «A downgrade that drops a part-paid renewal below the paid amount» (incomplete since the fork) left on 2026-10-27:
    // a line of an invoice with a payment on it keeps its price (R9, ChangeOpenLine; UpgradeLifecycleTest), and a total
    // staff bring below what was paid is flagged (MoneyCheck open_settled) and closed with «Close as paid» (2b).

    /**
     * The cron's upgrade_invoices_updated step sets the price of EVERY line on the upgrade's invoice to the
     * prorated upgrade amount. A renewal line on the same invoice (API / admin) is repriced too.
     * Ledger: the renewal line 10.00 becomes ~1.00, and paying it still grants the full period.
     */
    public function test_the_upgrade_reprice_step_leaves_a_renewal_line_on_the_same_invoice_alone(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $bigger = $this->product(20.00);
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id, 'plan_id' => $bigger->plan->id, 'product_id' => $bigger->product->id,
            'status' => 'pending', 'invoice_id' => $invoice->id,
        ]);
        $invoice->items()->create([
            'reference_id' => $upgrade->id, 'reference_type' => ServiceUpgrade::class,
            'description' => 'Upgrade', 'quantity' => 1, 'price' => 1.00,
        ]);

        $this->cronOn('2026-10-12 10:00:00');

        $renewal = $invoice->items()->where('reference_type', Service::class)->sole();
        $this->assertSame('10.00', (string) $renewal->price, 'renewal line repriced to the upgrade proration');
    }

    /**
     * Upgrade proration counts |expires_at - today|: for an overdue service (grace days, or a staff
     * suspend hold) that is the days SINCE expiry, i.e. days of the unpaid renewal period. A downgrade then
     * credits the customer for days nobody paid (and an upgrade charges them on top of the renewal, whose
     * line ServiceUpgradeService reprices to the new plan). Ledger: credit 3.33 out for 0 days paid.
     */
    public function test_a_downgrade_of_an_overdue_service_credits_nothing_for_unpaid_days(): void
    {
        $service = $this->service(['expires_at' => '2026-09-22', 'suspend_hold_until' => '2026-12-31']);
        $this->invoiceFor($service, '2026-09-22', '2026-10-22'); // the unpaid renewal
        $cheap = $this->product(5.00);
        $downgrade = ServiceUpgrade::create([
            'service_id' => $service->id, 'plan_id' => $cheap->plan->id, 'product_id' => $cheap->product->id,
            'status' => 'pending', 'invoice_id' => null,
        ]);

        $this->assertGreaterThanOrEqual(0, $downgrade->calculatePrice()->price, 'credit for days after expiry (unpaid)');
    }

    /**
     * Staff switch a monthly service to yearly while its October renewal is open and correct the open
     * line's price to the yearly amount (the period fields are not editable in the admin). The line still
     * says Oct 15 - Nov 15: the customer pays a year and gets a month. Before this PR the payment added a
     * period of the CURRENT plan (a year). Introduced by binding the period at billing time.
     * Ledger: money in 100.00 (yearly), days granted 31.
     */
    public function test_a_yearly_price_paid_on_a_line_billed_monthly_buys_a_year(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $yearly = Plan::factory()->create([
            'priceable_id' => $service->product_id, 'priceable_type' => Product::class,
            'name' => 'Yearly', 'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring',
        ]);
        $service->update(['plan_id' => $yearly->id, 'price' => 100.00]);
        $invoice->items()->sole()->update(['price' => 100.00]);

        $this->pay($invoice);

        $this->assertSame('2027-10-15', $this->expires($service), 'paid 100.00 (a year), granted a month');
    }

    /**
     * Staff activate a new order by hand before it is paid and set its Next Due Date one period out (the
     * first month). The first-order line has no period, so paying it takes "the first period no live
     * invoice covers" FROM that date: the order buys a second month. The same manual-renewal-before-payment
     * double renewal A3 prevents for lines with a period. Ledger: money in 1 period, days granted 2.
     */
    public function test_paying_a_first_order_staff_already_activated_buys_one_period(): void
    {
        $service = $this->service(['status' => 'pending', 'expires_at' => null]);
        $order = $this->invoiceFor($service, null, null);
        $service->update(['status' => 'active', 'expires_at' => '2026-11-12']); // staff: "active, first month"

        $this->pay($order);

        $this->assertSame('2026-11-12', $this->expires($service), 'one payment, two months');
    }

    // «An invoice created paid renews its service line» (incomplete since the fork) left on 2026-10-27: an invoice is
    // never created paid (MT-1c, InvoiceObserver::creating; StaffCorrectionsAdversarialTest::
    // test_an_invoice_is_never_created_paid), so paid always follows a recorded payment.

    // ---------------------------------------------------------------- invariants expected to hold

    public function test_quantity_three_bills_three_units_and_grants_one_period(): void
    {
        $service = $this->service(['quantity' => 3]);
        $this->cronOn('2026-10-12 10:00:00');
        $invoice = $service->invoices()->sole();
        $this->assertEquals(30.00, $invoice->total);

        $this->pay($invoice);

        $this->assertSame('2026-11-15', $this->expires($service));
    }

    public function test_recurring_amount_edited_after_billing_keeps_the_billed_price_and_period(): void
    {
        $service = $this->service();
        $this->cronOn('2026-10-12 10:00:00');
        $service->update(['price' => 12.00]);

        $invoice = $service->invoices()->sole();
        $this->assertEquals(10.00, $invoice->total);
        $this->pay($invoice);
        $this->assertSame('2026-11-15', $this->expires($service));

        $this->cronOn('2026-11-12 10:00:00');
        $this->assertSame('12.00', (string) $this->serviceLines($service)->last()->price);
    }

    public function test_partial_payments_grant_the_period_only_once_fully_paid(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');

        $this->pay($invoice, 4.00);
        $this->assertSame('2026-10-15', $this->expires($service));
        $this->pay($invoice, 6.00);

        $this->assertSame('2026-11-15', $this->expires($service));
    }

    public function test_one_invoice_two_services_each_get_their_own_period(): void
    {
        $a = $this->service();
        $b = $this->service(['expires_at' => '2026-10-20']);
        $invoice = $this->invoiceFor($a, '2026-10-15', '2026-11-15');
        $invoice->items()->create([
            'reference_id' => $b->id, 'reference_type' => Service::class, 'description' => 'B',
            'quantity' => 1, 'price' => 10.00, 'period_start' => '2026-10-20', 'period_end' => '2026-11-20',
        ]);

        $this->pay($invoice->fresh());

        $this->assertSame('2026-11-15', $this->expires($a));
        $this->assertSame('2026-11-20', $this->expires($b));
    }

    public function test_admin_flipping_paid_pending_paid_renews_once(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $this->pay($invoice);
        $invoice->fresh()->update(['status' => 'unpaid']);
        $invoice->fresh()->update(['status' => 'paid']);

        $this->assertSame('2026-11-15', $this->expires($service));
    }

    public function test_renewal_and_upgrade_on_one_invoice_renew_once_and_upgrade(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $bigger = $this->product(20.00);
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id, 'plan_id' => $bigger->plan->id, 'product_id' => $bigger->product->id,
            'status' => 'pending', 'invoice_id' => $invoice->id,
        ]);
        $invoice->items()->create([
            'reference_id' => $upgrade->id, 'reference_type' => ServiceUpgrade::class,
            'description' => 'Upgrade', 'quantity' => 1, 'price' => 1.00,
        ]);

        $this->pay($invoice->fresh());

        $this->assertSame('2026-11-15', $this->expires($service));
        $this->assertSame($bigger->plan->id, $service->fresh()->plan_id);
    }
}
