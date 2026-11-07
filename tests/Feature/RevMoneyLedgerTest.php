<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
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
     * payment (10 of credit goes, 40 left). Then the second payment is deleted: the fix took back all 40 left and
     * cleared applied_at. The reopened invoice owed 20, but paying those 20 was refused (InvoiceObserver: the line
     * would grant 50 for 40 received net), so the invoice could never be paid again.
     *
     * Fixed by refusing that deletion (its lines cannot give their whole total again for less money): staff refund the
     * payment instead, which takes its credit back and leaves the invoice paid for what it kept. Test corrected from
     * «deleted, then paid again» to «refused, nothing changes; the refund keeps credit = money kept».
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

        $error = null;
        try {
            (new RecordPaymentService)->deleteTransaction($second);
        } catch (DisplayException $e) {
            $error = $e;
        }
        $this->assertNotNull($error, 'Deleting a payment of a credit purchase refunded in part reopened it unpayable');
        $this->assertNotNull($second->fresh(), 'The refused payment stays');
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('40.00', $this->balance());
        $this->assertSame(0, $invoice->items()->where('reference_type', Credit::class)->whereNull('applied_at')->count());

        // What staff do instead: refund it, which takes its credit back too.
        ExtensionHelper::refundLocally($second->fresh(), 20, notify: false);
        $this->assertSame('20.00', $this->balance(), 'Credit left is the money the invoice kept (30 - 10)');
        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));
    }

    /** The same deletion with no refund before it still reopens the invoice, and paying it again gives the credit again. */
    public function test_credit_purchase_payment_deleted_without_refunds_can_be_paid_again(): void
    {
        $invoice = $this->creditPurchase(50);
        ExtensionHelper::addPayment($invoice->id, null, 30, transactionId: 'REV-T4');
        $second = ExtensionHelper::addPayment($invoice->id, null, 20, transactionId: 'REV-T5');
        $this->assertSame('50.00', $this->balance());

        (new RecordPaymentService)->deleteTransaction($second);
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame('0.00', $this->balance());

        ExtensionHelper::addPayment($invoice->id, null, (float) $invoice->fresh()->remaining, transactionId: 'REV-T6');
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('50.00', $this->balance());
        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));
    }
}
