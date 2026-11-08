<?php

namespace Tests\Feature;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\AdjustmentNoteType;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Independent review of the sweep3 l-ledger-invariant fix (REV3-LLI-*): failing tests for what the fix leaves open.
 */
class Rev3LLedgerInvariantTest extends TestCase
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

    /**
     * REV3-LLI-1: a partial write-off gives back the chargeback's take in full and takes the unpaid part again as a FINAL
     * purchase_refunded. A full write-off keeps its take open and gives it back once the write-off is undone and the
     * invoice paid (the fixer's own test); a partial one does not: the customer pays the whole 100 and holds 60.
     */
    public function test_a_partly_written_off_chargeback_gives_the_whole_credit_once_the_write_off_is_undone_and_paid(): void
    {
        $invoice = $this->purchase();
        ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'R3L-1A');
        ExtensionHelper::chargebackAtGateway('Stripe', 'R3L-1A', 'dp_r3l_1a', 100, 'usd');
        $this->assertSame('0.00', $this->balance());

        $note = $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -40, 'description' => 'Part written off']);
        ExtensionHelper::addPayment($invoice->id, null, 60, transactionId: 'R3L-1B');
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('60.00', $this->balance());

        // The write-off undone (as in the fixer's full write-off test): 40 to pay again, and paid.
        $invoice->fresh()->update(['status' => 'unpaid']);
        $note->fresh()->update(['status' => AdjustmentNoteStatus::Voided]);
        ExtensionHelper::addPayment($invoice->id, null, 40, transactionId: 'R3L-1C');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('100.00', $this->balance(), '100 net kept on a paid 100 credit purchase must give 100 credit');
    }
}
