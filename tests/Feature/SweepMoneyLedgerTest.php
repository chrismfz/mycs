<?php

namespace Tests\Feature;

use App\Enums\AdjustmentNoteType;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Adversarial sweep of the money ledger (2026-11-07). Each test fails on 00268d7f for the reason in its docblock. */
class SweepMoneyLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->user = User::factory()->create();
        config(['settings.credits_enabled' => true]);
    }

    private function creditPurchase(float $price): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Credit::class, 'description' => 'Deposit', 'quantity' => 1, 'price' => $price]);

        return $invoice;
    }

    private function balance(): string
    {
        return number_format((float) Credit::where('client_id', $this->user->client()->id)->where('currency_code', 'USD')->sum('amount'), 2, '.', '');
    }

    /**
     * MONEY-LEDGER-1: deleting the payment of a credit purchase takes the bought credit back but leaves applied_at on
     * the line, so paying the reopened invoice again buys nothing: the customer paid 50 and has 0 credit.
     */
    public function test_deleted_credit_purchase_payment_then_paid_again_gives_the_credit(): void
    {
        $invoice = $this->creditPurchase(50);
        $first = ExtensionHelper::addPayment($invoice->id, null, 50, transactionId: 'SWEEP-T1');
        $this->assertSame('50.00', $this->balance());

        (new RecordPaymentService)->deleteTransaction($first);
        $this->assertSame('0.00', $this->balance());
        $this->assertSame('unpaid', $invoice->fresh()->status);

        ExtensionHelper::addPayment($invoice->id, null, 50, transactionId: 'SWEEP-T2');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('50.00', $this->balance(), 'Paid 50 for 50 of credit, but the line still says it gave its credit');
    }

    /** MONEY-LEDGER-1, partial: 30 + 20, delete the 30, pay 30 again: 50 paid in total, 20 of credit. */
    public function test_deleted_part_payment_of_credit_purchase_then_paid_again_gives_all_the_credit(): void
    {
        $invoice = $this->creditPurchase(50);
        $first = ExtensionHelper::addPayment($invoice->id, null, 30, transactionId: 'SWEEP-P1');
        ExtensionHelper::addPayment($invoice->id, null, 20, transactionId: 'SWEEP-P2');
        $this->assertSame('50.00', $this->balance());

        (new RecordPaymentService)->deleteTransaction($first);
        $this->assertSame('20.00', $this->balance());

        ExtensionHelper::addPayment($invoice->id, null, 30, transactionId: 'SWEEP-P3');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('50.00', $this->balance(), 'The customer paid 50 that stays on the invoice and holds 20 of credit');
    }

    /**
     * MONEY-LEDGER-2: a credit payment on an open invoice that staff refunded to credit (the only refund offered for
     * it) makes giveBack -> deleteTransaction throw «has refunds», which rolls back the termination.
     */
    public function test_service_terminates_after_its_open_invoice_credit_payment_was_refunded_to_credit(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 100.00, 'quantity' => 1,
        ]);
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 100]);
        Credit::create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD', 'amount' => 40]);

        $payment = (new RecordPaymentService)->payWithCredit($invoice->id);
        $this->assertNotNull($payment);
        $this->assertSame('40.00', number_format((float) $payment->amount, 2, '.', ''));
        ExtensionHelper::refundToCredit($payment, 40, notify: false);
        $this->assertSame('40.00', $this->balance());

        $error = null;
        try {
            $service->markTerminated();
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNull($error, 'Termination threw: ' . ($error?->getMessage() ?? ''));
        $this->assertSame('terminated', $service->fresh()->status);
        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame('40.00', $this->balance());
    }

    /**
     * MONEY-LEDGER-3: a credit purchase of 50 with a debit note of 5, paid 55, grants 50. Refunding the 55 takes 55 of
     * credit: 5 of the customer's unrelated credit is lost.
     */
    public function test_refunding_credit_purchase_with_debit_note_takes_back_only_the_credit_it_bought(): void
    {
        Credit::create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD', 'amount' => 5]);
        $invoice = $this->creditPurchase(50);
        $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Debit->value, 'amount' => 5, 'description' => 'Fee']);
        $payment = ExtensionHelper::addPayment($invoice->id, null, 55, transactionId: 'SWEEP-DN1');
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('55.00', $this->balance());

        ExtensionHelper::refundLocally($payment, 55, notify: false);

        $this->assertSame('5.00', $this->balance(), 'All 55 went back, but only 50 of credit was bought');
    }

    /** MONEY-LEDGER-3, delete: the same purchase with no other credit cannot be deleted (it takes 55 for 50 granted). */
    public function test_deleting_credit_purchase_with_debit_note_takes_back_only_the_credit_it_bought(): void
    {
        $invoice = $this->creditPurchase(50);
        $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Debit->value, 'amount' => 5, 'description' => 'Fee']);
        $payment = ExtensionHelper::addPayment($invoice->id, null, 55, transactionId: 'SWEEP-DN2');
        $this->assertSame('50.00', $this->balance());

        (new RecordPaymentService)->deleteTransaction($payment);

        $this->assertNull($payment->fresh());
        $this->assertSame('0.00', $this->balance());
    }
}
