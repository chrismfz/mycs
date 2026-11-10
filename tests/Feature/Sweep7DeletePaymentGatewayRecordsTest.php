<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * S7-MONEY-LEDGER-1: deleting a payment the gateway reported something on (gateway_refunds) is never a raw database error.
 * Deleted with the payment: what moved no money (a dispute opened or resolved, a chargeback won back, a refund the gateway
 * reported failed). Kept waiting without it: a refund or chargeback that waited for a payment that never succeeded.
 * Refused, with a message: a refund or a chargeback the gateway made (recorded here or not).
 */
class Sweep7DeletePaymentGatewayRecordsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Cache::flush();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.credits_enabled' => true,
            'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        $this->invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $this->invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00']);
    }

    private function paid(string $amount = '100.00'): InvoiceTransaction
    {
        ExtensionHelper::addPayment($this->invoice->fresh(), 'Stripe', $amount, null, 'pi_1', currency: 'USD');

        return InvoiceTransaction::query()->where('transaction_id', 'pi_1')->sole();
    }

    private function credit(): string
    {
        return number_format((float) (Credit::query()->where('client_id', $this->user->client()->id)->value('amount') ?? 0), 2, '.', '');
    }

    /** Every journal entry of the invoice is undone or is a reversal: the deletion left nothing standing. */
    private function assertJournalUndone(): void
    {
        $standing = MoneyEvent::query()->where('invoice_id', $this->invoice->id)->where('kind', '!=', 'reversal')->whereDoesntHave('reversal')->count();
        $this->assertSame(0, $standing, 'a journal entry of the deleted payment was left standing');
    }

    public function test_a_payment_with_an_opened_dispute_is_deleted_with_its_record(): void
    {
        $payment = $this->paid();
        ExtensionHelper::chargebackOpened('Stripe', 'pi_1', 'dp_1', 100, 'usd');
        $this->assertSame([GatewayRefund::ORIGIN_DISPUTE], GatewayRefund::query()->pluck('origin')->all());

        (new RecordPaymentService)->deleteTransaction($payment);

        $this->assertNull($payment->fresh());
        $this->assertSame(0, GatewayRefund::query()->count());
        $this->assertSame(Invoice::STATUS_UNPAID, $this->invoice->fresh()->status);
        $this->assertSame('100.00', number_format((float) $this->invoice->fresh()->remaining, 2, '.', ''));
        $this->assertJournalUndone();
    }

    public function test_a_payment_whose_chargeback_was_won_back_is_deleted_with_its_records(): void
    {
        $payment = $this->paid();
        ExtensionHelper::chargebackOpened('Stripe', 'pi_1', 'dp_1', 100, 'usd');
        $this->assertTrue(ExtensionHelper::chargebackAtGateway('Stripe', 'pi_1', 'dp_1', 100, 'usd'));
        $this->assertTrue(ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_1', 'dp_1', 100, 'usd'));
        $this->assertSame('0.00', number_format((float) $payment->fresh()->refunded_amount, 2, '.', ''));
        $this->assertContains(GatewayRefund::ORIGIN_CHARGEBACK_WON, GatewayRefund::query()->pluck('origin')->all());

        (new RecordPaymentService)->deleteTransaction($payment->fresh());

        $this->assertNull($payment->fresh());
        $this->assertSame(0, GatewayRefund::query()->count());
        $this->assertSame(Invoice::STATUS_UNPAID, $this->invoice->fresh()->status);
        $this->assertSame('0.00', $this->credit());
        $this->assertJournalUndone();
    }

    /** An overpayment's credit is taken back as always, with a dispute record on the payment. */
    public function test_the_credit_an_overpayment_gave_is_taken_back_when_its_disputed_payment_is_deleted(): void
    {
        $payment = $this->paid('130.00');
        $this->assertSame('30.00', $this->credit());
        ExtensionHelper::chargebackOpened('Stripe', 'pi_1', 'dp_1', 130, 'usd');

        (new RecordPaymentService)->deleteTransaction($payment);

        $this->assertNull($payment->fresh());
        $this->assertSame('0.00', $this->credit());
        $this->assertSame(0, GatewayRefund::query()->count());
    }

    public function test_a_refund_the_gateway_reported_failed_goes_with_its_payment(): void
    {
        $payment = $this->paid();
        ExtensionHelper::refundFailedAtGateway('Stripe', 're_failed', 'pi_1', 40, 'usd');
        $this->assertSame([GatewayRefund::ORIGIN_FAILED], GatewayRefund::query()->where('invoice_transaction_id', $payment->id)->pluck('origin')->all());

        (new RecordPaymentService)->deleteTransaction($payment);

        $this->assertNull($payment->fresh());
        $this->assertSame(0, GatewayRefund::query()->count());
    }

    /** A chargeback standing on the payment is money gone: refused as a payment with refunds always was. */
    public function test_a_payment_with_a_standing_chargeback_is_refused_with_a_message(): void
    {
        $payment = $this->paid();
        $this->assertTrue(ExtensionHelper::chargebackAtGateway('Stripe', 'pi_1', 'dp_1', 100, 'usd'));

        try {
            (new RecordPaymentService)->deleteTransaction($payment->fresh());
            $this->fail('a payment the gateway took back was deleted');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('refunds', $e->getMessage());
        }
        $this->assertNotNull($payment->fresh());
        $this->assertSame(1, GatewayRefund::query()->where('origin', GatewayRefund::ORIGIN_CHARGEBACK)->count());
    }

    /** A gateway refund in another currency records no amount here (staff record it by hand): real money, so refused, by name. */
    public function test_a_payment_with_a_gateway_refund_not_recorded_here_is_refused_by_its_reference(): void
    {
        $payment = $this->paid();
        ExtensionHelper::refundedAtGateway('Stripe', 'pi_1', 're_eur', 40, 'eur');
        $refund = GatewayRefund::query()->where('reference', 're_eur')->sole();
        $this->assertSame(GatewayRefund::ORIGIN_GATEWAY, $refund->origin);
        $this->assertSame('0.00', number_format((float) $payment->fresh()->refunded_amount, 2, '.', ''), 'precondition: nothing recorded on the payment');

        try {
            (new RecordPaymentService)->deleteTransaction($payment->fresh());
            $this->fail('a payment the gateway refunded was deleted');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('re_eur', $e->getMessage());
            $this->assertStringContainsString('by hand', $e->getMessage());
        }
        $this->assertNotNull($payment->fresh());
        $this->assertNotNull($refund->fresh());
        $this->assertSame(Invoice::STATUS_PAID, $this->invoice->fresh()->status, 'nothing changed: the refusal comes before any reversal');
        $this->assertSame(1, MoneyEvent::query()->where('invoice_id', $this->invoice->id)->count());
    }

    /**
     * A refund reported for a payment still processing waits for it. Staff delete the payment (it never came): the refund
     * keeps waiting by the payment's id, and the success the gateway reports later is recorded with its refund.
     */
    public function test_a_waiting_refund_outlives_its_deleted_processing_payment_and_is_applied_to_a_late_success(): void
    {
        ExtensionHelper::addProcessingPayment($this->invoice->id, 'Stripe', '100.00', null, 'pi_1', currency: 'USD');
        $payment = InvoiceTransaction::query()->where('transaction_id', 'pi_1')->sole();
        ExtensionHelper::refundedAtGateway('Stripe', 'pi_1', 're_wait', 40, 'usd');
        $this->assertSame($payment->id, GatewayRefund::query()->where('reference', 're_wait')->sole()->invoice_transaction_id);

        (new RecordPaymentService)->deleteTransaction($payment);

        $this->assertNull($payment->fresh());
        $waiting = GatewayRefund::query()->where('reference', 're_wait')->sole();
        $this->assertSame(GatewayRefund::ORIGIN_WAITING, $waiting->origin);
        $this->assertNull($waiting->invoice_transaction_id);

        ExtensionHelper::addPayment($this->invoice->id, 'Stripe', '100.00', null, 'pi_1', currency: 'USD');
        ExtensionHelper::applyWaitingRefunds('Stripe', 'pi_1');

        $late = InvoiceTransaction::query()->where('transaction_id', 'pi_1')->sole();
        $this->assertSame(InvoiceTransactionStatus::Succeeded, $late->status);
        $this->assertSame('40.00', number_format((float) $late->fresh()->refunded_amount, 2, '.', ''));
        $this->assertSame($late->id, GatewayRefund::query()->where('reference', 're_wait')->sole()->invoice_transaction_id);
    }
}
