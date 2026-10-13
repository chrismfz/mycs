<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Jobs\Server\TerminateJob;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MoneyEvent;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * MT-2b (docs/money-trail.md §3): the app cancels invoices through the model, with their credit note and an audit row,
 * and line by line (audit sweep SVC-4): an invoice that also bills another service loses only the cancelled service's
 * lines. A queued job (the cancellation request's listener) is audited.
 */
class CancelOpenInvoicesTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $attributes */
    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(array_merge([
            'user_id' => $this->user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => '2026-11-30', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ], $attributes));
    }

    /** @param  array<int, Service|float>  $lines  a service's renewal line, or a line with no reference (its price) */
    private function invoice(array $lines, ?string $periodStart = null, ?string $periodEnd = null): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        foreach ($lines as $line) {
            $invoice->items()->create($line instanceof Service
                ? ['reference_type' => Service::class, 'reference_id' => $line->id, 'description' => 'Renewal', 'quantity' => 1,
                    'price' => 10, 'period_start' => $periodStart, 'period_end' => $periodEnd]
                : ['description' => 'Promotion', 'quantity' => 1, 'price' => $line]);
        }

        return $invoice;
    }

    private function request(Service $service, string $type = 'immediate'): ServiceCancellation
    {
        return ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => $type, 'reason' => 'Leaving']);
    }

    private function credit(): float
    {
        return (float) (Credit::query()->where('user_id', $this->user->id)->where('currency_code', 'USD')->value('amount') ?? 0);
    }

    /** @return array<int, int> */
    private function servicesBilled(Invoice $invoice): array
    {
        return $invoice->items()->orderBy('id')->pluck('reference_id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_the_cron_cancels_an_unpaid_order_through_the_model_with_its_credit_note_and_audit(): void
    {
        Queue::fake();
        $service = $this->service(['status' => 'pending', 'expires_at' => null]);
        $service->forceFill(['created_at' => now()->subDays(10)])->save();
        $invoice = $this->invoice([$service]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->status);
        $note = $invoice->adjustmentNotes()->sole();
        $this->assertTrue($note->is_cancellation);
        $this->assertSame('-10.00', (string) $note->amount);
        $this->assertSame(__('invoices.cancellation_reasons.unpaid_order'), $note->description);
        $this->assertSame(__('invoices.cancellation_reasons.unpaid_order'), $invoice->cancellation_reason);
        $this->assertTrue(Audit::query()->where('auditable_type', Invoice::class)->where('auditable_id', $invoice->id)
            ->where('event', 'updated')->get()->contains(fn (Audit $audit) => ($audit->new_values['status'] ?? null) === Invoice::STATUS_CANCELLED));
    }

    public function test_a_request_takes_only_its_service_off_an_invoice_that_bills_another(): void
    {
        Queue::fake([TerminateJob::class]); // the request's listener runs, on the sync queue
        $this->assertFalse(config('audit.console'));
        $a = $this->service();
        $b = $this->service();
        $invoice = $this->invoice([$a, $b]);

        $this->request($a);

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertSame([$b->id], $this->servicesBilled($invoice));
        $this->assertEqualsWithDelta(10.0, $invoice->total, 0.001);
        $this->assertSame(0, $invoice->adjustmentNotes()->count());
        $this->assertSame(Service::STATUS_CANCELLED, $a->fresh()->status);
        $this->assertSame(Service::STATUS_ACTIVE, $b->fresh()->status);
        // In the audit: the worker audits its job, and puts the console setting back after it.
        $removed = Audit::query()->where('auditable_type', InvoiceItem::class)->where('event', 'deleted')->sole();
        $this->assertSame($a->id, (int) $removed->old_values['reference_id']);
        $this->assertTrue(Audit::query()->where('auditable_type', Service::class)->where('auditable_id', $a->id)->where('event', 'updated')->exists());
        $this->assertFalse(config('audit.console'));
    }

    public function test_credit_on_a_mixed_invoice_keeps_paying_the_rest_and_only_the_part_over_comes_back(): void
    {
        Queue::fake([TerminateJob::class]);
        $a = $this->service();
        $b = $this->service();
        $invoice = $this->invoice([$a, $b], '2026-11-30', '2026-12-30');
        Credit::query()->create(['user_id' => $this->user->id, 'currency_code' => 'USD', 'amount' => 15]);
        (new RecordPaymentService)->payWithCredit($invoice);
        $this->assertEqualsWithDelta(0.0, $this->credit(), 0.001);

        $this->request($a);

        // All of it back (cause «cancellation», not a staff deletion), then B's 10.00 paid again from it: 5.00 left.
        $this->assertSame([$b->id], $this->servicesBilled($invoice));
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertEqualsWithDelta(5.0, $this->credit(), 0.001);
        $this->assertNotEmpty(MoneyEvent::query()->where('invoice_id', $invoice->id)->where('cause', MoneyTrail::CAUSE_CANCELLATION)->get());
        $this->assertSame('2026-12-30', $b->fresh()->expires_at->toDateString(), 'B renewed by what the credit paid');
    }

    public function test_credit_on_a_mixed_invoice_below_the_rest_stays_on_it(): void
    {
        Queue::fake([TerminateJob::class]);
        $a = $this->service();
        $b = $this->service();
        $invoice = $this->invoice([$a, $b]);
        Credit::query()->create(['user_id' => $this->user->id, 'currency_code' => 'USD', 'amount' => 4]);
        (new RecordPaymentService)->payWithCredit($invoice);

        $this->request($a);

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertEqualsWithDelta(6.0, $invoice->fresh()->remaining, 0.001);
        $this->assertEqualsWithDelta(0.0, $this->credit(), 0.001);
        $this->assertSame(0, MoneyEvent::query()->where('cause', MoneyTrail::CAUSE_CANCELLATION)->count());
    }

    public function test_a_line_that_belongs_to_no_one_keeps_a_mixed_invoice_open_for_staff(): void
    {
        Queue::fake([TerminateJob::class]);
        $a = $this->service();
        $b = $this->service();
        $invoice = $this->invoice([$a, $b, -2.00]);

        $left = CancelOpenInvoices::handle([$invoice], 'reason', $a);

        $this->assertStringContainsString('lines that belong to neither', $left["invoice_cancelled #{$invoice->id}"]->getMessage());
        $this->assertSame(3, $invoice->items()->count());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    public function test_an_imported_promotion_goes_with_the_hosting_it_names(): void
    {
        $a = $this->service();
        $b = $this->service();
        $invoice = $this->invoice([$a, $b]);
        // As the WHMCS importer writes a PromoHosting/Setup line: no type, relid = the hosting (services keep WHMCS ids).
        $invoice->items()->create(['reference_type' => null, 'reference_id' => $a->id, 'description' => 'Promo', 'quantity' => 1, 'price' => -2]);

        $this->assertSame([], CancelOpenInvoices::handle([$invoice], 'Cancelled.', $a));

        $this->assertSame([$b->id], $this->servicesBilled($invoice));
        $this->assertEqualsWithDelta(10.0, $invoice->fresh()->total, 0.001);
    }

    public function test_a_promotion_on_an_invoice_of_one_service_goes_with_it(): void
    {
        $a = $this->service();
        $invoice = $this->invoice([$a, -2.00]);

        $this->assertSame([], CancelOpenInvoices::handle([$invoice], 'Cancelled.', $a));

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame('-8.00', (string) $invoice->adjustmentNotes()->sole()->amount);
    }

    public function test_termination_takes_only_its_service_off_an_invoice_imported_from_whmcs(): void
    {
        Queue::fake();
        $a = $this->service(['status' => 'suspended', 'expires_at' => '2026-09-01', 'dunning_suspended_at' => '2026-09-10']);
        $b = $this->service(['expires_at' => '2026-09-01']);
        $invoice = $this->invoice([$a, $b], '2026-09-01', '2026-10-01'); // WHMCS: one invoice for all due that day

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_CANCELLED, $a->fresh()->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame([$b->id], $this->servicesBilled($invoice));
    }

    public function test_a_withdrawn_request_bills_the_service_again_after_its_line_was_taken_off(): void
    {
        Queue::fake([TerminateJob::class]);
        $a = $this->service(['expires_at' => '2026-10-25']);
        $b = $this->service(['expires_at' => '2026-10-25']);
        $invoice = $this->invoice([$a, $b], '2026-10-25', '2026-11-25');
        $request = $this->request($a, 'end_of_period');
        $this->assertSame([$b->id], $this->servicesBilled($invoice));

        $request->delete();
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_ACTIVE, $a->fresh()->status);
        $this->assertTrue($a->invoices()->where('invoices.status', Invoice::STATUS_UNPAID)->exists(), 'its period is billed again');
    }

    public function test_paying_the_rest_does_not_renew_the_service_taken_off(): void
    {
        Queue::fake([TerminateJob::class]);
        $a = $this->service(['expires_at' => '2026-10-25']);
        $b = $this->service(['expires_at' => '2026-10-25']);
        $invoice = $this->invoice([$a, $b], '2026-10-25', '2026-11-25');
        $this->request($a, 'end_of_period');

        (new RecordPaymentService)->handle($invoice, null, 10.00, transactionId: 'bank-1');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('2026-10-25', $a->fresh()->expires_at->toDateString());
        $this->assertSame('2026-11-25', $b->fresh()->expires_at->toDateString());
    }

    public function test_twice_is_once(): void
    {
        $a = $this->service();
        $b = $this->service();
        $invoice = $this->invoice([$a, $b]);

        CancelOpenInvoices::handle([$invoice], 'Cancelled.', $a);
        CancelOpenInvoices::handle([$invoice], 'Cancelled.', $a);

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame([$b->id], $this->servicesBilled($invoice));
    }

    public function test_an_invoice_paid_in_the_meantime_is_left_as_it_is(): void
    {
        $a = $this->service();
        $stale = $this->invoice([$a]);
        (new RecordPaymentService)->handle($stale->id, null, 10.00, transactionId: 'bank-2');

        $this->assertSame([], CancelOpenInvoices::handle([$stale], 'Cancelled.'));

        $this->assertSame(Invoice::STATUS_PAID, $stale->fresh()->status);
        $this->assertSame(0, $stale->adjustmentNotes()->count());
    }

    public function test_gateway_money_keeps_it_open_and_a_processing_payment_does_not(): void
    {
        $a = $this->service();
        $paid = $this->invoice([$a]);
        (new RecordPaymentService)->handle($paid, null, 4.00, transactionId: 'bank-3');
        $processing = $this->invoice([$a]);
        (new RecordPaymentService)->handle($processing, null, 10.00, transactionId: 'sepa-1', status: InvoiceTransactionStatus::Processing);

        $left = CancelOpenInvoices::handle([$paid, $processing], 'Cancelled.');

        $this->assertSame(["invoice_cancelled #{$paid->id}"], array_keys($left));
        $this->assertSame(Invoice::STATUS_UNPAID, $paid->fresh()->status);
        $this->assertSame(Invoice::STATUS_CANCELLED, $processing->fresh()->status);
    }

    public function test_the_upgrade_price_follows_line_by_line_in_the_audit(): void
    {
        Queue::fake();
        $service = $this->service();
        $invoice = $this->invoice([]);
        $bigger = $this->createProduct();
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id, 'plan_id' => $bigger->plan->id, 'product_id' => $bigger->product->id,
            'status' => 'pending', 'invoice_id' => $invoice->id,
        ]);
        $line = $invoice->items()->create([
            'reference_id' => $upgrade->id, 'reference_type' => ServiceUpgrade::class, 'description' => 'Upgrade', 'quantity' => 1, 'price' => 99.99,
        ]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertNotSame('99.99', (string) $line->fresh()->price);
        $this->assertTrue(Audit::query()->where('auditable_type', InvoiceItem::class)->where('auditable_id', $line->id)->where('event', 'updated')->exists());
    }

    public function test_a_queued_job_is_audited_and_the_setting_is_put_back(): void
    {
        $this->assertFalse(config('audit.console'));
        $user = $this->user;

        dispatch(fn () => $user->update(['first_name' => 'Queued']));

        $this->assertTrue(Audit::query()->where('auditable_type', User::class)->where('auditable_id', $user->id)->where('event', 'updated')->exists());
        $this->assertFalse(config('audit.console'));
        $user->update(['first_name' => 'Console']);
        $this->assertSame(1, Audit::query()->where('auditable_type', User::class)->where('auditable_id', $user->id)->where('event', 'updated')->count());
    }
}
