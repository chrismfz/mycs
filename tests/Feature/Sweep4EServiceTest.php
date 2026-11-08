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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sweep 4, lens e-service (adversarial verifier): a WHMCS invoice the importer brings in already paid has its Hosting
 * lines with no period and applied_at null (ImportFromWhmcs::lineReference). Reopened and paid again, the line counts
 * as a new payment and renews the service once more, though no new money came in. (A native line, applied_at set,
 * in the same flow keeps expires_at: checked while verifying, not kept as a test since it passes.)
 */
class Sweep4EServiceTest extends TestCase
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

    /**
     * As the importer leaves it: an active monthly service paid until 2026-12-01, an older paid invoice of it, and
     * its November invoice paid in WHMCS with a succeeded payment; lines without period, applied_at null.
     *
     * @return array{Service, Invoice, InvoiceTransaction}
     */
    private function importedPaid(bool $appliedAt): array
    {
        Carbon::setTestNow('2026-11-15 10:00');
        $client = User::factory()->create()->client();
        $product = $this->createProduct();

        $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => '2026-11-01', 'currency_code' => 'USD', 'price' => 10.00, 'created_at' => '2025-01-01']);
        // An older invoice of the service (so the November one is not its order's).
        $older = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-01']);
        InvoiceItem::factory()->create(['invoice_id' => $older->id, 'price' => 10, 'quantity' => 1, 'description' => 'Hosting Oct',
            'reference_type' => Service::class, 'reference_id' => $service->id, 'period_start' => '2026-10-01', 'period_end' => '2026-11-01']);
        $older->forceFill(['created_at' => '2026-10-01'])->save();

        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-11-01']);
        $line = InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'price' => 10, 'quantity' => 1, 'description' => 'Hosting Nov',
            'reference_type' => Service::class, 'reference_id' => $service->id]);
        ExtensionHelper::addPayment($invoice->id, null, 10.00, transactionId: 'WHMCS-NOV');
        $this->assertSame('paid', $invoice->fresh()->status, 'precondition: paid');
        $this->assertNull($invoice->fresh()->apply_pending_since, 'precondition: applied');
        $this->assertSame('2026-12-01', $service->fresh()->expires_at->toDateString(), 'precondition: renewed once');

        if (!$appliedAt) {
            // What ImportFromWhmcs::lineReference writes for a Hosting line, paid or not.
            $line->fresh()->forceFill(['period_start' => null, 'period_end' => null, 'applied_at' => null])->save();
        }

        return [$service->fresh(), $invoice->fresh(), $invoice->transactions()->firstOrFail()];
    }

    /** E-SERVICE-1: the imported line (applied_at null) renews the service one more month for the same money. */
    public function test_e_service_1_an_imported_paid_invoice_paid_again_does_not_renew_its_service_again(): void
    {
        [$service, $invoice, $payment] = $this->importedPaid(appliedAt: false);
        $this->assertSame('2026-12-01', $service->fresh()->expires_at->toDateString(), 'precondition');

        (new RecordPaymentService)->deleteTransaction($payment);
        $this->assertSame('unpaid', $invoice->fresh()->status, 'precondition: reopened');
        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, 10);
        $this->assertSame('paid', $invoice->fresh()->status, 'precondition: paid again');

        $line = $invoice->items()->firstOrFail();
        $this->assertSame('2026-12-01', $service->fresh()->expires_at->toDateString(),
            'the same November payment, deleted and recorded again, moved expires_at a month (line period '
            . $line->period_start?->toDateString() . '..' . $line->period_end?->toDateString() . ')');
    }
}
