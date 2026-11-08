<?php

namespace Tests\Feature;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\AdjustmentNoteType;
use App\Enums\MoneyEventKind;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\MoneyEvent;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The fix of sweep3 L-LEDGER-INVARIANT-1/2: bought credit taken while a credit purchase is open again is given back when
 * it is paid again, only as far as money stays on it; and the journal keeps showing what it did.
 */
class Sweep3LedgerInvariantFixTest extends TestCase
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

    public function test_a_deletion_on_a_reopened_purchase_is_journaled_as_a_take_and_given_back_once(): void
    {
        $invoice = $this->purchase();
        $p1 = ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'S3F-D1');
        $invoice->fresh()->update(['status' => 'unpaid']);
        (new RecordPaymentService)->deleteTransaction($p1->fresh());

        $take = MoneyEvent::query()->where('invoice_id', $invoice->id)->where('cause', MoneyTrail::CAUSE_PURCHASE_REOPENED)->sole();
        $this->assertSame(MoneyEventKind::CreditOut, $take->kind);
        $this->assertSame('100.00', (string) $take->amount);

        ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'S3F-D2');
        $this->assertSame('100.00', $this->balance());
        $this->assertTrue($take->fresh()->reversal()->exists(), 'given back by reversing the take');
        $this->assertSame([], RecordPaymentService::chargebackTakes($invoice)->all());

        // Set back to unpaid and paid again by the form (nothing taken meanwhile): nothing more is given.
        $invoice->fresh()->update(['status' => 'unpaid']);
        $invoice->fresh()->update(['status' => 'paid']);
        $this->assertSame('100.00', $this->balance());
        $this->assertSame([], (new MoneyCheck)->run());
    }

    public function test_a_partial_refund_on_a_reopened_purchase_takes_only_its_part(): void
    {
        $invoice = $this->purchase();
        $p1 = ExtensionHelper::addPayment($invoice->id, null, 60, transactionId: 'S3F-P1');
        ExtensionHelper::addPayment($invoice->id, null, 40, transactionId: 'S3F-P2');
        $invoice->fresh()->update(['status' => 'unpaid']);

        ExtensionHelper::refundLocally($p1->fresh(), 60, notify: false);
        $this->assertSame('40.00', $this->balance(), 'only the refunded part of the credit goes');
        $this->assertSame(4000, (new RecordPaymentService)->boughtCreditLeftCents($invoice->fresh()));

        ExtensionHelper::addPayment($invoice->id, null, 60, transactionId: 'S3F-P3');
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('100.00', $this->balance());
        $this->assertSame(10000, (new RecordPaymentService)->boughtCreditLeftCents($invoice->fresh()));
    }

    public function test_a_charged_back_purchase_partly_written_off_gives_back_only_the_money_paid(): void
    {
        $invoice = $this->purchase();
        ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'S3F-W1');
        ExtensionHelper::chargebackAtGateway('Stripe', 'S3F-W1', 'dp_s3f_w1', 100, 'usd');
        $this->assertSame('0.00', $this->balance());

        $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -40, 'description' => 'Part written off']);
        ExtensionHelper::addPayment($invoice->id, null, 60, transactionId: 'S3F-W2');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('60.00', $this->balance(), '60 paid and kept: 60 credit, not the 100 the chargeback took');
        $this->assertSame(6000, (new RecordPaymentService)->boughtCreditLeftCents($invoice->fresh()), 'what a later refund may take back');
    }

    public function test_a_written_off_chargeback_take_comes_back_when_the_write_off_is_undone_and_paid(): void
    {
        $invoice = $this->purchase();
        ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'S3F-U1');
        ExtensionHelper::chargebackAtGateway('Stripe', 'S3F-U1', 'dp_s3f_u1', 100, 'usd');
        $note = $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -100, 'description' => 'Write-off']);
        $fresh = $invoice->fresh();
        if ($fresh->status !== 'paid') {
            $fresh->update(['status' => 'paid']);
        }
        $this->assertSame('0.00', $this->balance());
        $this->assertCount(1, RecordPaymentService::chargebackTakes($invoice), 'the take stays');

        $invoice->fresh()->update(['status' => 'unpaid']);
        $note->fresh()->update(['status' => AdjustmentNoteStatus::Voided]);
        ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'S3F-U2');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('100.00', $this->balance());
    }

    public function test_a_refund_of_a_paid_purchase_stays_final(): void
    {
        $invoice = $this->purchase();
        $p1 = ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'S3F-F1');
        ExtensionHelper::refundLocally($p1->fresh(), 30, notify: false);

        $this->assertSame('70.00', $this->balance());
        $this->assertSame(1, MoneyEvent::query()->where('invoice_id', $invoice->id)->where('cause', MoneyTrail::CAUSE_PURCHASE_REFUNDED)->count());
        $this->assertSame([], RecordPaymentService::chargebackTakes($invoice)->all(), 'a refund of a paid purchase is never given back');
    }
}
