<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use App\Services\Service\RenewServiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Adversarial review, round 2, of the billing cursor (docs/billing-cursor.md, PR #32).
 * Tests that FAIL on feat/billing-cursor are confirmed defects; passing ones are invariants that held.
 */
class BillingCursorAdversarialRound2Test extends TestCase
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

    private function service(array $attributes = [], array $plan = []): Service
    {
        $product = $this->createProduct();
        if ($plan) {
            Plan::query()->whereKey($product->plan->id)->update($plan);
        }

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

    /** @param array<int, array{0: ?string, 1: ?string}> $periods */
    private function invoiceWith(Service $service, array $periods, string $status = 'unpaid'): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => $status === Invoice::STATUS_PAID ? Invoice::STATUS_UNPAID : $status, 'currency_code' => 'USD']);
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

        // A paid fixture (a period paid in the past): stored paid, its lines added while it was open (MT-1c: a paid
        // invoice keeps its lines, and is never created paid).
        if ($status === Invoice::STATUS_PAID) {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        }

        return $invoice->fresh();
    }

    private function invoiceFor(Service $service, ?string $start, ?string $end, string $status = 'unpaid'): Invoice
    {
        return $this->invoiceWith($service, [[$start, $end]], $status);
    }

    private function pay(Invoice $invoice): void
    {
        $invoice->transactions()->create(['amount' => $invoice->remaining]);
    }

    private function expires(Service $service): ?string
    {
        return $service->fresh()->expires_at?->toDateString();
    }

    private function pendingLines(Service $service)
    {
        return InvoiceItem::query()
            ->where('reference_type', Service::class)->where('reference_id', $service->id)
            ->whereHas('invoice', fn ($q) => $q->where('status', 'unpaid'))
            ->get();
    }

    // ---------------------------------------------------------------- confirmed-defect candidates

    /**
     * Two concurrent "paid" notifications for a first order: each ProcessPaidInvoiceService loaded the
     * invoice items before the other committed. The service row is locked, but the item is not re-read
     * under the lock: the second run sees a stale item with no period, the service already active with
     * an expiry, and takes the period-less path: one more month for free.
     */
    public function test_concurrent_first_order_processing_with_stale_items_renews_once(): void
    {
        $service = $this->service(['status' => 'pending', 'expires_at' => null]);
        $invoice = $this->invoiceFor($service, null, null, 'paid'); // created paid: no observer run
        $itemA = InvoiceItem::query()->where('invoice_id', $invoice->id)->sole();
        $itemB = InvoiceItem::query()->where('invoice_id', $invoice->id)->sole(); // loaded before A commits

        (new RenewServiceService)->handle(Service::find($service->id), $itemA);
        (new RenewServiceService)->handle(Service::find($service->id), $itemB);

        $this->assertSame('2026-11-12', $this->expires($service));
    }

    /**
     * One paid period-line invoice and one period-less (admin/manual/imported) invoice for the same
     * service, both paid. Paid in one order the customer gets two months, in the other only one: the
     * period-less line adds one period from the expiry, then the period line (Oct 15 - Nov 15) ends at
     * the cursor and adds nothing. Two payments, one month.
     */
    public function test_a_period_less_line_paid_before_a_period_line_does_not_swallow_a_payment(): void
    {
        $service = $this->service();
        $cron = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $manual = $this->invoiceFor($service, null, null);

        $this->pay($manual);
        $this->pay($cron);

        $this->assertSame('2026-12-15', $this->expires($service), 'paid twice, renewed once (reverse order gives 12-15)');
    }

    /**
     * Staff comps 10 days (Next Due Date 10-15 -> 10-25) while the October invoice is open. Paying it
     * follows the line that covers the cursor to its end (11-15): the 10 comp days are lost. The old rule
     * (and the period-less path) gives 11-25.
     */
    public function test_paying_after_staff_moved_the_date_inside_the_period_covers_the_period(): void
    {
        $service = $this->service();
        $october = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $service->update(['expires_at' => '2026-10-25']); // goodwill credit

        $this->pay($october);

        // Decision (docs/billing-cursor.md): a paid line covers its own period; it does not add a
        // period from a date staff moved inside it (the same rule that stops a double renewal when
        // staff extend before the payment arrives). To comp days, staff move the date after the payment.
        $this->assertSame('2026-11-15', $this->expires($service));
    }

    /**
     * Staff moves the Next Due Date back. Inside a paid period it is kept (decision, round 1); onto the
     * exact start of a paid period the observer silently re-extends it to the end of the chain, so a
     * correction to a period boundary (the usual case) cannot be saved.
     */
    public function test_staff_moving_the_date_back_onto_a_paid_period_start_is_kept_like_inside_a_period(): void
    {
        $service = $this->service(['expires_at' => '2026-12-15']);
        $this->invoiceFor($service, '2026-10-15', '2026-11-15', 'paid');
        $this->invoiceFor($service, '2026-11-15', '2026-12-15', 'paid');

        $service->update(['expires_at' => '2026-11-15']); // one correction back, onto a paid period's start
        $this->assertSame('2026-11-15', $this->expires($service), 'boundary correction overridden by the observer');

        $service->update(['expires_at' => '2026-11-14']);
        $this->assertSame('2026-11-14', $this->expires($service)); // back inside a period: kept
        // (Moving it forward again onto 11-15 picks the paid period up: docs/billing-cursor.md A3.)
    }

    /**
     * Decision A2 says: a long-suspended service that pays its one old invoice is re-suspended "without a
     * second invoice". But after the payment the service is ACTIVE with no pending invoice, and the cron
     * step invoices_created runs before services_suspended: it bills the next period in the same run.
     */
    public function test_a2_long_suspended_service_paying_old_invoice_gets_one_invoice_for_the_next_period(): void
    {
        Carbon::setTestNow('2026-11-25 10:00:00');
        $service = $this->service(['status' => 'suspended', 'dunning_suspended_at' => now()]);
        $october = $this->invoiceFor($service, '2026-10-15', '2026-11-15');

        $this->pay($october);
        $this->assertSame('2026-11-15', $this->expires($service));
        $this->assertSame('active', $service->fresh()->status);

        $this->artisan('app:cron-job')->assertExitCode(0);

        // A2 (docs/billing-cursor.md): the old invoice is paid, so November is a new unpaid period with its
        // one invoice; the service is suspended until it is paid (or staff adjust the date).
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertSame('2026-11-15', $this->pendingLines($service)->sole()->period_start->toDateString());
    }

    /**
     * A renewal invoice set to draft (staff edits it) is a live period for nextPeriodStart but not a
     * "pending invoice" for the cron's guard: the cron bills the NEXT period while the current one is
     * unbilled. Paying that next invoice adds nothing (hole), so the customer pays and is suspended.
     */
    public function test_a_draft_renewal_invoice_blocks_the_cron_like_a_pending_one(): void
    {
        $service = $this->service(['suspend_hold_until' => '2026-12-31']); // staff hold while the draft is fixed
        $this->invoiceFor($service, '2026-10-15', '2026-11-15', 'draft');

        Carbon::setTestNow('2026-11-09 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertCount(0, $this->pendingLines($service), 'cron billed 11-15..12-15 while October is a draft');
    }

    /**
     * billingDay() comes from the OLDEST live period. A service whose billing day later became the 30th
     * (WHMCS import, or staff moved it) is pulled back to the 31st whenever a period starts on a 30th
     * in a 30-day month: Nov 30 -> Dec 31 instead of Dec 30.
     */
    public function test_billing_day_follows_the_latest_period_not_the_first_one(): void
    {
        Carbon::setTestNow('2026-11-25 10:00:00');
        $service = $this->service(['expires_at' => '2026-11-30']);
        $this->invoiceFor($service, '2019-01-31', '2019-02-28', 'paid'); // years ago, billed on the 31st
        $this->invoiceFor($service, '2026-10-30', '2026-11-30', 'paid'); // now billed on the 30th

        $this->artisan('app:cron-job')->assertExitCode(0);

        $line = $this->pendingLines($service)->sole();
        $this->assertSame('2026-11-30', $line->period_start->toDateString());
        $this->assertSame('2026-12-30', $line->period_end->toDateString());
    }

    /**
     * Perf: per service the cron loads the whole period history twice (nextPeriodStart, then periodEnd ->
     * billingDay), each with its eager invoice query; renewal loads it 3-4 times (paidThrough, billingDay,
     * the observer's paidThrough).
     */
    public function test_the_cron_reads_the_period_history_once_per_service(): void
    {
        $service = $this->service();
        $this->invoiceFor($service, '2026-09-15', '2026-10-15', 'paid');

        $selects = 0;
        DB::listen(function ($query) use (&$selects) {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'period_start')) {
                $selects++;
            }
        });
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertLessThanOrEqual(1, $selects, "period history read {$selects} times for one service");
    }

    // ---------------------------------------------------------------- invariants expected to hold

    public function test_a_pending_service_paying_a_line_with_a_period_starts_from_that_period(): void
    {
        $service = $this->service(['status' => 'pending', 'expires_at' => null]);
        $this->pay($this->invoiceFor($service, '2026-10-12', '2026-11-12'));

        $this->assertSame('2026-11-12', $this->expires($service));
        $this->assertSame('active', $service->fresh()->status);
    }

    public function test_a_zero_length_period_adds_nothing_and_terminates(): void
    {
        $service = $this->service();
        $this->pay($this->invoiceFor($service, '2026-10-15', '2026-10-15'));

        $this->assertSame('2026-10-15', $this->expires($service));
    }

    public function test_overlapping_paid_lines_chain_to_the_furthest_end(): void
    {
        $service = $this->service();
        $this->invoiceFor($service, '2026-10-20', '2026-11-20', 'paid');
        $this->pay($this->invoiceFor($service, '2026-10-15', '2026-11-15'));

        $this->assertSame('2026-11-20', $this->expires($service));
    }

    public function test_a_free_plan_pending_service_activates_without_expiry(): void
    {
        $service = $this->service(['status' => 'pending', 'expires_at' => null], ['type' => 'free']);
        $this->pay($this->invoiceFor($service, null, null));

        $this->assertNull($this->expires($service));
        $this->assertSame('active', $service->fresh()->status);
    }

    public function test_observer_skips_a_service_whose_plan_is_gone(): void
    {
        $service = $this->service();
        $this->invoiceFor($service, '2026-11-15', '2026-12-15', 'paid');
        // As a delete before BIL-6 left it (the foreign key now refuses the delete).
        Schema::withoutForeignKeyConstraints(fn () => DB::table('services')->where('id', $service->id)->update(['plan_id' => null]));

        $service->fresh()->update(['expires_at' => '2026-11-15']);

        $this->assertSame('2026-11-15', $this->expires($service));
    }

    public function test_expiry_with_a_time_part_picks_up_the_paid_period(): void
    {
        $service = $this->service();
        $this->invoiceFor($service, '2026-11-15', '2026-12-15', 'paid');

        $service->update(['expires_at' => '2026-11-15 13:45:00']);

        $this->assertSame('2026-12-15', $this->expires($service));
    }

    public function test_cron_credit_auto_pay_renews_once_after_commit(): void
    {
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
        $this->user->client()->credits()->create(['currency_code' => 'USD', 'amount' => 100]);
        $service = $this->service();

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame('2026-11-15', $this->expires($service));
        $this->assertSame('paid', $service->invoices()->sole()->status);
    }
}
