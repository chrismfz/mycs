<?php

namespace Tests\Feature;

use App\Events\Invoice\Paid;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\ProcessPaidInvoiceService;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of PR #68 (BIL-9, paid but not applied). Failing tests are findings; the rest are attacks that held.
 */
class PaidNotAppliedAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private bool $failRenewal = false;

    /** Only this service's renewal fails (null: every service). */
    private ?int $failOnly = null;

    private bool $failUpgrade = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        Queue::fake();
        Mail::fake();
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_invoice' => 0,
            'settings.credits_auto_use' => false]);
        Service::updating(function (Service $service) {
            if ($this->failRenewal && $service->isDirty('expires_at') && ($this->failOnly === null || $this->failOnly === $service->id)) {
                throw new RuntimeException('the server went away');
            }
            if ($this->failUpgrade && $service->isDirty('product_id')) {
                throw new RuntimeException('the server went away (upgrade)');
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(?User $user = null, ?object $product = null): Service
    {
        $product ??= $this->createProduct();

        return Service::factory()->create([
            'user_id' => ($user ?? User::factory()->create())->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-10-15',
        ]);
    }

    /** @param  Service[]  $services */
    private function renewal(array $services): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $services[0]->user_id, 'status' => 'pending', 'currency_code' => 'USD',
            'due_at' => '2026-10-15', 'created_at' => '2026-10-08']);
        foreach ($services as $service) {
            $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);
        }

        return $invoice;
    }

    private function payFailingAfterCommit(Invoice $invoice, float $amount = 10): void
    {
        $this->failRenewal = true;
        try {
            (new RecordPaymentService)->handle($invoice, null, $amount, transactionId: 'tx-' . $invoice->id . '-' . uniqid());
        } catch (RuntimeException) {
        }
        $this->failRenewal = false;
    }

    /** The cron as the scheduler runs it: a failure makes it throw after it mailed staff. */
    private function cron(): ?Throwable
    {
        try {
            Artisan::call('app:cron-job');
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    }

    // ---------------------------------------------------------------- findings

    /**
     * A1. The cron runs once a day (routes/console.php, dailyAt). A payment whose application failed less than 10
     * minutes before that run is skipped by paid_applied, and services_suspended, in the same run, suspends the
     * customer who paid. Nothing in suspendable() looks at apply_pending_since.
     */
    public function test_a1_a_failure_just_before_the_daily_run_does_not_suspend_the_customer_who_paid(): void
    {
        $service = $this->service();
        $invoice = $this->renewal([$service]);
        $this->payFailingAfterCommit($invoice); // 03:00

        Carbon::setTestNow('2026-10-20 03:05:00');
        $this->cron();

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'paid five minutes ago: suspended anyway');
    }

    /**
     * A2. Credit paid the invoice in this very run (credit_applied runs after paid_applied); its application failed,
     * and services_suspended in the same run suspends the customer whose credit just paid.
     */
    public function test_a2_credit_paid_in_the_same_run_whose_application_failed_does_not_suspend(): void
    {
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
        $service = $this->service();
        $this->renewal([$service]);
        (new CreditLedger)->add($service->user_id, 'USD', 1000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'seed');

        $this->failRenewal = true;
        $this->cron();
        $this->failRenewal = false;

        $invoice = Invoice::query()->where('user_id', $service->user_id)->first();
        $this->assertSame('paid', $invoice->status, 'precondition: credit paid it');
        $this->assertNotNull($invoice->apply_pending_since, 'precondition: its application failed');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'paid by credit in this run: suspended anyway');
    }

    /**
     * A3. A line that fails on every run (a bug, a server that refuses the change): the invoice keeps its marker, every
     * daily run fails and mails, and in the same run services_suspended suspends the service whose renewal is paid
     * (then terminates it Termination Days later). The step's own comment: «before anything is suspended».
     */
    public function test_a3_a_paid_service_whose_renewal_keeps_failing_is_not_suspended(): void
    {
        $service = $this->service();
        $invoice = $this->renewal([$service]);
        $this->payFailingAfterCommit($invoice);

        Carbon::setTestNow('2026-10-20 09:00:00');
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $this->failRenewal = true;
        $this->cron();
        $this->failRenewal = false;

        // Reported to staff as a notice (the run itself succeeds: the service is protected meanwhile).
        $this->assertTrue(EmailLog::where('body', 'like', "%paid_applied #{$invoice->id}%")->exists(), 'staff are told');
        $this->assertNotNull($invoice->fresh()->apply_pending_since, 'precondition: still pending');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'paid renewal pending application: suspended');
    }

    /**
     * A6. A paid line without a period (an invoice staff made by hand, an older one) whose application failed in the
     * ten minutes before the daily run: paid_applied skips it, invoices_created then bills the same period again (a
     * period-less paid line covers nothing), and the next run gives the payment the period after it. The customer
     * paid 10-15..11-15 and is asked to pay 10-15..11-15 again. Also with a line failing for good: the second run
     * clears the marker («ahead of an unpaid period») with no time given.
     */
    public function test_a6_a_paid_line_without_period_is_not_billed_again_before_it_is_applied(): void
    {
        $service = $this->service();
        $invoice = $this->renewal([$service]);
        $this->payFailingAfterCommit($invoice); // 03:00
        DB::table('services')->where('id', $service->id)->update(['expires_at' => '2026-10-19']); // not suspendable today

        Carbon::setTestNow('2026-10-20 03:05:00');
        $this->cron();

        $this->assertSame(0, Invoice::query()->where('user_id', $service->user_id)->where('status', 'pending')->count(),
            'the period just paid is billed again');
    }

    /**
     * A4. A paid invoice stays «paid» after a full refund (the refund writes an adjustment note). Staff refund the
     * customer whose renewal failed; the next cron renews the service anyway: a period with no money.
     */
    public function test_a4_a_fully_refunded_invoice_is_not_applied_again(): void
    {
        $service = $this->service();
        $invoice = $this->renewal([$service]);
        $this->payFailingAfterCommit($invoice);
        ExtensionHelper::refundLocally($invoice->transactions()->firstOrFail(), 10);
        $this->assertSame('refunded', $invoice->fresh()->status, 'precondition: all of it went back: refunded (2c)');

        Carbon::setTestNow('2026-10-20 03:30:00');
        $this->cron();

        $this->assertSame('2026-10-15', $service->fresh()->expires_at->toDateString(), 'refunded: renewed for nothing');
    }

    /**
     * A5. The cron applies the lines but never fires Invoice\Paid, which the failed after-commit closure never reached:
     * no «payment received» mail, no paid snapshot, no affiliate reward.
     */
    public function test_a5_applying_again_fires_the_paid_event_that_was_lost(): void
    {
        $service = $this->service();
        $invoice = $this->renewal([$service]);
        Event::fake([Paid::class]);
        $this->payFailingAfterCommit($invoice);
        Event::assertNotDispatched(Paid::class);

        Carbon::setTestNow('2026-10-20 03:30:00');
        $this->cron();

        $this->assertNull($invoice->fresh()->apply_pending_since, 'precondition: applied');
        Event::assertDispatched(Paid::class, fn (Paid $event) => $event->invoice->is($invoice));
    }

    // ---------------------------------------------------------------- holds

    public function test_holds_several_services_one_failing_each_renewed_once(): void
    {
        $user = User::factory()->create();
        $a = $this->service($user);
        $b = $this->service($user);
        $invoice = $this->renewal([$a, $b]);
        $this->failOnly = $a->id;
        $this->payFailingAfterCommit($invoice, 20);
        $this->failOnly = null;
        $bRenewed = $b->fresh()->expires_at->toDateString();
        $this->assertNotSame('2026-10-15', $bRenewed);
        $this->assertSame('2026-10-15', $a->fresh()->expires_at->toDateString());

        Carbon::setTestNow('2026-10-20 03:30:00');
        $this->assertNull($this->cron());
        Carbon::setTestNow('2026-10-21 03:30:00');
        $this->cron();

        $this->assertSame($bRenewed, $b->fresh()->expires_at->toDateString(), 'b: one period');
        $this->assertSame($bRenewed, $a->fresh()->expires_at->toDateString(), 'a: renewed once, as b');
        $this->assertNull($invoice->fresh()->apply_pending_since);
    }

    public function test_holds_paid_pending_paid_renews_once(): void
    {
        $service = $this->service();
        $invoice = $this->renewal([$service]);
        $this->payFailingAfterCommit($invoice);
        (new RecordPaymentService)->deleteTransaction($invoice->transactions()->firstOrFail());
        $this->assertSame('pending', $invoice->fresh()->status);

        Carbon::setTestNow('2026-10-20 03:30:00');
        $this->cron();
        $this->assertSame('2026-10-15', $service->fresh()->expires_at->toDateString(), 'unpaid: not renewed');

        (new RecordPaymentService)->handle($invoice->fresh(), null, 10, transactionId: 'tx-again');
        $renewed = $service->fresh()->expires_at->toDateString();
        $this->assertNotSame('2026-10-15', $renewed);
        Carbon::setTestNow('2026-10-20 04:00:00');
        DB::table('invoices')->where('id', $invoice->id)->update(['apply_pending_since' => '2026-10-20 03:00:00']);
        $this->cron();

        $this->assertSame($renewed, $service->fresh()->expires_at->toDateString(), 'one payment, one period');
    }

    public function test_holds_an_upgrade_is_done_once_and_stock_moves_once(): void
    {
        $old = $this->createProduct(['stock' => 5]);
        $new = $this->createProduct(['stock' => 5]);
        $service = $this->service(null, $old);
        $upgrade = ServiceUpgrade::query()->create(['service_id' => $service->id, 'plan_id' => $new->plan->id, 'product_id' => $new->product->id]);
        $invoice = Invoice::factory()->create(['user_id' => $service->user_id, 'status' => 'pending', 'currency_code' => 'USD', 'due_at' => '2026-10-20']);
        $invoice->items()->create(['reference_type' => ServiceUpgrade::class, 'reference_id' => $upgrade->id, 'description' => 'Upgrade', 'quantity' => 1, 'price' => 5]);

        $this->failUpgrade = true;
        try {
            (new RecordPaymentService)->handle($invoice, null, 5, transactionId: 'tx-up');
        } catch (RuntimeException) {
        }
        $this->failUpgrade = false;
        $this->assertSame(ServiceUpgrade::STATUS_PENDING, $upgrade->fresh()->status);
        $this->assertSame(5, (int) $old->product->fresh()->stock, 'rolled back');

        Carbon::setTestNow('2026-10-20 03:30:00');
        $this->cron();
        $this->cron();
        (new ProcessPaidInvoiceService)->handle($invoice->fresh());

        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);
        $this->assertSame(6, (int) $old->product->fresh()->stock);
        $this->assertSame(4, (int) $new->product->fresh()->stock);
    }

    public function test_holds_a_cancelled_service_is_not_revived_and_the_marker_goes(): void
    {
        $service = $this->service();
        $invoice = $this->renewal([$service]);
        $this->payFailingAfterCommit($invoice);
        DB::table('services')->where('id', $service->id)->update(['status' => 'cancelled']);

        Carbon::setTestNow('2026-10-20 03:30:00');
        $this->assertNull($this->cron());

        $this->assertSame('cancelled', $service->fresh()->status);
        $this->assertSame('2026-10-15', $service->fresh()->expires_at->toDateString());
        $this->assertNull($invoice->fresh()->apply_pending_since);
    }

    public function test_holds_a_credit_purchase_is_not_credited_again(): void
    {
        config(['settings.credits_enabled' => true]);
        $user = User::factory()->create();
        $deposit = Invoice::factory()->create(['user_id' => $user->id, 'status' => 'pending', 'currency_code' => 'USD']);
        $deposit->items()->create(['reference_type' => Credit::class, 'description' => 'Deposit', 'quantity' => 1, 'price' => 20]);
        (new RecordPaymentService)->handle($deposit, null, 20, transactionId: 'tx-dep');
        $this->assertSame(2000, RecordPaymentService::toCents(Credit::query()->where('user_id', $user->id)->value('amount')));

        DB::table('invoices')->where('id', $deposit->id)->update(['apply_pending_since' => '2026-10-20 02:00:00']);
        $this->cron();
        $this->cron();

        $this->assertSame(2000, RecordPaymentService::toCents(Credit::query()->where('user_id', $user->id)->value('amount')));
        $this->assertNull($deposit->fresh()->apply_pending_since);
    }

    public function test_holds_the_ten_minute_boundary(): void
    {
        $service = $this->service();
        $invoice = $this->renewal([$service]);
        $this->payFailingAfterCommit($invoice); // 03:00:00

        Carbon::setTestNow('2026-10-20 03:10:00');
        DB::table('services')->where('id', $service->id)->update(['expires_at' => '2026-10-19']); // not suspendable today
        $this->cron();
        $this->assertNotNull($invoice->fresh()->apply_pending_since, 'exactly ten minutes: still left alone');

        Carbon::setTestNow('2026-10-20 03:10:01');
        $this->cron();
        $this->assertNull($invoice->fresh()->apply_pending_since, 'past ten minutes: applied');
    }

    public function test_holds_imported_paid_invoice_without_marker_is_never_touched_even_with_unapplied_lines(): void
    {
        $service = $this->service();
        $id = DB::table('invoices')->insertGetId(['user_id' => $service->user_id, 'status' => 'paid', 'currency_code' => 'USD',
            'due_at' => '2026-10-15', 'created_at' => '2026-10-08', 'updated_at' => '2026-10-08']);
        DB::table('invoice_items')->insert(['invoice_id' => $id, 'reference_type' => Service::class, 'reference_id' => $service->id,
            'description' => 'WHMCS renewal', 'quantity' => 1, 'price' => 10, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('services')->where('id', $service->id)->update(['expires_at' => '2026-10-19']);

        $this->cron();

        $this->assertSame('2026-10-19', $service->fresh()->expires_at->toDateString());
    }
}
