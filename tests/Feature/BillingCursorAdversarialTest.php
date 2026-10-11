<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Adversarial review of the billing cursor (docs/billing-cursor.md, PR #32).
 * Tests that FAIL on feat/billing-cursor are confirmed defects; passing ones are invariants that held.
 */
class BillingCursorAdversarialTest extends TestCase
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
        ]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = [], array $plan = []): Service
    {
        $product = $this->createProduct();
        if ($plan) {
            Plan::query()->whereKey($product->plan->id)->update($plan);
        }

        return Service::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => '2026-10-15',
            'currency_code' => 'USD',
            'price' => 10.00,
        ], $attributes));
    }

    /** @param array<int, array{0: ?string, 1: ?string}> $periods */
    private function invoiceWith(Service $service, array $periods, string $status = 'pending'): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => $status, 'currency_code' => 'USD']);
        foreach ($periods as [$start, $end]) {
            $invoice->items()->create([
                'reference_id' => $service->id,
                'reference_type' => Service::class,
                'description' => 'Renewal',
                'quantity' => 1,
                'price' => 10.00,
                'period_start' => $start,
                'period_end' => $end,
            ]);
        }

        return $invoice->fresh();
    }

    private function invoiceFor(Service $service, ?string $start, ?string $end, string $status = 'pending'): Invoice
    {
        return $this->invoiceWith($service, [[$start, $end]], $status);
    }

    private function pay(Invoice $invoice): void
    {
        $invoice->transactions()->create(['amount' => $invoice->remaining]);
    }

    private function expires(Service $service): string
    {
        return $service->fresh()->expires_at->toDateString();
    }

    private function pendingLines(Service $service)
    {
        return InvoiceItem::query()
            ->where('reference_type', Service::class)->where('reference_id', $service->id)
            ->whereHas('invoice', fn ($q) => $q->where('status', 'pending'))
            ->get();
    }

    // ---------------------------------------------------------------- confirmed-defect candidates

    /**
     * A paid period ahead of a cancelled one: invoicedUntil = max(period_end) jumps over the hole, so the
     * cron never bills the cancelled period again and the paid one waits forever.
     */
    public function test_a_cancelled_period_before_a_paid_ahead_period_is_billed_again(): void
    {
        $service = $this->service();
        $october = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $november = $this->invoiceFor($service, '2026-11-15', '2026-12-15');
        $this->pay($november); // waits for October
        $october->update(['status' => 'cancelled']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $lines = $this->pendingLines($service);
        $this->assertCount(1, $lines, 'October is owed again but the cron billed nothing');
        $this->assertSame('2026-10-15', $lines->first()->period_start->toDateString());
    }

    /**
     * Staff closes the gap by hand (writes October off, moves Next Due Date to 11-15). The paid November
     * line is never applied: nothing re-runs the sweep, and the cron will not bill November again either.
     */
    public function test_a_paid_ahead_period_is_applied_when_staff_closes_the_gap_by_hand(): void
    {
        $service = $this->service();
        $october = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $november = $this->invoiceFor($service, '2026-11-15', '2026-12-15');
        $this->pay($november);
        $october->update(['status' => 'cancelled']);
        $service->update(['expires_at' => '2026-11-15']); // October comped

        Carbon::setTestNow('2026-11-18 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame('active', $service->fresh()->status, 'November is paid, yet the service is suspended');
    }

    /**
     * The gap is paid by a line without a period (WHMCS import / pre-change invoice): the old rule adds
     * one period but does not sweep over the later period that is already paid.
     */
    public function test_a_gap_paid_by_a_line_without_period_also_applies_the_paid_ahead_period(): void
    {
        $service = $this->service();
        $november = $this->invoiceFor($service, '2026-11-15', '2026-12-15');
        $this->pay($november); // waits
        $october = $this->invoiceFor($service, null, null); // imported, no period

        $this->pay($october);

        $this->assertSame('2026-12-15', $this->expires($service));
    }

    /**
     * Staff moves Next Due Date (expires_at) forward; billingDay() still comes from the first period
     * (31st), so the next period runs 11-10 → 12-31 (51 days) for one month's price.
     */
    public function test_moving_next_due_date_forward_keeps_a_one_month_period(): void
    {
        Carbon::setTestNow('2026-11-05 10:00:00');
        $service = $this->service(['expires_at' => '2026-10-31']);
        $this->invoiceFor($service, '2026-08-31', '2026-09-30', 'paid');
        $this->invoiceFor($service, '2026-09-30', '2026-10-31', 'paid');
        $service->update(['expires_at' => '2026-11-10']); // staff: new Next Due Date

        $this->artisan('app:cron-job')->assertExitCode(0);

        $line = $this->pendingLines($service)->sole();
        $this->assertSame('2026-11-10', $line->period_start->toDateString());
        $this->assertSame('2026-12-10', $line->period_end->toDateString());
    }

    /**
     * Staff moves Next Due Date back (15th → 5th). nextPeriodStart = max(expires, invoicedUntil) ignores
     * it: nothing is billed for 11-05, and the service is suspended on 11-08 with no invoice to pay.
     */
    public function test_moving_next_due_date_back_inside_a_paid_period_does_not_bill_it_twice(): void
    {
        $service = $this->service(['expires_at' => '2026-11-15']);
        $this->invoiceFor($service, '2026-10-15', '2026-11-15', 'paid');
        $service->update(['expires_at' => '2026-11-05']);

        Carbon::setTestNow('2026-11-08 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);

        // 11-05..11-15 is already paid (the October line): billing it again would charge it twice. The cron
        // bills from 11-15, and paying that invoice follows the paid periods from 11-05 to 12-15 (next test).
        $line = $this->pendingLines($service)->sole();
        $this->assertSame('2026-11-15', $line->period_start->toDateString());
    }

    public function test_paying_the_invoice_the_cron_made_after_next_due_date_moved_back_renews(): void
    {
        $service = $this->service(['expires_at' => '2026-11-15']);
        $this->invoiceFor($service, '2026-10-15', '2026-11-15', 'paid');
        $service->update(['expires_at' => '2026-11-05']);

        Carbon::setTestNow('2026-11-08 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->pay($service->invoices()->where('status', 'pending')->sole());

        $service->refresh();
        $this->assertSame('active', $service->status, 'customer paid the only open invoice, still suspended');
        $this->assertTrue($service->expires_at->greaterThan(now()), 'paid invoice not applied: expires_at=' . $service->expires_at->toDateString());
    }

    /**
     * A payment that adds nothing (period already covered) still flips a manually suspended service to
     * active and dispatches UnsuspendJob.
     */
    public function test_a_payment_that_adds_nothing_does_not_unsuspend(): void
    {
        $service = $this->service(['expires_at' => '2026-11-15', 'status' => 'suspended']); // e.g. abuse
        $this->invoiceFor($service, '2026-10-15', '2026-11-15', 'paid');
        $duplicate = $this->invoiceFor($service, '2026-10-15', '2026-11-15');

        $this->pay($duplicate);

        $this->assertSame('2026-11-15', $this->expires($service));
        $this->assertSame('suspended', $service->fresh()->status);
    }

    // ---------------------------------------------------------------- invariants expected to hold

    public function test_two_periods_of_one_service_on_one_invoice_newer_first(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceWith($service, [['2026-11-15', '2026-12-15'], ['2026-10-15', '2026-11-15']]);

        $this->pay($invoice);

        $this->assertSame('2026-12-15', $this->expires($service));
    }

    public function test_the_same_period_twice_on_one_invoice_renews_once(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceWith($service, [['2026-10-15', '2026-11-15'], ['2026-10-15', '2026-11-15']]);

        $this->pay($invoice);

        $this->assertSame('2026-11-15', $this->expires($service));
    }

    public function test_a_cancelled_invoice_paid_late_does_not_double_renew_its_rebilled_period(): void
    {
        $service = $this->service();
        $this->artisan('app:cron-job')->assertExitCode(0);
        $first = $service->invoices()->sole();
        $first->update(['status' => 'cancelled']);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $second = $service->invoices()->where('status', 'pending')->sole();

        $this->pay($second);
        $this->pay($first->fresh()); // late gateway callback on the cancelled one

        $this->assertSame('2026-11-15', $this->expires($service));
    }

    public function test_first_order_on_the_31st_keeps_the_31st(): void
    {
        Carbon::setTestNow('2027-01-31 10:00:00');
        $service = $this->service(['status' => 'pending', 'expires_at' => null]);
        $this->pay($this->invoiceFor($service, null, null));
        $this->assertSame('2027-02-28', $this->expires($service));

        Carbon::setTestNow('2027-02-25 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
        $feb = $service->invoices()->where('status', 'pending')->sole();
        $this->assertSame('2027-03-31', $feb->items()->sole()->period_end->toDateString());
        $this->pay($feb);
        $this->assertSame('2027-03-31', $this->expires($service));
    }

    public function test_first_order_on_29_february_yearly(): void
    {
        Carbon::setTestNow('2028-02-29 10:00:00');
        $service = $this->service(['status' => 'pending', 'expires_at' => null], ['billing_unit' => 'year']);
        $this->pay($this->invoiceFor($service, null, null));
        $this->assertSame('2029-02-28', $this->expires($service));

        Carbon::setTestNow('2029-02-25 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
        $line = $this->pendingLines($service)->sole();
        $this->assertSame('2029-02-28', $line->period_start->toDateString());
        $this->assertSame('2030-02-28', $line->period_end->toDateString());
    }

    public function test_paying_a_first_order_invoice_twice_renews_once(): void
    {
        $service = $this->service(['status' => 'pending', 'expires_at' => null]);
        $invoice = $this->invoiceFor($service, null, null);
        $this->pay($invoice);
        // admin flips it back to pending and it gets paid again
        $invoice->fresh()->update(['status' => 'pending']);
        $invoice->fresh()->update(['status' => 'paid']);

        $this->assertSame('2026-11-12', $this->expires($service));
    }

    public function test_quantity_two_renews_one_period(): void
    {
        $service = $this->service(['quantity' => 2]);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $invoice = $service->invoices()->sole();
        $this->assertSame(2, (int) $invoice->items()->sole()->quantity);

        $this->pay($invoice);

        $this->assertSame('2026-11-15', $this->expires($service));
    }

    public function test_plan_changed_between_billing_and_payment_uses_the_billed_period(): void
    {
        $service = $this->service();
        $this->artisan('app:cron-job')->assertExitCode(0);
        Plan::query()->whereKey($service->plan_id)->update(['billing_unit' => 'year']);

        $this->pay($service->invoices()->sole());

        $this->assertSame('2026-11-15', $this->expires($service));
    }
}
