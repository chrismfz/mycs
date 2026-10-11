<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use MyCS\BillingPeriod\BillingPeriod;
use Tests\TestCase;

/** Renewal bound to invoice and period (docs/billing-cursor.md). */
class BillingCursorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 10:00:00');
        config(['settings.cronjob_invoice' => 7, 'settings.credits_enabled' => false]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct(); // monthly, 1 month

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

    private function invoiceFor(Service $service, ?string $start, ?string $end, string $status = 'pending'): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => $status, 'currency_code' => 'USD']);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'description' => 'Renewal',
            'quantity' => 1,
            'price' => 10.00,
            'period_start' => $start,
            'period_end' => $end,
        ]);

        return $invoice->fresh();
    }

    private function pay(Invoice $invoice): void
    {
        $invoice->transactions()->create(['amount' => $invoice->remaining]);
    }

    private function expires(Service $service): string
    {
        return $service->fresh()->expires_at->toDateString();
    }

    public function test_month_and_year_periods_keep_the_billing_day(): void
    {
        $this->assertSame('2026-02-28', BillingPeriod::end(CarbonImmutable::parse('2026-01-31'), 'month', 1)->toDateString());
        $this->assertSame('2026-03-31', BillingPeriod::end(CarbonImmutable::parse('2026-02-28'), 'month', 1, 31)->toDateString());
        $this->assertSame('2026-04-30', BillingPeriod::end(CarbonImmutable::parse('2026-03-31'), 'month', 1, 31)->toDateString());
        $this->assertSame('2025-02-28', BillingPeriod::end(CarbonImmutable::parse('2024-02-29'), 'year', 1)->toDateString());
        $this->assertSame('2028-02-29', BillingPeriod::end(CarbonImmutable::parse('2027-02-28'), 'year', 1, 29)->toDateString());
        $this->assertSame('2027-01-31', BillingPeriod::end(CarbonImmutable::parse('2026-10-31'), 'month', 3)->toDateString());
        $this->assertSame('2026-10-19', BillingPeriod::end(CarbonImmutable::parse('2026-10-12'), 'week', 1)->toDateString());
        $this->assertSame('2026-10-22', BillingPeriod::end(CarbonImmutable::parse('2026-10-12'), 'day', 10)->toDateString());
    }

    public function test_the_billing_day_only_applies_to_periods_that_start_on_it(): void
    {
        // A service billed on the 1st whose Next Due Date staff moved to the 20th bills 20th to 20th.
        $this->assertSame('2026-04-20', BillingPeriod::end(CarbonImmutable::parse('2026-03-20'), 'month', 1, 1)->toDateString());
        // Anchor 31 and a period starting mid-month: a plain month, not to the 31st.
        $this->assertSame('2026-03-15', BillingPeriod::end(CarbonImmutable::parse('2026-02-15'), 'month', 1, 31)->toDateString());
        // Anchor 31 clamped to the 30th still counts as the anchor.
        $this->assertSame('2026-05-31', BillingPeriod::end(CarbonImmutable::parse('2026-04-30'), 'month', 1, 31)->toDateString());
    }

    public function test_a_payment_never_revives_a_cancelled_service(): void
    {
        $service = $this->service(['status' => 'cancelled', 'expires_at' => '2026-03-25']);

        $this->pay($this->invoiceFor($service, null, null));

        $service->refresh();
        $this->assertSame('cancelled', $service->status); // staff decide (PR 3 notifies them)
        $this->assertSame('2026-03-25', $service->expires_at->toDateString());
        // A fresh start bills from today, never a 45-day period mixing today with the old 25th.
        $this->assertSame('2026-11-12', $service->calculateNextDueDate()->toDateString());
    }

    public function test_the_cron_writes_the_period_on_the_renewal_line(): void
    {
        $service = $this->service();

        $this->artisan('app:cron-job')->assertExitCode(0);

        $item = InvoiceItem::query()->where('reference_id', $service->id)->where('reference_type', Service::class)->sole();
        $this->assertSame('2026-10-15', $item->period_start->toDateString());
        $this->assertSame('2026-11-15', $item->period_end->toDateString());
        $this->assertStringContainsString('Oct 15, 2026 - Nov 15, 2026', $item->description);
        $this->assertSame('2026-10-15', $item->invoice->due_at->toDateString());
    }

    public function test_the_cron_never_bills_the_same_period_twice(): void
    {
        $service = $this->service();

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->artisan('app:cron-job')->assertExitCode(0);
        // Even once that invoice is paid, the next period is outside the window: nothing new.
        $this->pay($service->invoices()->sole());
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(1, $service->invoices()->count());
        $this->assertSame('2026-11-15', $this->expires($service));
    }

    public function test_a_cancelled_invoice_frees_its_period(): void
    {
        $service = $this->service();
        $this->artisan('app:cron-job')->assertExitCode(0);
        $service->invoices()->sole()->update(['status' => 'cancelled']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $live = $service->invoiceItems()->whereHas('invoice', fn ($q) => $q->where('status', 'pending'))->sole();
        $this->assertSame('2026-10-15', $live->period_start->toDateString());
    }

    public function test_paying_the_same_period_twice_renews_once(): void
    {
        $service = $this->service();
        $first = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $second = $this->invoiceFor($service, '2026-10-15', '2026-11-15');

        $this->pay($first);
        $this->pay($second);

        $this->assertSame('2026-11-15', $this->expires($service));
    }

    public function test_an_older_invoice_paid_after_a_newer_one_adds_nothing(): void
    {
        $service = $this->service();
        $older = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $newer = $this->invoiceFor($service, '2026-11-15', '2026-12-15');
        $this->pay($older);
        $this->pay($newer);

        $this->pay($this->invoiceFor($service, '2026-10-15', '2026-11-15')); // a stray re-billing of October

        $this->assertSame('2026-12-15', $this->expires($service));
    }

    public function test_a_period_paid_ahead_of_an_unpaid_one_waits_for_the_gap(): void
    {
        $service = $this->service(['status' => 'suspended']);
        $october = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $november = $this->invoiceFor($service, '2026-11-15', '2026-12-15');

        $this->pay($november);
        $this->assertSame('2026-10-15', $this->expires($service)); // October is still owed
        $this->assertSame('suspended', $service->fresh()->status);

        $this->pay($october);
        $this->assertSame('2026-12-15', $this->expires($service));
        $this->assertSame('active', $service->fresh()->status);
    }

    public function test_a_period_already_extended_by_hand_is_not_added_again(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $service->update(['expires_at' => '2026-11-15']); // staff renewed before the payment arrived

        $this->pay($invoice);

        $this->assertSame('2026-11-15', $this->expires($service));
    }

    public function test_a_suspended_service_renews_from_its_expiry(): void
    {
        $service = $this->service(['status' => 'suspended', 'expires_at' => '2026-09-20']);
        $invoice = $this->invoiceFor($service, '2026-09-20', '2026-10-20');

        $this->pay($invoice);

        $service->refresh();
        $this->assertSame('active', $service->status);
        $this->assertSame('2026-10-20', $service->expires_at->toDateString()); // not 2026-11-12
    }

    public function test_a_first_order_line_gets_its_period_when_paid(): void
    {
        $service = $this->service(['status' => 'pending', 'expires_at' => null]);
        $invoice = $this->invoiceFor($service, null, null);

        $this->pay($invoice);

        $item = $invoice->items()->sole();
        $this->assertSame('2026-10-12', $item->period_start->toDateString());
        $this->assertSame('2026-11-12', $item->period_end->toDateString());
        $this->assertSame('2026-11-12', $this->expires($service));
    }

    public function test_a_line_without_a_period_keeps_the_old_rule(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, null, null); // e.g. imported from WHMCS

        $this->pay($invoice);

        $this->assertSame('2026-11-15', $this->expires($service));
    }

    public function test_a_service_billed_on_the_31st_keeps_billing_on_the_31st(): void
    {
        Carbon::setTestNow('2027-01-28 10:00:00');
        $service = $this->service(['expires_at' => '2027-01-31']);
        $this->invoiceFor($service, '2026-12-31', '2027-01-31', 'paid'); // its first billed period

        $this->artisan('app:cron-job')->assertExitCode(0);
        $feb = $service->invoices()->where('status', 'pending')->sole();
        $this->assertSame('2027-02-28', $feb->items()->sole()->period_end->toDateString());
        $this->pay($feb);

        Carbon::setTestNow('2027-02-25 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
        $mar = $service->invoices()->where('status', 'pending')->sole();
        $this->assertSame('2027-03-31', $mar->items()->sole()->period_end->toDateString());
    }

    public function test_a_free_renewal_extends_from_the_expiry_without_an_invoice(): void
    {
        $service = $this->service(['price' => 0]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(0, $service->invoices()->count());
        $this->assertSame('2026-11-15', $this->expires($service));
    }

    // Round 3 (code review) — docs/billing-cursor.md

    public function test_staff_moving_the_date_forward_into_a_paid_period_picks_it_up(): void
    {
        $service = $this->service();
        $october = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $this->pay($this->invoiceFor($service, '2026-11-15', '2026-12-15')); // waits for October
        $october->update(['status' => 'cancelled']);

        $service->update(['expires_at' => '2026-11-20']); // staff comp into the paid month

        $this->assertSame('2026-12-15', $this->expires($service));
    }

    public function test_a_hole_shorter_than_a_period_is_billed_alone_and_prorated(): void
    {
        $service = $this->service(['price' => 30.00]);
        $october = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $this->pay($this->invoiceFor($service, '2026-11-15', '2026-12-15'));
        $october->update(['status' => 'cancelled']);
        $service->update(['expires_at' => '2026-10-31']); // comp half of October

        Carbon::setTestNow('2026-10-28 10:00:00'); // the hole 10-31..11-15 is due
        $this->artisan('app:cron-job')->assertExitCode(0);

        $line = $service->invoiceItems()->whereHas('invoice', fn ($q) => $q->where('status', 'pending'))->sole();
        $this->assertSame('2026-10-31', $line->period_start->toDateString());
        $this->assertSame('2026-11-15', $line->period_end->toDateString()); // not 11-30: no day billed twice
        $this->assertEquals(15.00, (float) $line->price); // 30 × 15/30 (the full period 10-31..11-30 has 30 days)
    }

    public function test_a_suspended_service_gets_its_hole_billed_before_a_paid_period(): void
    {
        $service = $this->service(['status' => 'suspended', 'expires_at' => '2026-10-15']);
        $this->pay($this->invoiceFor($service, '2026-11-15', '2026-12-15')); // paid ahead, stranded
        $this->invoiceFor($service, '2026-10-15', '2026-11-15', 'cancelled');

        $this->artisan('app:cron-job')->assertExitCode(0);

        $line = $service->invoiceItems()->whereHas('invoice', fn ($q) => $q->where('status', 'pending'))->sole();
        $this->assertSame('2026-10-15', $line->period_start->toDateString());
    }

    public function test_a_suspended_service_without_a_stranded_payment_is_not_billed_again(): void
    {
        $service = $this->service(['status' => 'suspended', 'expires_at' => '2026-09-15']);
        $this->invoiceFor($service, '2026-09-15', '2026-10-15', 'cancelled');

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(0, $service->invoices()->where('status', 'pending')->count()); // A2
    }

    public function test_a_payment_never_unsuspends_a_one_time_service(): void
    {
        $service = $this->service(['status' => 'suspended', 'expires_at' => null]);
        Plan::query()->whereKey($service->plan_id)->update(['type' => 'one-time']);

        $this->pay($this->invoiceFor($service, null, null));

        $this->assertSame('suspended', $service->fresh()->status);
    }

    public function test_a_first_order_line_text_gets_the_period_that_starts_when_paid(): void
    {
        $service = $this->service(['status' => 'pending', 'expires_at' => null]);
        $invoice = $this->invoiceFor($service, null, null);

        $this->pay($invoice);

        $this->assertStringContainsString('Oct 12, 2026 - Nov 12, 2026', $invoice->items()->sole()->description);
    }

    public function test_an_order_on_the_30th_keeps_the_30th_after_february(): void
    {
        $service = $this->service(['expires_at' => '2027-02-28']);
        $this->invoiceFor($service, '2026-11-30', '2026-12-30', 'paid');
        $this->invoiceFor($service, '2026-12-30', '2027-01-30', 'paid');
        $this->invoiceFor($service, '2027-01-30', '2027-02-28', 'paid');

        $this->assertSame('2027-03-30', $service->periodEnd(CarbonImmutable::parse('2027-02-28'))->toDateString());
    }

    public function test_one_failing_service_does_not_stop_the_cron(): void
    {
        $broken = $this->service();
        Plan::query()->whereKey($broken->plan_id)->update(['billing_unit' => 'hour']); // not whole days
        $fine = $this->service();

        try {
            Artisan::call('app:cron-job');
            $this->fail('The broken service was not reported');
        } catch (InvalidArgumentException) {
            // reported at the end of the run, after the other services were billed
        }

        $this->assertSame(1, $fine->invoices()->count());
        $this->assertSame(0, $broken->invoices()->count());
    }
}
