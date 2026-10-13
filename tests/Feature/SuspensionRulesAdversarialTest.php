<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of PR #63 (BIL-4, BIL-5, BIL-10): suspension on an overdue open invoice, a minimum time in
 * suspension before termination, one service per transaction.
 */
class SuspensionRulesAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        if (!str_contains($this->name(), 'suspendjob_is_after_commit')) {
            Queue::fake(); // that test needs a real queue: the fake records afterCommit jobs even when they roll back
        }
        Mail::fake();
        config([
            'settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_invoice' => 7,
            'settings.cronjob_suspend_requires_invoice' => true, // the review was of the invoice rule, now a setting (off by default, as WHMCS)
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = [], string $planType = 'recurring'): Service
    {
        $product = $this->createProduct();
        if ($planType !== 'recurring') {
            Plan::whereKey($product->plan->id)->update(['type' => $planType]);
        }

        return Service::factory()->create(array_merge([
            'user_id' => User::factory()->create()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ], $attributes));
    }

    private function invoice(Service $service, ?string $due, string $status = 'unpaid', string $type = Service::class): Invoice
    {
        // Issued on its due date (or now, if that is later): an invoice the customer had in hand.
        $issued = $due !== null && Carbon::parse($due)->isPast() ? Carbon::parse($due) : now();
        $invoice = Invoice::factory()->create(['user_id' => $service->user_id, 'status' => $status, 'currency_code' => 'USD', 'due_at' => $due, 'created_at' => $issued]);
        $invoice->items()->create(['reference_type' => $type, 'reference_id' => $service->id, 'description' => 'Line', 'quantity' => 1, 'price' => 10]);

        return $invoice;
    }

    private function cron(?string $now = null): void
    {
        if ($now !== null) {
            Carbon::setTestNow($now);
        }
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    private function noticesFor(Service $service): int
    {
        return EmailLog::where('subject', 'like', '%needs staff attention%')->where('body', 'like', "%services_suspended #{$service->id}:%")->count();
    }

    // ------------------------------------------------------------------ findings (fail on the PR)

    /**
     * A1. The notice tells staff «bill it». Staff remove the draft that held the renewal; the next run bills the
     * service with due_at = the period start (weeks ago) and suspends it in the same run: the customer is suspended
     * the moment they are first asked to pay — exactly what BIL-5 says must not happen.
     */
    public function test_a_renewal_billed_late_is_not_suspended_in_the_run_that_bills_it(): void
    {
        $service = $this->service(['expires_at' => '2026-10-01 00:00:00']);
        $draft = $this->invoice($service, '2026-10-01', 'draft');

        $this->cron();
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame(1, $this->noticesFor($service), 'staff told: past due with no open invoice');

        $draft->items()->delete();
        $draft->delete(); // staff act on the notice

        $this->cron('2026-10-21 03:00:00');
        $bill = $service->invoices()->where('invoices.status', 'unpaid')->sole();
        $this->assertSame('2026-10-01', $bill->due_at->toDateString(), 'renewal due at its period start, in the past');
        $this->assertSame('active', $service->fresh()->status, 'asked to pay a minute ago: not yet suspended');
    }

    /**
     * A2. The ids are picked without a lock; under the per-service lock only the status is re-checked. A payment that
     * commits in between (renews, invoice paid) is not seen: the paying customer is suspended, with no open invoice
     * left to lift it. The old code read with lockForUpdate and saw it.
     */
    public function test_a_payment_committed_after_selection_is_seen_under_the_lock(): void
    {
        $service = $this->service(['expires_at' => '2026-10-10 00:00:00']);
        $invoice = $this->invoice($service, '2026-10-10');

        $fired = false;
        DB::listen(function ($query) use (&$fired, $invoice, $service) {
            if (!$fired && str_contains($query->sql, 'due_at') && str_contains($query->sql, 'exists') && preg_match('/[`"]services[`"]/', $query->sql)) {
                $fired = true; // the payment lands right after the selection query
                DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid']);
                DB::table('services')->where('id', $service->id)->update(['expires_at' => '2026-11-10 00:00:00']);
            }
        });

        $this->cron();

        $this->assertTrue($fired, 'hook reached the suspension selection');
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('active', $service->fresh()->status, 'paid until 2026-11-10: must not be suspended');
    }

    /** A2b. Same window: staff put a hold (suspend_hold_until) after selection; it is not re-checked either. */
    public function test_a_hold_set_after_selection_is_honoured(): void
    {
        $service = $this->service(['expires_at' => '2026-10-10 00:00:00']);
        $this->invoice($service, '2026-10-10');

        $fired = false;
        DB::listen(function ($query) use (&$fired, $service) {
            if (!$fired && str_contains($query->sql, 'due_at') && str_contains($query->sql, 'exists') && preg_match('/[`"]services[`"]/', $query->sql)) {
                $fired = true;
                DB::table('services')->where('id', $service->id)->update(['suspend_hold_until' => '2026-10-30']);
            }
        });

        $this->cron();

        $this->assertTrue($fired);
        $this->assertSame('active', $service->fresh()->status, 'on hold until 2026-10-30');
    }

    /**
     * A3. An open invoice with no due date (API: due_at nullable; the factory default) blocks the renewal (billRenewal
     * sees an open invoice), never counts as overdue, and suppresses the notice (an open invoice exists): the service
     * runs for free, forever, and nobody is told.
     */
    public function test_an_open_invoice_without_a_due_date_is_not_a_silent_free_service(): void
    {
        $service = $this->service(['expires_at' => '2026-07-01 00:00:00']); // 3.5 months past due
        $this->invoice($service, null)->forceFill(['created_at' => '2026-07-01 00:00:00'])->save(); // issued with the period, long ago

        $this->cron();

        $this->assertSame(1, $service->invoices()->count(), 'no renewal billed: the open invoice blocks it');
        $suspended = $service->fresh()->status === 'suspended';
        $this->assertTrue($suspended || $this->noticesFor($service) > 0, 'neither suspended nor reported to staff');
    }

    /** A4 (PAY-9, out of scope of this PR): a renewal paid with a processing (SEPA) payment is still suspended. */
    public function test_a_processing_payment_keeps_the_service_running(): void
    {
        $service = $this->service(['expires_at' => '2026-10-10 00:00:00']);
        $invoice = $this->invoice($service, '2026-10-10');
        DB::table('invoice_transactions')->insert(['invoice_id' => $invoice->id, 'amount' => 10, 'status' => InvoiceTransactionStatus::Processing->value,
            'transaction_id' => 'sepa_1', 'created_at' => now(), 'updated_at' => now()]);

        $this->cron();

        $this->assertSame('active', $service->fresh()->status, 'the money is on its way');
    }

    /**
     * A5. dunning_suspended_at is stamped mid-run; the next runs compare it against their own wall clock. A run that
     * reaches the termination step a few seconds earlier in the day than the suspension step did terminates a day late.
     */
    public function test_termination_is_day_granular(): void
    {
        $service = $this->service(['status' => 'suspended', 'expires_at' => '2026-10-01 00:00:00', 'dunning_suspended_at' => '2026-10-08 03:00:40']);

        $this->cron('2026-10-20 03:00:00'); // 12 days (14 - 2) after the day it was suspended

        $this->assertSame('terminated', $service->fresh()->status);
    }

    /** A7 (pre-existing): a hold «until today» (the admin form allows today) holds nothing: it ends at midnight. */
    public function test_a_hold_until_today_holds_today(): void
    {
        $service = $this->service(['expires_at' => '2026-10-01 00:00:00', 'suspend_hold_until' => '2026-10-20']);
        $this->invoice($service, '2026-10-01');

        $this->cron();

        $this->assertSame('active', $service->fresh()->status);
    }

    /** A6. A free-plan service whose date has passed is «renewed» by the cron without moving it: a notice every run, forever. */
    public function test_a_free_service_is_not_reported_every_run(): void
    {
        $service = $this->service(['expires_at' => '2026-10-01 00:00:00', 'price' => 0], 'free');

        $this->cron();
        $this->cron('2026-10-21 03:00:00');
        $this->cron('2026-10-22 03:00:00');

        $this->assertSame('active', $service->fresh()->status);
        $this->assertLessThanOrEqual(1, $this->noticesFor($service), 'the same notice every day');
    }

    // ------------------------------------------------------------------ holds

    public function test_holds_staff_unsuspend_then_resuspension_restarts_the_minimum_suspension(): void
    {
        $service = $this->service(['status' => 'suspended', 'expires_at' => '2026-09-01 00:00:00', 'dunning_suspended_at' => '2026-09-05 03:00:00']);
        $this->invoice($service, '2026-09-01');
        $service->applyStaffChanges(['status' => 'active']);
        $this->assertNull($service->fresh()->dunning_suspended_at);

        $this->cron();
        $this->assertSame('suspended', $service->fresh()->status, 'resuspended as dunning, not terminated');
        $this->assertSame('2026-10-20', $service->fresh()->dunning_suspended_at->toDateString());

        // Termination Days count from the due date (as WHMCS), long past: the next day, after «minimum days suspended».
        // Staff who want time give a hold or move the date, not just unsuspend.
        $this->cron('2026-10-20 23:00:00');
        $this->assertSame('suspended', $service->fresh()->status);
        $this->cron('2026-10-21 03:00:01');
        $this->assertSame('terminated', $service->fresh()->status);
    }

    public function test_holds_terminate_not_after_suspend_waits_one_day(): void
    {
        config(['settings.cronjob_order_suspend' => 14, 'settings.cronjob_order_terminate' => 2]);
        $service = $this->service(['expires_at' => '2026-10-01 00:00:00']);
        $this->invoice($service, '2026-10-01');

        $this->cron();
        $this->assertSame('suspended', $service->fresh()->status);
        $this->cron('2026-10-20 23:00:00');
        $this->assertSame('suspended', $service->fresh()->status);
        $this->cron('2026-10-21 03:00:01');
        $this->assertSame('terminated', $service->fresh()->status);
    }

    public function test_holds_hold_until_and_null_expiry_and_upgrade_or_draft_lines(): void
    {
        $held = $this->service(['expires_at' => '2026-10-01 00:00:00', 'suspend_hold_until' => '2026-10-21']);
        $this->invoice($held, '2026-10-01');
        $noExpiry = $this->service(['expires_at' => null]);
        $this->invoice($noExpiry, '2026-10-01');
        // Only an upgrade line (same numeric id) and a renewal draft: no open Service invoice.
        $upgradeOnly = $this->service(['expires_at' => '2026-10-01 00:00:00']);
        $this->invoice($upgradeOnly, '2026-10-01', 'draft');
        $this->invoice($upgradeOnly, '2026-10-01', 'unpaid', ServiceUpgrade::class);

        $this->cron();

        $this->assertSame('active', $held->fresh()->status);
        $this->assertSame('active', $noExpiry->fresh()->status);
        $this->assertSame('active', $upgradeOnly->fresh()->status);
        $this->assertSame(1, $this->noticesFor($upgradeOnly));
    }

    public function test_holds_partially_paid_and_staff_extended_due_date(): void
    {
        $partial = $this->service(['expires_at' => '2026-10-10 00:00:00']);
        $invoice = $this->invoice($partial, '2026-10-10');
        DB::table('invoice_transactions')->insert(['invoice_id' => $invoice->id, 'amount' => 4, 'status' => InvoiceTransactionStatus::Succeeded->value,
            'transaction_id' => 'p1', 'created_at' => now(), 'updated_at' => now()]);
        $extended = $this->service(['expires_at' => '2026-10-10 00:00:00']);
        $this->invoice($extended, '2026-10-25'); // staff gave time

        $this->cron();

        $this->assertSame('suspended', $partial->fresh()->status, 'WHMCS: still unpaid');
        $this->assertSame('active', $extended->fresh()->status);
    }

    public function test_holds_one_service_failing_does_not_block_the_others_and_suspendjob_is_after_commit(): void
    {
        $bad = $this->service(['expires_at' => '2026-10-10 00:00:00']);
        $this->invoice($bad, '2026-10-10');
        $good = $this->service(['expires_at' => '2026-10-10 00:00:00']);
        $this->invoice($good, '2026-10-10');
        config(['queue.default' => 'database']);
        Service::updating(function (Service $service) use ($bad) {
            if ($service->id === $bad->id && $service->status === 'suspended') {
                throw new RuntimeException('listener failed');
            }
        });

        try {
            Artisan::call('app:cron-job');
            $this->fail('the failure is reported');
        } catch (Throwable $e) {
            $this->assertStringContainsString('listener failed', $e->getMessage(), $e->getMessage());
        }

        $this->assertSame('active', $bad->fresh()->status);
        $this->assertSame('suspended', $good->fresh()->status);
        $jobs = DB::table('jobs')->pluck('payload')->filter(fn ($payload) => str_contains($payload, 'SuspendJob'));
        $this->assertCount(1, $jobs, 'only the committed suspension is queued');
        $this->assertStringContainsString('"id";i:' . $good->id . ';', json_decode($jobs->first(), true)['data']['command']);
    }
}
