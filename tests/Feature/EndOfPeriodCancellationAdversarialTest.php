<?php

namespace Tests\Feature;

use App\Jobs\Server\TerminateJob;
use App\Models\Credit;
use App\Models\CronStat;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of PR #61 (BIL-1, SVC-13): «end of billing period» cancellation requests.
 * test_holds_* are guards that pass; the others fail on the PR head and prove a finding.
 */
class EndOfPeriodCancellationAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(string $expires, string $status = 'active', ?int $stock = null): Service
    {
        $product = $this->createProduct();
        $product->product->update(['stock' => $stock]);

        return Service::factory()->create([
            'user_id' => $this->user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ]);
    }

    /** @param array<int, Service> $services */
    private function openInvoiceFor(array $services, ?string $periodStart = null, ?string $periodEnd = null): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'pending', 'currency_code' => 'USD']);
        foreach ($services as $service) {
            $invoice->items()->create([
                'reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
                'quantity' => 1, 'price' => 10, 'period_start' => $periodStart, 'period_end' => $periodEnd,
            ]);
        }

        return $invoice;
    }

    private function request(Service $service, string $type = 'end_of_period'): ServiceCancellation
    {
        return ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => $type, 'reason' => 'Leaving']);
    }

    private function credit(): float
    {
        return (float) (Credit::query()->where('user_id', $this->user->id)->where('currency_code', 'USD')->value('amount') ?? 0);
    }

    // ---------------------------------------------------------------- findings (fail on the PR head)

    /**
     * F1: the request's listener cancels an invoice a gateway already paid in part. giveBack only returns CREDIT
     * payments: the 4.00 that came through a gateway stays on a cancelled invoice, neither credited nor refunded.
     */
    public function test_a_gateway_part_payment_is_not_lost_when_the_request_cancels_the_invoice(): void
    {
        Queue::fake([TerminateJob::class]);
        $service = $this->service('2026-10-25');
        $invoice = $this->openInvoiceFor([$service]);
        (new RecordPaymentService)->handle($invoice, null, 4.00, transactionId: 'bank-1');
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);

        $this->request($service); // listener runs (sync queue)

        $kept = $invoice->fresh()->status === Invoice::STATUS_PENDING;
        $this->assertTrue($kept || abs($this->credit() - 4.00) < 0.001,
            'the 4.00 paid by gateway must stay payable on the open invoice or come back as credit; it vanished (credit = ' . $this->credit() . ')');
    }

    /** F1 on the cron path (listener never ran / request made by import): same loss. */
    public function test_a_gateway_part_payment_is_not_lost_when_the_cron_ends_the_service(): void
    {
        Queue::fake(); // listener and TerminateJob queued, not run
        $service = $this->service('2026-10-20');
        $invoice = $this->openInvoiceFor([$service]);
        (new RecordPaymentService)->handle($invoice, null, 4.00, transactionId: 'bank-2');
        $this->request($service);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        // Decision (#61): the invoice stays open with its money, for staff (the cron mails it); MT-2 decides credit.
        $kept = $invoice->fresh()->status === Invoice::STATUS_PENDING;
        $this->assertTrue($kept || abs($this->credit() - 4.00) < 0.001, 'the 4.00 a gateway paid vanished (credit = ' . $this->credit() . ')');
    }

    /**
     * F2 (SVC-4, newly reachable on every end_of_period request): one invoice renews services A and B; the request on A
     * cancels it, so B's renewal is cancelled too, the day the customer asks (before, an end_of_period request touched
     * no invoice). B is only re-billed at the next cron, as a new invoice.
     */
    public function test_a_request_on_one_service_leaves_the_other_services_renewal_open(): void
    {
        Queue::fake([TerminateJob::class]);
        $a = $this->service('2026-10-25');
        $b = $this->service('2026-10-25');
        $invoice = $this->openInvoiceFor([$a, $b]);

        $this->request($a);

        $this->assertTrue(
            $b->invoices()->where('invoices.status', Invoice::STATUS_PENDING)->exists(),
            "service B's renewal (invoice #{$invoice->id}, status {$invoice->fresh()->status}) was cancelled by a request on service A"
        );
    }

    /**
     * F3: two immediate requests (double submit: Cancel::cancelService does not check `cancellable`, there is no
     * unique key on service_cancellations) give the stock back twice. The PR changed the guard from truthy to
     * `!== null`, so it now also increments from 0.
     */
    public function test_a_second_immediate_request_does_not_give_stock_back_twice(): void
    {
        Queue::fake([TerminateJob::class]);
        $service = $this->service('2026-10-25', stock: 0);

        $this->request($service, 'immediate');
        $this->request($service, 'immediate');

        $this->assertSame(1, $service->product->fresh()->stock);
    }

    /**
     * F3b: the cron ended the service (stock back), then an immediate request on the already cancelled service (stale
     * cancel form, staff) gives the stock back again.
     */
    public function test_an_immediate_request_on_a_service_the_cron_already_ended_does_not_give_stock_back_again(): void
    {
        Queue::fake([TerminateJob::class]);
        $service = $this->service('2026-10-20', stock: 0);
        $this->request($service);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertSame(1, $service->product->fresh()->stock);

        $this->request($service->fresh(), 'immediate');

        $this->assertSame(1, $service->product->fresh()->stock, 'stock given back twice for one service');
    }

    /**
     * F4: a period paid AHEAD of an unpaid one waits for the gap (billing cursor). The request cancels the gap's invoice,
     * so the gap is never paid; the cron ends the service at the current expiry and the paid period is neither
     * delivered nor credited back.
     */
    public function test_a_period_paid_ahead_of_the_cancelled_gap_is_not_stranded(): void
    {
        Queue::fake([TerminateJob::class]);
        $service = $this->service('2026-10-20');
        $this->openInvoiceFor([$service], '2026-10-20', '2026-11-20'); // the gap, unpaid
        $ahead = $this->openInvoiceFor([$service], '2026-11-20', '2026-12-20');
        (new RecordPaymentService)->handle($ahead, null, 10.00, transactionId: 'bank-ahead');
        $this->assertSame(Invoice::STATUS_PAID, $ahead->fresh()->status);
        $this->assertSame('2026-10-20', $service->fresh()->expires_at->toDateString(), 'the paid period waits for the gap');

        $this->request($service);
        $this->artisan('app:cron-job')->assertExitCode(0);

        $ended = $service->fresh()->status === Service::STATUS_CANCELLED;
        $this->assertFalse($ended && $this->credit() < 9.999,
            'service ended on 2026-10-20 while 10.00 paid for 2026-11-20..12-20 is neither delivered nor credited');
    }

    // ---------------------------------------------------------------- guards (pass)

    public function test_holds_cron_run_twice_is_idempotent(): void
    {
        Queue::fake();
        $service = $this->service('2026-10-20', stock: 3);
        $this->request($service);

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->artisan('app:cron-job')->assertExitCode(0);

        Queue::assertPushed(TerminateJob::class, 1);
        $this->assertSame(4, $service->product->fresh()->stock);
        $this->assertSame(1, (int) CronStat::query()->where('key', 'cancellations_processed')->sum('value'));
        $this->assertSame(2, CronStat::query()->where('key', 'cancellations_processed')->count());
    }

    public function test_holds_timezone_boundary_late_evening_before_the_due_date(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-19 23:55:00', 'Europe/Athens'));
        $service = $this->service('2026-10-20');
        $this->request($service);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);

        Carbon::setTestNow(Carbon::parse('2026-10-20 00:05:00', 'Europe/Athens'));
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
    }

    public function test_holds_paid_ahead_service_ends_at_the_end_of_the_paid_period(): void
    {
        Queue::fake([TerminateJob::class]);
        $service = $this->service('2026-10-20');
        $invoice = $this->openInvoiceFor([$service], '2026-10-20', '2026-11-20');
        (new RecordPaymentService)->handle($invoice, null, 10.00, transactionId: 'bank-paid');
        $this->assertSame('2026-11-20', $service->fresh()->expires_at->toDateString());

        $this->request($service);
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        Queue::assertNotPushed(TerminateJob::class);
    }

    public function test_holds_request_deleted_before_due_date_service_is_billed_again(): void
    {
        Queue::fake([TerminateJob::class]);
        $service = $this->service('2026-10-24');
        $invoice = $this->openInvoiceFor([$service], '2026-10-24', '2026-11-24');
        $cancellation = $this->request($service);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);

        $cancellation->delete();
        $this->artisan('app:cron-job')->assertExitCode(0);

        $service->refresh();
        $this->assertSame(Service::STATUS_ACTIVE, $service->status);
        $this->assertTrue($service->invoices()->where('invoices.status', Invoice::STATUS_PENDING)->exists(), 'the period is billed again');
        Queue::assertNotPushed(TerminateJob::class);
    }

    /** After #61's review: a combined invoice is left open for staff, so the other service's renewal is never lost. */
    public function test_holds_other_service_on_a_combined_invoice_keeps_its_renewal_open(): void
    {
        Queue::fake([TerminateJob::class]);
        $a = $this->service('2026-10-25');
        $b = $this->service('2026-10-25');
        $this->openInvoiceFor([$a, $b], '2026-10-25', '2026-11-25');
        $this->request($a);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertTrue($b->invoices()->where('invoices.status', Invoice::STATUS_PENDING)->exists());
    }

    public function test_holds_credit_is_not_applied_to_a_requested_service_invoice_by_the_sweep(): void
    {
        Queue::fake(); // listener never ran: the open invoice is still there when the cron starts
        $service = $this->service('2026-10-20');
        $invoice = $this->openInvoiceFor([$service]);
        Credit::query()->create(['user_id' => $this->user->id, 'currency_code' => 'USD', 'amount' => 25]);
        $this->request($service);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(0, $invoice->transactions()->count());
        $this->assertEqualsWithDelta(25.0, $this->credit(), 0.001);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
    }

    public function test_holds_credit_paid_in_part_comes_back_when_the_request_cancels(): void
    {
        Queue::fake([TerminateJob::class]);
        $service = $this->service('2026-10-25');
        $invoice = $this->openInvoiceFor([$service]);
        Credit::query()->create(['user_id' => $this->user->id, 'currency_code' => 'USD', 'amount' => 4]);
        (new RecordPaymentService)->payWithCredit($invoice);
        $this->assertEqualsWithDelta(0.0, $this->credit(), 0.001);

        $this->request($service);

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertEqualsWithDelta(4.0, $this->credit(), 0.001);
    }

    public function test_holds_suspended_service_is_not_suspended_again_with_dunning_marker(): void
    {
        Queue::fake();
        $service = $this->service('2026-10-01');
        $this->request($service);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $service->refresh();
        $this->assertSame(Service::STATUS_CANCELLED, $service->status);
        $this->assertNull($service->dunning_suspended_at);
    }
}
