<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Events\InvoiceTransaction\Updated;
use App\Helpers\ExtensionHelper;
use App\Listeners\SendMailListener;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\GatewayReference;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The neighbours of the three sweep-10 fixes (S10-PAYMENTS-1, S10-PAYMENTS-2, S10-MONEY-LEDGER-1): what must stay as it
 * was, and the other answers of the new rules. Invented fixtures only.
 */
class Sweep10PaymentsLedgerFixTest extends TestCase
{
    use RefreshDatabase;

    private const STRIPE_SECRET = 'whsec_sweep10fix';

    private const PAYPAL_API = 'https://api-m.sandbox.paypal.com';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        Cache::flush();
        $this->user = User::factory()->create();
    }

    // ---- S10-PAYMENTS-1: Stripe's payment_failed (and canceled) on a payment that is processing here ----

    private function stripeInvoice(): Invoice
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        foreach (['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::STRIPE_SECRET] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => now()->toDateString()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);

        return $invoice->fresh();
    }

    /** @return array<string, mixed> */
    private function intent(Invoice $invoice, string $status): array
    {
        return ['id' => 'pi_s10fix', 'object' => 'payment_intent', 'amount' => 1000, 'currency' => 'usd', 'status' => $status,
            'metadata' => ['mycs' => GatewayReference::mark(), 'invoice_id' => (string) $invoice->id]];
    }

    private function stripeWebhook(string $id, string $type, array $object)
    {
        $payload = json_encode(['id' => $id, 'created' => 1794304800, 'type' => $type, 'data' => ['object' => $object]]);
        $time = time();

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1=" . hash_hmac('sha256', $time . '.' . $payload, self::STRIPE_SECRET),
        ], $payload);
    }

    private function stripeSays(string|int $answer): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents/pi_s10fix' => is_int($answer)
            ? Http::response(['error' => ['message' => 'unavailable']], $answer)
            : Http::response(['id' => 'pi_s10fix', 'object' => 'payment_intent', 'status' => $answer])]);
    }

    private function failedMails(): int
    {
        return Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === SendMailListener::class
            && $job->data[0] instanceof Updated && $job->data[0]->invoiceTransaction->status === InvoiceTransactionStatus::Failed)->count();
    }

    /** A bank debit that bounced: Stripe, asked, says the intent wants a new method. Failed, the customer mailed, once. */
    public function test_a_failure_stripe_confirms_fails_the_processing_payment_and_mails_the_customer_once(): void
    {
        $invoice = $this->stripeInvoice();
        $this->stripeWebhook('evt_fix_1', 'payment_intent.processing', $this->intent($invoice, 'processing'))->assertOk();
        $this->assertSame(InvoiceTransactionStatus::Processing, $invoice->transactions()->sole()->status);
        Http::assertNothingSent(); // no failed payment of the intent: nothing to ask

        $this->stripeSays('requires_payment_method');
        $this->stripeWebhook('evt_fix_2', 'payment_intent.payment_failed', $this->intent($invoice, 'requires_payment_method'))->assertOk();

        $this->assertSame(InvoiceTransactionStatus::Failed, $invoice->transactions()->sole()->status);
        $this->assertFalse(SavedMethodCharge::onItsWay($invoice->fresh()));
        $this->assertSame(1, $this->failedMails());
        Http::assertSentCount(1);

        // The same event again: the payment is failed already, Stripe is not asked, nothing more is mailed.
        $this->stripeWebhook('evt_fix_2', 'payment_intent.payment_failed', $this->intent($invoice, 'requires_payment_method'))->assertOk();
        $this->assertSame(InvoiceTransactionStatus::Failed, $invoice->transactions()->sole()->status);
        $this->assertSame(1, $this->failedMails());
        Http::assertSentCount(1);
    }

    public function test_a_cancelled_intent_fails_the_processing_payment(): void
    {
        $invoice = $this->stripeInvoice();
        $this->stripeWebhook('evt_fix_1', 'payment_intent.processing', $this->intent($invoice, 'processing'))->assertOk();
        $this->stripeSays('canceled');

        $this->stripeWebhook('evt_fix_2', 'payment_intent.canceled', $this->intent($invoice, 'canceled'))->assertOk();

        $this->assertSame(InvoiceTransactionStatus::Failed, $invoice->transactions()->sole()->status);
    }

    /** No payment of the intent here: the failed one is recorded as before, and Stripe is not asked. */
    public function test_a_failure_of_an_intent_with_no_payment_here_records_the_failed_payment(): void
    {
        $invoice = $this->stripeInvoice();

        $this->stripeWebhook('evt_fix_1', 'payment_intent.payment_failed', $this->intent($invoice, 'requires_payment_method'))->assertOk();

        $payment = $invoice->transactions()->sole();
        $this->assertSame(InvoiceTransactionStatus::Failed, $payment->status);
        $this->assertSame('pi_s10fix', $payment->transaction_id);
        Http::assertNothingSent();
    }

    /** The money came at Stripe and its «succeeded» is still on its way here: an old failure does not fail the payment. */
    public function test_a_failure_for_an_intent_that_succeeded_at_stripe_leaves_the_payment_processing(): void
    {
        $invoice = $this->stripeInvoice();
        $this->stripeWebhook('evt_fix_1', 'payment_intent.processing', $this->intent($invoice, 'processing'))->assertOk();
        $this->stripeSays('succeeded');

        $this->stripeWebhook('evt_fix_2', 'payment_intent.payment_failed', $this->intent($invoice, 'requires_payment_method'))->assertOk();

        $this->assertSame(InvoiceTransactionStatus::Processing, $invoice->transactions()->sole()->status);
        $this->assertSame(0, $this->failedMails());

        $this->stripeWebhook('evt_fix_3', 'payment_intent.succeeded', $this->intent($invoice, 'succeeded'))->assertOk();
        $this->assertSame(InvoiceTransactionStatus::Succeeded, $invoice->transactions()->sole()->status);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /** Stripe does not answer: nothing changes and the webhook fails, so Stripe sends the event again. */
    public function test_a_failure_while_stripe_cannot_be_asked_changes_nothing_and_is_retried(): void
    {
        $invoice = $this->stripeInvoice();
        $this->stripeWebhook('evt_fix_1', 'payment_intent.processing', $this->intent($invoice, 'processing'))->assertOk();
        $this->stripeSays(503);

        $this->stripeWebhook('evt_fix_2', 'payment_intent.payment_failed', $this->intent($invoice, 'requires_payment_method'))->assertStatus(500);

        $this->assertSame(InvoiceTransactionStatus::Processing, $invoice->transactions()->sole()->status);
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()));
    }

    /** The mirror (S6-PAYMENTS-1) reads the intent the same way: a late «processing» on a failed payment stays failed. */
    public function test_a_late_processing_on_a_failed_payment_stays_failed_unless_stripe_says_processing(): void
    {
        $invoice = $this->stripeInvoice();
        $this->stripeWebhook('evt_fix_1', 'payment_intent.payment_failed', $this->intent($invoice, 'requires_payment_method'))->assertOk();
        $this->stripeSays('requires_payment_method');

        $this->stripeWebhook('evt_fix_2', 'payment_intent.processing', $this->intent($invoice, 'processing'))->assertOk();

        $this->assertSame(InvoiceTransactionStatus::Failed, $invoice->transactions()->sole()->status);
        Http::assertSentCount(1);
    }

    // ---- S10-PAYMENTS-2: PayPal's completed capture whose order cannot be read ----

    private function paypalCompleted(mixed $orderAnswer): array
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        foreach (['test_mode' => '1', 'client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'wh'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => now()->toDateString()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 20]);
        $invoice = $invoice->fresh();
        Http::fake([
            self::PAYPAL_API . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::PAYPAL_API . '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
            self::PAYPAL_API . '/v2/checkout/orders/ORDER-S10FIX' => is_callable($orderAnswer) ? $orderAnswer($invoice) : $orderAnswer,
        ]);
        /** @var PayPal $paypal */
        $paypal = ExtensionHelper::getExtension('gateway', 'PayPal', $gateway->fresh()->settings);
        $paypal->boot();
        PayPal::rememberOrder('ORDER-S10FIX', $invoice);
        ExtensionHelper::addProcessingPayment($invoice->id, 'PayPal', 20.00, null, 'CAP-S10FIX', currency: 'USD');
        $send = fn () => $this->postJson('/extensions/paypal/webhook', ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => [
            'id' => 'CAP-S10FIX', 'status' => 'COMPLETED', 'amount' => ['value' => '20.00', 'currency_code' => 'USD'],
            'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-S10FIX']],
        ]]);

        return [$invoice, $send];
    }

    public function test_a_completed_capture_whose_order_does_not_exist_is_still_ignored(): void
    {
        [$invoice, $send] = $this->paypalCompleted(Http::response(['name' => 'RESOURCE_NOT_FOUND', 'details' => [['issue' => 'INVALID_RESOURCE_ID']]], 404));

        $send()->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(InvoiceTransactionStatus::Processing, $invoice->transactions()->sole()->status);
    }

    /** No answer about the order: PayPal down (5xx), or our credentials refused (401 after a new token, 403). */
    #[DataProvider('noAnswerAboutTheOrder')]
    public function test_a_completed_capture_whose_order_read_got_no_answer_is_retried(int $status, string $name): void
    {
        [$invoice, $send] = $this->paypalCompleted(Http::response(['name' => $name], $status));

        $send()->assertStatus(500);

        $this->assertSame(InvoiceTransactionStatus::Processing, $invoice->transactions()->sole()->status);
    }

    /** @return array<string, array{int, string}> */
    public static function noAnswerAboutTheOrder(): array
    {
        return [
            '503' => [503, 'SERVICE_UNAVAILABLE'],
            '500' => [500, 'INTERNAL_SERVER_ERROR'],
            '401' => [401, 'AUTHENTICATION_FAILURE'],
            '403' => [403, 'NOT_AUTHORIZED'],
        ];
    }

    /** Rate-limited, then answered on PayPal's retry: recorded once; the event a third time is a replay. */
    public function test_a_completed_capture_is_recorded_once_on_the_retry_after_a_rate_limit(): void
    {
        [$invoice, $send] = $this->paypalCompleted(fn (Invoice $invoice) => Http::sequence()
            ->push(['name' => 'RATE_LIMIT_REACHED'], 429)
            ->whenEmpty(Http::response(['id' => 'ORDER-S10FIX', 'status' => 'COMPLETED', 'purchase_units' => [[
                'invoice_id' => GatewayReference::paypalInvoiceId($invoice),
                'payments' => ['captures' => [['id' => 'CAP-S10FIX', 'status' => 'COMPLETED', 'amount' => ['value' => '20.00', 'currency_code' => 'USD'],
                    'seller_receivable_breakdown' => ['paypal_fee' => ['value' => '0.98', 'currency_code' => 'USD']]]]],
            ]]])));

        $send()->assertStatus(500);
        $this->assertSame(InvoiceTransactionStatus::Processing, $invoice->transactions()->sole()->status);

        $send()->assertOk();
        $send()->assertOk();

        $payment = $invoice->transactions()->sole();
        $this->assertSame(InvoiceTransactionStatus::Succeeded, $payment->status);
        $this->assertSame(2000, RecordPaymentService::toCents($payment->amount));
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    // ---- S10-MONEY-LEDGER-1: a gateway refund or chargeback, and the invoice it leaves ----

    /**
     * An invoice of 100.00 for a renewal, $paid of it paid by card (pi_s10fix).
     *
     * @return array{invoice: Invoice, service: Service, line: InvoiceItem, client: int, expires: string}
     */
    private function renewalInvoice(string $paid): array
    {
        $this->freezeTime();
        $this->storeSettings(['credits_enabled' => true, 'credits_auto_use' => true]);
        $client = $this->user->client()->id;
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => 100.00]);
        $service = Service::factory()->create(['client_id' => $client, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'expires_at' => now()->addDays(5)->startOfDay(), 'currency_code' => 'USD', 'price' => 100.00]);
        $invoice = Invoice::factory()->create(['client_id' => $client, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addDays(5)]);
        $line = $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100, 'reference_type' => Service::class, 'reference_id' => $service->id,
            'period_start' => now()->addDays(5)->toDateString(), 'period_end' => now()->addDays(5)->addMonth()->toDateString()]);
        $expires = $service->fresh()->expires_at->toDateString();
        (new RecordPaymentService)->handle($invoice->id, $gateway, $paid, null, 'pi_s10fix');

        return ['invoice' => $invoice, 'service' => $service, 'line' => $line, 'client' => $client, 'expires' => $expires];
    }

    /**
     * @param  array{invoice: Invoice, service: Service, client: int, expires: string}  $made
     * @return array{refunded: int, credited: int, credit: int, status: string, remaining: int, renewed: bool}
     */
    private function ledger(array $made): array
    {
        $payment = InvoiceTransaction::query()->where('transaction_id', 'pi_s10fix')->sole();
        $invoice = $made['invoice']->fresh();

        return [
            'refunded' => RecordPaymentService::toCents($payment->refunded_amount),
            'credited' => RecordPaymentService::toCents($payment->credited_amount),
            'credit' => RecordPaymentService::toCents(Credit::query()->where('client_id', $made['client'])->value('amount') ?? 0),
            'status' => $invoice->status,
            'remaining' => RecordPaymentService::toCents($invoice->remaining),
            'renewed' => $made['service']->fresh()->expires_at->toDateString() !== $made['expires'],
        ];
    }

    /** As on main: an exactly-paid invoice whose whole payment is refunded at the gateway is refunded, its service as it was. */
    public function test_a_gateway_refund_of_an_exactly_paid_invoice_is_as_before(): void
    {
        $made = $this->renewalInvoice('100.00');
        $this->assertSame(Invoice::STATUS_PAID, $made['invoice']->fresh()->status);

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_s10fix', 're_s10fix', 100.00, 'USD');

        $this->assertSame(['refunded' => 10000, 'credited' => 0, 'credit' => 0, 'status' => Invoice::STATUS_REFUNDED, 'remaining' => 0, 'renewed' => true], $this->ledger($made));
    }

    /** As on main: an exactly-paid invoice whose payment is charged back is owed again. */
    public function test_a_chargeback_of_an_exactly_paid_invoice_is_as_before(): void
    {
        $made = $this->renewalInvoice('100.00');

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_s10fix', 'dp_s10fix', 100.00, 'USD');

        $this->assertSame(['refunded' => 10000, 'credited' => 0, 'credit' => 0, 'status' => Invoice::STATUS_UNPAID, 'remaining' => 10000, 'renewed' => true], $this->ledger($made));
    }

    /** As on main: 80.00 on an invoice of 100.00 that was not lowered, 20.00 refunded to credit, the other 60.00 at the gateway. */
    public function test_refund_to_credit_then_a_gateway_refund_of_the_rest_on_an_invoice_not_lowered(): void
    {
        $made = $this->renewalInvoice('80.00');
        ExtensionHelper::refundToCredit(InvoiceTransaction::query()->where('transaction_id', 'pi_s10fix')->sole(), 20.00, false);
        $this->assertSame(['refunded' => 0, 'credited' => 2000, 'credit' => 2000, 'status' => Invoice::STATUS_UNPAID, 'remaining' => 4000, 'renewed' => false], $this->ledger($made));

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_s10fix', 're_s10fix', 60.00, 'USD');

        $this->assertSame(['refunded' => 6000, 'credited' => 0, 'credit' => 0, 'status' => Invoice::STATUS_UNPAID, 'remaining' => 8000, 'renewed' => false], $this->ledger($made));
    }

    /**
     * The over-held open invoice (100.00, 80.00 paid, 20.00 to credit, lowered to 50.00: 10.00 over, not closed).
     *
     * @return array{invoice: Invoice, service: Service, line: InvoiceItem, client: int, expires: string}
     */
    private function overHeld(): array
    {
        $made = $this->renewalInvoice('80.00');
        ExtensionHelper::refundToCredit(InvoiceTransaction::query()->where('transaction_id', 'pi_s10fix')->sole(), 20.00, false);
        $made['line']->refresh()->update(['price' => 50]);
        $this->assertSame(['refunded' => 0, 'credited' => 2000, 'credit' => 2000, 'status' => Invoice::STATUS_UNPAID, 'remaining' => -1000, 'renewed' => false], $this->ledger($made));

        return $made;
    }

    /** A partial gateway refund the credit covers: taken from the credit, and the invoice is left as it was (open, over). */
    public function test_a_partial_gateway_refund_within_the_credit_leaves_the_over_held_invoice_open(): void
    {
        $made = $this->overHeld();

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_s10fix', 're_s10fix', 20.00, 'USD');

        $this->assertSame(['refunded' => 2000, 'credited' => 0, 'credit' => 0, 'status' => Invoice::STATUS_UNPAID, 'remaining' => -1000, 'renewed' => false], $this->ledger($made));
        // Staff's «Close as paid» still closes it, with the part over as credit.
        $this->assertTrue((new RecordPaymentService)->settle($made['invoice']->id));
        $this->assertSame(['refunded' => 2000, 'credited' => 1000, 'credit' => 1000, 'status' => Invoice::STATUS_PAID, 'remaining' => 0, 'renewed' => true], $this->ledger($made));
    }

    /** A partial gateway refund that leaves exactly what the invoice bills: paid by the money still on it. */
    public function test_a_partial_gateway_refund_down_to_the_total_pays_the_over_held_invoice(): void
    {
        $made = $this->overHeld();

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_s10fix', 're_s10fix', 30.00, 'USD');

        $this->assertSame(['refunded' => 3000, 'credited' => 0, 'credit' => 0, 'status' => Invoice::STATUS_PAID, 'remaining' => 0, 'renewed' => true], $this->ledger($made));
    }

    /** A payment landing on an invoice already over still closes it with the part over as credit (2b). */
    public function test_a_new_payment_on_an_over_held_invoice_still_closes_it(): void
    {
        $made = $this->overHeld();

        (new RecordPaymentService)->handle($made['invoice']->id, Gateway::query()->where('extension', 'Stripe')->sole(), '5.00', null, 'pi_s10fix_2');

        $this->assertSame(Invoice::STATUS_PAID, $made['invoice']->fresh()->status);
        $this->assertSame(0, RecordPaymentService::toCents($made['invoice']->fresh()->remaining));
        $this->assertSame(3500, RecordPaymentService::toCents(Credit::query()->where('client_id', $made['client'])->value('amount')));
    }
}
