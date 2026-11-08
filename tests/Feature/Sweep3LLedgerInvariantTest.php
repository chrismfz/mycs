<?php

namespace Tests\Feature;

use App\Enums\AdjustmentNoteType;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Sweep 3, lens l-ledger-invariant (adversarial verifier): a credit purchase's bought credit must follow the money that
 * stays on it. Each test fails today for the reported reason.
 */
class Sweep3LLedgerInvariantTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => false, 'settings.mail_disable' => true]);
        $this->user = User::factory()->create();
    }

    private function purchase(float $price = 100): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Credit::class, 'description' => 'Deposit', 'quantity' => 1, 'price' => $price]);

        return $invoice;
    }

    private function balance(): string
    {
        return number_format((float) Credit::where('client_id', $this->user->client()->id)->where('currency_code', 'USD')->sum('amount'), 2, '.', '');
    }

    /** L-LEDGER-INVARIANT-1 (a): set back to unpaid in the form, its mistaken payment deleted, then paid for real. */
    public function test_deleting_the_payment_of_a_reopened_credit_purchase_lets_a_new_payment_buy_the_credit_again(): void
    {
        $invoice = $this->purchase();
        $p1 = ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'S3L-P1');
        $this->assertSame('100.00', $this->balance());

        $invoice->fresh()->update(['status' => 'unpaid']); // the form allows paid -> unpaid
        (new RecordPaymentService)->deleteTransaction($p1->fresh());
        $this->assertSame('0.00', $this->balance());

        ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'S3L-P2');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('100.00', $this->balance(), '100 received and kept on a paid credit purchase must give 100 credit');
    }

    /** L-LEDGER-INVARIANT-1 (refund): set back to unpaid, its payment refunded, then paid again. */
    public function test_refunding_the_payment_of_a_reopened_credit_purchase_lets_a_new_payment_buy_the_credit_again(): void
    {
        $invoice = $this->purchase();
        $p1 = ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'S3L-R1');

        $invoice->fresh()->update(['status' => 'unpaid']);
        ExtensionHelper::refundLocally($p1->fresh(), 100, notify: false);
        $this->assertSame('0.00', $this->balance());

        ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'S3L-R2');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('100.00', $this->balance(), '100 received and kept on a paid credit purchase must give 100 credit');
    }

    /** L-LEDGER-INVARIANT-1 (chargeback): P1 60 charged back, P2 40 deleted on the unpaid invoice, paid 100 anew. */
    public function test_a_chargeback_then_a_deleted_payment_then_a_new_payment_gives_the_whole_credit(): void
    {
        $invoice = $this->purchase();
        ExtensionHelper::addPayment($invoice->id, null, 60, transactionId: 'S3L-C1');
        $p2 = ExtensionHelper::addPayment($invoice->id, null, 40, transactionId: 'S3L-C2');
        $this->assertSame('100.00', $this->balance());

        ExtensionHelper::chargebackAtGateway('Stripe', 'S3L-C1', 'dp_s3l_c1', 60, 'usd');
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame('40.00', $this->balance());

        (new RecordPaymentService)->deleteTransaction($p2->fresh());
        $this->assertSame('0.00', $this->balance());

        ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'S3L-C3');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('100.00', $this->balance(), '100 net kept on a paid credit purchase must give 100 credit');
    }

    /** L-LEDGER-INVARIANT-2: fully charged back, the debt written off with a credit note, then closed as paid. */
    public function test_a_charged_back_credit_purchase_closed_by_a_credit_note_gives_no_credit_back(): void
    {
        $invoice = $this->purchase();
        ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'S3L-W1');
        $this->assertSame('100.00', $this->balance());

        ExtensionHelper::chargebackAtGateway('Stripe', 'S3L-W1', 'dp_s3l_w1', 100, 'usd');
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame('0.00', $this->balance());

        $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -100, 'description' => 'Write-off']);
        $fresh = $invoice->fresh();
        if ($fresh->status !== 'paid') {
            $fresh->update(['status' => 'paid']); // remaining 0: the form allows it
        }

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('0.00', $this->balance(), 'no money stays on the invoice: the chargeback\'s take must not be given back');
    }
}
