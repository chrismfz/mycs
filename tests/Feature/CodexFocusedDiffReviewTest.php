<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Focused review of 92246c5..8a95af7 (Codex, docs/payments.md): deleting a refunded transaction, and the database
 * default of an omitted status. The reopen/payment race is in CodexReopenPaymentRaceReviewTest: as first written
 * here it ran the payment synchronously inside the reopening connection's query listener, which cannot work once
 * reopening holds the customer and invoice locks (the payment would wait for its own caller).
 */
class CodexFocusedDiffReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 10:00:00');
        config(['settings.credits_enabled' => true]);
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => 10.00]);

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

    private function invoiceFor(Service $service, string $start, string $end, string $status = 'unpaid', float $price = 10.00): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => $status === Invoice::STATUS_PAID ? Invoice::STATUS_UNPAID : $status, 'currency_code' => 'USD']);
        $invoice->items()->create([
            'reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => $price, 'period_start' => $start, 'period_end' => $end,
        ]);

        // A paid fixture (a period paid in the past): stored paid, its lines added while it was open (MT-1c: a paid
        // invoice keeps its lines, and is never created paid).
        if ($status === Invoice::STATUS_PAID) {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        }

        return $invoice->fresh();
    }

    private function credit(): string
    {
        return number_format((float) (Credit::query()->where('client_id', $this->user->client()->id)->where('currency_code', 'USD')->value('amount') ?? 0), 2, '.', '');
    }

    public function test_refunded_transaction_cannot_be_deleted_and_replayed(): void
    {
        $invoice = $this->invoiceFor($this->service(), '2026-10-15', '2026-11-15');
        $txn = ExtensionHelper::addPayment($invoice->id, 'Stripe', 15, transactionId: 'PAID-REFUNDED', currency: 'USD');
        ExtensionHelper::refundLocally($txn, 10);
        try {
            (new RecordPaymentService)->deleteTransaction($txn);
        } catch (DisplayException) {
            $this->assertNotNull($txn->fresh());

            return;
        }
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 15, transactionId: 'PAID-REFUNDED', currency: 'USD');
        $this->assertSame('5.00', $this->credit(), 'Received 15, refunded 10: replay after deletion must not create 15 credit');
    }

    public function test_omitted_status_uses_database_succeeded_default(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $txn = $invoice->transactions()->create(['amount' => 10]);
        $this->assertSame(InvoiceTransactionStatus::Succeeded, $txn->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('2026-11-15', $service->fresh()->expires_at->toDateString());
    }
}
