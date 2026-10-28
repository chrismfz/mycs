<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Jobs\Server\TerminateJob;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial re-review of PR #61, commit 0422366 (review findings). test_holds_* pass (guards); the others fail on
 * the PR head and prove a finding.
 */
class EndOfPeriodCancellationReReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        $this->user = User::factory()->create();
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true,
            'settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14]); // the days these fixtures assume
    }

    /** The cron rethrows its first failure after mailing it: run it, keep going. */
    private function cron(): void
    {
        try {
            $this->artisan('app:cron-job')->run();
        } catch (\Throwable) {
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(string $expires, string $status = 'active', ?int $stock = null, array $extra = []): Service
    {
        $product = $this->createProduct();
        $product->product->update(['stock' => $stock]);

        return Service::factory()->create(array_merge([
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ], $extra));
    }

    private function openInvoiceFor(Service $service, float $price = 10): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create([
            'reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => $price,
        ]);

        return $invoice;
    }

    private function credit(): float
    {
        return (float) (Credit::query()->where('client_id', $this->user->client()->id)->where('currency_code', 'USD')->value('amount') ?? 0);
    }

    // ---------------------------------------------------------------- findings (fail on the PR head)

    /**
     * R1 (high): dunning termination now leaves a gateway-part-paid invoice OPEN on a cancelled service. There is no
     * ServiceCancellation row on this path, so CreditAutoApply::eligible does not stop it: the next credit the
     * customer gets is swept into it, the invoice turns paid, RenewServiceService refuses to revive the service
     * («not revived»). The customer's credit is spent on a service that no longer exists.
     */
    public function test_credit_is_not_swept_into_a_terminated_services_left_open_invoice(): void
    {
        Queue::fake([TerminateJob::class]);
        $service = $this->service('2026-09-01', 'suspended', extra: ['dunning_suspended_at' => '2026-09-05']);
        $invoice = $this->openInvoiceFor($service);
        (new RecordPaymentService)->handle($invoice, null, 4.00, transactionId: 'bank-r1');

        $this->cron();
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);

        // Later the customer gets credit (overpayment elsewhere, staff, refund to credit).
        (new CreditLedger)->add($this->user->client()->id, 'USD', 600, MoneyTrail::CAUSE_OPENING_BALANCE, MoneyTrail::SOURCE_SYSTEM);
        Carbon::setTestNow('2026-10-21 03:00:00');
        $this->cron();

        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
        $this->assertEqualsWithDelta(6.00, $this->credit(), 0.001,
            "credit was swept into invoice #{$invoice->id} (now {$invoice->fresh()->status}) of a terminated service");
    }

    /** R1 on orders_cancelled: a never-activated order with a gateway part payment, same sweep. */
    public function test_credit_is_not_swept_into_a_cancelled_orders_left_open_invoice(): void
    {
        Queue::fake([TerminateJob::class]);
        $service = $this->service('2026-10-01', 'pending', extra: ['created_at' => '2026-10-01']);
        $invoice = $this->openInvoiceFor($service);
        (new RecordPaymentService)->handle($invoice, null, 4.00, transactionId: 'bank-r1b');

        $this->cron();
        // BIL-12: an order with money on it is no longer cancelled (staff decide on a part paid), so its invoice is never
        // a left-open invoice of a cancelled service that credit could be swept into.
        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    public function test_one_bad_invoice_does_not_block_every_dunning_termination(): void
    {
        Queue::fake([TerminateJob::class]);
        $bad = $this->service('2026-09-01', 'suspended', extra: ['dunning_suspended_at' => '2026-09-05']);
        $good = $this->service('2026-09-01', 'suspended', extra: ['dunning_suspended_at' => '2026-09-05']);
        $invoice = $this->openInvoiceFor($bad);
        $invoice->transactions()->create([
            'amount' => 2, 'refunded_amount' => 1, 'status' => InvoiceTransactionStatus::Succeeded, 'is_credit_transaction' => true,
        ]);

        $this->cron();

        $this->assertSame(Service::STATUS_TERMINATED, $good->fresh()->status,
            'an unrelated service was not terminated because another service\'s invoice could not be cancelled');
    }

    /**
     * R3 (medium): an immediate request leaves the service's own pending UPGRADE invoice open (onlyFor counts the
     * ServiceUpgrade line as «something else»). Until the old expiry the customer can still pay it: the upgrade is
     * applied to the cancelled service (plan/product changed, old product's stock given back a second time).
     */
    public function test_an_immediate_request_does_not_leave_the_services_upgrade_payable(): void
    {
        Queue::fake([TerminateJob::class]);
        $service = $this->service('2026-11-15', stock: 5);
        $target = $this->createProduct();
        $upgradeInvoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'plan_id' => $target->plan->id, 'product_id' => $target->product->id, 'status' => 'pending', 'invoice_id' => $upgradeInvoice->id]);
        $upgradeInvoice->items()->create(['reference_id' => $upgrade->id, 'reference_type' => ServiceUpgrade::class, 'description' => 'Upgrade', 'quantity' => 1, 'price' => 3]);

        ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'immediate', 'reason' => 'Leaving']);
        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);

        $this->assertNotSame(Invoice::STATUS_UNPAID, $upgradeInvoice->fresh()->status,
            'the upgrade invoice of an immediately cancelled service is still payable');
    }

    /**
     * R4 (low): an invoice imported from WHMCS carries the service's promotion or setup fee as its charge (#507:
     * reference Service, kind charge). It goes with the service: the service's own invoice is cancelled with it.
     */
    public function test_an_imported_promo_line_does_not_keep_the_services_own_invoice_open(): void
    {
        Queue::fake([TerminateJob::class]);
        $service = $this->service('2026-10-25');
        $invoice = $this->openInvoiceFor($service);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'kind' => InvoiceItem::KIND_CHARGE,
            'description' => 'Promo', 'quantity' => 1, 'price' => -2]);

        ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status,
            'a service-only invoice with a reference-less promo/setup line stays open after the request');
    }

    // ---------------------------------------------------------------- holds

    /** A processing (not yet succeeded) gateway payment does not keep the invoice open; when it settles it becomes credit, not lost. */
    public function test_holds_processing_payment_settles_into_credit_after_the_cancellation(): void
    {
        Queue::fake([TerminateJob::class]);
        $service = $this->service('2026-10-25');
        $invoice = $this->openInvoiceFor($service);
        (new RecordPaymentService)->handle($invoice, null, 10.00, transactionId: 'sepa-1', status: InvoiceTransactionStatus::Processing);

        ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);

        (new RecordPaymentService)->handle($invoice, null, 10.00, transactionId: 'sepa-1');
        $this->assertEqualsWithDelta(10.00, $this->credit(), 0.001);
    }

    /** Within cancellations_processed one bad invoice aborts only its own service, the others end, and it is retried. */
    public function test_holds_one_bad_invoice_aborts_only_its_own_service_end(): void
    {
        Queue::fake();
        $bad = $this->service('2026-10-20');
        $good = $this->service('2026-10-20');
        foreach ([$bad, $good] as $s) {
            ServiceCancellation::query()->create(['service_id' => $s->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);
        }
        $invoice = $this->openInvoiceFor($bad);
        $invoice->transactions()->create(['amount' => 2, 'refunded_amount' => 1, 'status' => InvoiceTransactionStatus::Succeeded, 'is_credit_transaction' => true]);

        $this->cron();

        $this->assertSame(Service::STATUS_CANCELLED, $good->fresh()->status);
        $this->assertSame(Service::STATUS_ACTIVE, $bad->fresh()->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    /** End-of-period request on a pending (never paid) order: the listener cancels its first invoice. */
    public function test_holds_request_on_pending_service_cancels_its_invoice(): void
    {
        Queue::fake([TerminateJob::class]);
        $service = $this->service('2026-10-25', 'pending');
        $invoice = $this->openInvoiceFor($service);
        ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
    }

    /** paidAhead: the current paid period (ends at expires_at) is not «paid ahead»; the service ends on its date. */
    public function test_holds_current_paid_period_is_not_paid_ahead(): void
    {
        Queue::fake();
        $service = $this->service('2026-10-20');
        $paid = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $paid->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'R', 'quantity' => 1, 'price' => 10, 'period_start' => '2026-09-20', 'period_end' => '2026-10-20']);
        Invoice::query()->whereKey($paid->id)->update(['status' => 'paid']);
        ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
    }

    /**
     * Probe (holds): paidAhead compares period_start >= expires_at. An expiry carrying a time of day (staff edit,
     * import) makes the next paid period, starting that same day, look «not ahead»: the service ends with it paid.
     */
    public function test_holds_a_paid_next_period_is_seen_when_the_expiry_has_a_time_of_day(): void
    {
        Queue::fake();
        $service = $this->service('2026-10-20 12:00:00');
        $paid = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $paid->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'R', 'quantity' => 1, 'price' => 10, 'period_start' => '2026-10-20', 'period_end' => '2026-11-20']);
        Invoice::query()->whereKey($paid->id)->update(['status' => 'paid']);
        ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);

        $this->cron();
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'ended although the next period is paid');
    }
}
