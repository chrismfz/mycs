<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sweep 4 E-SERVICE-1, the fix (InvoiceObserver::markAppliedBeforeMyCs): a renewal line an imported invoice paid before
 * MyCS kept applied_at is marked applied when the invoice is reopened, and only that one: a line MyCS still has to
 * apply, one of an ended service, or one nothing shows was renewed, renews on its next payment as before.
 */
class Sweep4EServiceFixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{Service, Invoice, InvoiceTransaction, InvoiceItem} as the importer leaves it (Sweep4EServiceTest) */
    private function importedPaid(): array
    {
        Carbon::setTestNow('2026-11-15 10:00');
        $client = User::factory()->create()->client();
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => '2026-11-01', 'currency_code' => 'USD', 'price' => 10.00, 'created_at' => '2025-01-01']);
        $older = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-01']);
        InvoiceItem::factory()->create(['invoice_id' => $older->id, 'price' => 10, 'quantity' => 1, 'description' => 'Hosting Oct',
            'reference_type' => Service::class, 'reference_id' => $service->id, 'period_start' => '2026-10-01', 'period_end' => '2026-11-01']);
        $older->forceFill(['created_at' => '2026-10-01'])->save();

        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-11-01']);
        $line = InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'price' => 10, 'quantity' => 1, 'description' => 'Hosting Nov',
            'reference_type' => Service::class, 'reference_id' => $service->id]);
        ExtensionHelper::addPayment($invoice->id, null, 10.00, transactionId: 'WHMCS-NOV');
        $this->assertSame('2026-12-01', $service->fresh()->expires_at->toDateString(), 'precondition: renewed once');
        $line->fresh()->forceFill(['period_start' => null, 'period_end' => null, 'applied_at' => null])->save();

        return [$service->fresh(), $invoice->fresh(), $invoice->transactions()->firstOrFail(), $line->fresh()];
    }

    private function reopenAndPayAgain(Invoice $invoice, InvoiceTransaction $payment): void
    {
        (new RecordPaymentService)->deleteTransaction($payment);
        $this->assertSame('unpaid', $invoice->fresh()->status, 'precondition: reopened');
        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, 10);
        $this->assertSame('paid', $invoice->fresh()->status, 'precondition: paid again');
    }

    public function test_the_line_is_marked_applied_as_of_its_first_payment_when_reopened(): void
    {
        [$service, $invoice, $payment, $line] = $this->importedPaid();
        $paidAt = $invoice->paid_at;

        (new RecordPaymentService)->deleteTransaction($payment);

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame($paidAt->toDateTimeString(), $line->fresh()->applied_at?->toDateTimeString(), 'applied as of when it was paid');
        $this->assertNull($line->fresh()->period_start, 'its period is not guessed');
    }

    public function test_set_back_to_unpaid_by_staff_then_paid_again_renews_nothing(): void
    {
        [$service, $invoice, $payment] = $this->importedPaid();
        $invoice->status = Invoice::STATUS_UNPAID; // EditInvoice «Set to unpaid»
        $invoice->save();
        $this->assertNotNull($invoice->items()->firstOrFail()->applied_at);

        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, 10);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('2026-12-01', $service->fresh()->expires_at->toDateString(), 'no second period for the same money');
    }

    public function test_a_line_mycs_still_has_to_apply_renews_when_paid_again(): void
    {
        [$service, $invoice, $payment, $line] = $this->importedPaid();
        // Paid here, its application failed (BIL-9): the service never moved.
        DB::table('invoices')->where('id', $invoice->id)->update(['apply_pending_since' => now()->subHour()]);
        DB::table('services')->where('id', $service->id)->update(['expires_at' => '2026-11-01']);

        $this->reopenAndPayAgain($invoice->fresh(), $payment);

        $this->assertNotNull($line->fresh()->applied_at, 'applied by its new payment');
        $this->assertSame('2026-12-01', $service->fresh()->expires_at->toDateString(), 'the payment buys its period');
    }

    public function test_a_line_whose_service_never_ran_past_its_due_date_renews_when_paid_again(): void
    {
        [$service, $invoice, $payment, $line] = $this->importedPaid();
        // Nothing shows it renewed the service (staff moved the date back, or it never applied): left to renew.
        DB::table('services')->where('id', $service->id)->update(['expires_at' => '2026-11-01']);

        (new RecordPaymentService)->deleteTransaction($payment);
        $this->assertNull($line->fresh()->applied_at, 'not marked');
        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, 10);

        $this->assertSame('2026-12-01', $service->fresh()->expires_at->toDateString(), 'the payment buys its period');
    }

    public function test_a_line_of_an_ended_service_stays_not_applied(): void
    {
        [$service, $invoice, $payment, $line] = $this->importedPaid();
        DB::table('services')->where('id', $service->id)->update(['status' => Service::STATUS_TERMINATED]);

        $this->reopenAndPayAgain($invoice, $payment);

        $this->assertNull($line->fresh()->applied_at, 'its payment bought nothing: the money check still sees it');
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status, 'never revived');
    }

    public function test_a_charge_line_is_never_marked(): void
    {
        [$service, $invoice, $payment, $line] = $this->importedPaid();
        DB::table('invoice_items')->where('id', $line->id)->update(['kind' => InvoiceItem::KIND_CHARGE]);

        (new RecordPaymentService)->deleteTransaction($payment);

        $this->assertNull($line->fresh()->applied_at);
    }
}
