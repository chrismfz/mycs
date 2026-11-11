<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use MyCS\Extensions\Gateways\S7RevPay\S7RevPay;
use Tests\TestCase;

/**
 * Review of PR #774 (sweep 7, payments), what a single process shows. The two-process findings (REV-774-1, REV-774-2) are in
 * Sweep7PaymentsReviewConcurrencyTest (MariaDB).
 *
 * REV-774-3: the PR tells staff to complete a payment «processing» for 14 days with «Add payment» and its transaction id.
 *            A refund or a chargeback the gateway reported meanwhile waits for that payment (gateway_refunds «waiting»);
 *            only a gateway's own success applies it (ExtensionHelper::addPayment). Completed by staff, it is never
 *            applied: the invoice reads paid in full while the gateway gave the money back.
 * REV-774-4: deleteTransaction takes every «waiting» record as one whose payment «never succeeded» and detaches it. On a
 *            payment that did succeed (REV-774-3, or an apply that failed) the record is a refund the gateway made: the
 *            payment is deleted with no refusal and the refund is left pointing at nothing.
 * REV-774-5: Stripe answers a key of the other mode (test key on a live customer, or the reverse) with the same 404
 *            resource_missing («No such customer …; a similar object exists in live mode, but a test mode key was used»).
 *            The account's customer is replaced, which is what S7-PAYMENTS-2 set out to stop: its saved cards are
 *            attached to the customer that still exists.
 * REV-774-6: charge() now takes the owner from the caller's model; the gateway is then asked with that same model. An
 *            invoice moved to another customer since the model was read is charged to the first customer's card (the
 *            «only the invoice's own account's method pays it» check reads the stale owner), the attempt written under
 *            the new owner.
 */
class Sweep7PaymentsReviewTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
        Cache::flush();
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        $this->client = User::factory()->create()->client();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $this->invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()]);
        $this->invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00']);
    }

    /** A bank debit of 100.00 still processing, of which the gateway reported a refund of 40.00 (made in its dashboard). */
    private function processingWithAWaitingRefund(): InvoiceTransaction
    {
        ExtensionHelper::addProcessingPayment($this->invoice->id, 'Stripe', '100.00', null, 'pi_rev_1', currency: 'USD');
        $payment = InvoiceTransaction::query()->where('transaction_id', 'pi_rev_1')->sole();
        ExtensionHelper::refundedAtGateway('Stripe', 'pi_rev_1', 're_rev_wait', 40, 'usd');
        $this->assertSame(GatewayRefund::ORIGIN_WAITING, GatewayRefund::query()->where('reference', 're_rev_wait')->sole()->origin, 'precondition: it waits for the payment');

        return $payment;
    }

    /**
     * REV-774-3. Invoice 100.00, its payment pi_rev_1 processing, refund re_rev_wait of 40.00 waiting for it. Staff do what
     * the 14-day notice says: «Add payment» of 100.00 with the transaction id. The row is completed; the 40.00 the gateway
     * gave back must be on it (as it is when the gateway's own success completes it), not left waiting for ever.
     */
    public function test_rev_774_3_a_refund_that_waited_is_recorded_when_staff_complete_the_processing_payment(): void
    {
        $payment = $this->processingWithAWaitingRefund();

        (new RecordPaymentService)->recordByStaff($this->invoice->fresh(), null, '100.00', 'pi_rev_1');

        $payment->refresh();
        $this->assertSame(InvoiceTransactionStatus::Succeeded, $payment->status, 'precondition: staff completed the row');
        $refund = GatewayRefund::query()->where('reference', 're_rev_wait')->sole();
        $this->assertNotSame(GatewayRefund::ORIGIN_WAITING, $refund->origin,
            'the gateway refunded 40.00 of a payment staff completed: it still waits, and nothing will apply it');
        $this->assertSame('40.00', number_format((float) $payment->refunded_amount, 2, '.', ''));
    }

    /** REV-774-3, a chargeback: the bank took the 100.00 back; completed by staff, the invoice reads paid and stays so. */
    public function test_rev_774_3_a_chargeback_that_waited_is_recorded_when_staff_complete_the_processing_payment(): void
    {
        ExtensionHelper::addProcessingPayment($this->invoice->id, 'Stripe', '100.00', null, 'pi_rev_1', currency: 'USD');
        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_rev_1', 'dp_rev_wait', 100, 'usd');
        $this->assertSame(GatewayRefund::ORIGIN_CHARGEBACK_WAITING, GatewayRefund::query()->where('reference', 'dp_rev_wait')->sole()->origin, 'precondition: it waits for the payment');

        (new RecordPaymentService)->recordByStaff($this->invoice->fresh(), null, '100.00', 'pi_rev_1');

        $this->assertSame(InvoiceTransactionStatus::Succeeded, InvoiceTransaction::query()->where('transaction_id', 'pi_rev_1')->sole()->status, 'precondition: staff completed the row');
        $this->assertSame(GatewayRefund::ORIGIN_CHARGEBACK, GatewayRefund::query()->where('reference', 'dp_rev_wait')->sole()->origin,
            'the bank charged back 100.00 of a payment staff completed: the chargeback still waits, the invoice reads ' . $this->invoice->fresh()->status);
    }

    /**
     * REV-774-4. The same payment, completed (succeeded, 100.00) with the gateway's refund of 40.00 still «waiting» on it:
     * deleting it must be refused as a payment the gateway refunded is, not pass with the refund detached.
     */
    public function test_rev_774_4_a_succeeded_payment_with_a_refund_still_waiting_on_it_is_not_deleted(): void
    {
        $payment = $this->processingWithAWaitingRefund();
        (new RecordPaymentService)->recordByStaff($this->invoice->fresh(), null, '100.00', 'pi_rev_1');
        $payment->refresh();
        $this->assertSame(InvoiceTransactionStatus::Succeeded, $payment->status);
        if (GatewayRefund::query()->where('reference', 're_rev_wait')->value('origin') !== GatewayRefund::ORIGIN_WAITING) {
            // REV-774-3 fixed: the refund is on the payment now, and the unchanged rule refuses the deletion.
            $this->expectException(DisplayException::class);
            (new RecordPaymentService)->deleteTransaction($payment);

            return;
        }

        try {
            (new RecordPaymentService)->deleteTransaction($payment);
        } catch (DisplayException) {
            // refused: the gateway refunded it
        }

        $this->assertNotNull($payment->fresh(), 'a succeeded payment of 100.00 the gateway refunded 40.00 of was deleted: its «waiting» refund was taken as one of a payment that never succeeded');
    }

    /**
     * REV-774-5. The account's customer cus_LIVE exists at Stripe in live mode with its saved cards; the gateway's key is
     * (for a while) a test key. Stripe says so in its 404. The customer must be kept and the checkout refused, as for
     * every answer that is not «this customer is gone».
     */
    public function test_rev_774_5_a_stripe_key_of_the_other_mode_does_not_replace_the_accounts_customer(): void
    {
        $this->client->properties()->create(['key' => 'stripe_id', 'name' => 'Stripe customer', 'value' => 'cus_LIVE']);
        $stripe = Gateway::query()->where('extension', 'Stripe')->sole();
        $stripe->settings()->create(['key' => 'stripe_secret_key', 'value' => 'sk_test_key', 'encrypted' => true]);
        $stripe->settings()->create(['key' => 'stripe_publishable_key', 'value' => 'pk_test_key']);
        View::addNamespace('gateways.stripe', base_path('extensions/Gateways/Stripe/resources/views'));
        $posted = [];
        Http::fake(function (Request $request) use (&$posted) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/customers/cus_LIVE')) {
                return Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'param' => 'id',
                    'message' => "No such customer: 'cus_LIVE'; a similar object exists in live mode, but a test mode key was used to make this request."]], 404);
            }
            $posted[] = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($request->url(), '/customers') => Http::response(['id' => 'cus_TESTMODE']),
                str_ends_with($request->url(), '/payment_intents') => Http::response(['id' => 'pi_1', 'client_secret' => 'pi_1_secret', 'amount' => 10000, 'currency' => 'usd']),
                default => Http::response([], 404),
            };
        });

        try {
            ExtensionHelper::pay($stripe->fresh(), $this->invoice->fresh());
        } catch (DisplayException) {
            // refused for now: the key cannot see the customer
        }

        $this->assertSame('cus_LIVE', $this->client->properties()->where('key', 'stripe_id')->value('value'),
            'the customer that holds the account\'s saved cards in live mode was replaced by one made with a test key');
        $this->assertNotContains('/v1/customers', $posted, 'a second Stripe customer was made for the account');
    }

    /**
     * REV-774-6. Invoice of 100.00 of the first customer, read by a request (its page, «Pay now»). Staff move it to
     * another customer. The request's charge of the first customer's saved card then runs with the model it read: it
     * must be refused, nothing sent to the gateway.
     */
    public function test_rev_774_6_a_charge_with_a_model_read_before_the_invoice_changed_customer_does_not_charge_the_first_customers_card(): void
    {
        if (!class_exists(S7RevPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\S7RevPay; class S7RevPay {
                /** @var list<string> */ public static array $charged = [];
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) { self::$charged[] = number_format((float) $amount, 2, ".", ""); return true; } }');
        }
        S7RevPay::$charged = [];
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'S7RevPay'], ['name' => 'S7RevPay', 'type' => 'gateway', 'enabled' => true]);
        $card = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_first', 'type' => 'card']);
        $other = User::factory()->create()->client();

        $read = Invoice::query()->findOrFail($this->invoice->id); // the customer's request has read it
        Invoice::query()->findOrFail($this->invoice->id)->update(['client_id' => $other->id]); // staff move it
        $this->assertSame($other->id, (int) Invoice::query()->whereKey($this->invoice->id)->value('client_id'), 'precondition: moved');

        try {
            (new SavedMethodCharge)->charge($read, $card->fresh(), ChargeAttempt::SOURCE_CLIENT);
        } catch (DisplayException) {
            // refused under the locks
        }

        $this->assertSame([], S7RevPay::$charged, 'the first customer\'s card was charged 100.00 for an invoice that is another customer\'s now');
    }
}
