<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Independent review of the money-ledger sweep fixes (2026-11-07). Each test fails on fix/sweep-money-ledger. */
class RevMoneyLedgerTest extends TestCase
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
     * REV-ML-1 (MONEY-LEDGER-1 fix): a credit purchase of 50 paid 30 + 20 gives 50. Staff refund 10 of the first
     * payment (10 of credit goes, 40 left). Then the second payment is deleted: the fix takes back all 40 left and
     * clears applied_at. The reopened invoice owes 20, but paying those 20 is refused (InvoiceObserver: the line
     * would grant 50 for 40 received net), so the customer's payment of exactly what the invoice says it owes throws
     * and the invoice can never be paid again. Before the fix the 20 was taken and the invoice became paid.
     */
    public function test_credit_purchase_refunded_in_part_then_payment_deleted_can_be_paid_again(): void
    {
        $invoice = $this->creditPurchase(50);
        $first = ExtensionHelper::addPayment($invoice->id, null, 30, transactionId: 'REV-T1');
        $second = ExtensionHelper::addPayment($invoice->id, null, 20, transactionId: 'REV-T2');
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('50.00', $this->balance());

        ExtensionHelper::refundLocally($first->fresh(), 10, notify: false);
        $this->assertSame('40.00', $this->balance());

        (new RecordPaymentService)->deleteTransaction($second);
        $owed = (float) $invoice->fresh()->remaining;
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame('20.00', number_format($owed, 2, '.', ''));

        $error = null;
        try {
            ExtensionHelper::addPayment($invoice->id, null, $owed, transactionId: 'REV-T3');
        } catch (\Throwable $e) {
            $error = $e;
        }
        $this->assertNull($error, 'Paying what the reopened invoice owes was refused: ' . ($error?->getMessage() ?? ''));
        $this->assertSame('paid', $invoice->fresh()->status);

        $paid = 30 - 10 + $owed;
        $this->assertSame(number_format($paid, 2, '.', ''), $this->balance(), 'Credit granted is more than the customer paid net');
        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));
    }
}
