<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Money\GatewayReference;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use Tests\TestCase;

/**
 * Sweep 10, payments: adversarial verification. Each test fails while the finding it names (S10-PAYMENTS-n) holds.
 */
class Sweep10PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const STRIPE_SECRET = 'whsec_sweep10';

    private const PAYPAL_API = 'https://api-m.sandbox.paypal.com';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Cache::flush();
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
    }

    private function invoice(float $price): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => now()->toDateString()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function stripeWebhook(string $id, int $created, string $type, array $object)
    {
        $payload = json_encode(['id' => $id, 'created' => $created, 'type' => $type, 'data' => ['object' => $object]]);
        $time = time();

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1=" . hash_hmac('sha256', $time . '.' . $payload, self::STRIPE_SECRET),
        ], $payload);
    }

    /**
     * S10-PAYMENTS-1. One Stripe intent through the customer's attempts: a card declined (payment_failed), then a bank
     * debit on the same form (processing; Stripe is asked and says «processing», REV-766-1). The old payment_failed
     * event then arrives again (a duplicate, or its retry after a 5xx). The mirror of S6-PAYMENTS-1: nobody asks Stripe
     * what the intent is now, and the payment that is on its way is turned to failed, so nothing holds the invoice.
     */
    public function test_s10_payments_1_a_late_copy_of_the_old_failure_does_not_fail_a_payment_on_its_way(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        foreach (['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::STRIPE_SECRET] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $invoice = $this->invoice(10);
        $intent = ['id' => 'pi_s10_1', 'object' => 'payment_intent', 'amount' => 1000, 'currency' => 'usd',
            'metadata' => ['mycs' => GatewayReference::mark(), 'invoice_id' => (string) $invoice->id]];
        $declined = $intent + ['status' => 'requires_payment_method', 'latest_charge' => 'ch_s10_card'];

        // E1: the card is declined.
        $this->stripeWebhook('evt_s10_1a', 1794304800, 'payment_intent.payment_failed', $declined)->assertOk();
        $this->assertSame(InvoiceTransactionStatus::Failed, $invoice->transactions()->sole()->status, 'precondition: the decline is recorded');

        // E2: a bank debit on the same intent. Stripe, asked what the intent is now (then and ever after), says «processing».
        Http::fake(['api.stripe.com/v1/payment_intents/pi_s10_1' => Http::response(['id' => 'pi_s10_1', 'object' => 'payment_intent', 'status' => 'processing'])]);
        $this->stripeWebhook('evt_s10_1b', 1794304980, 'payment_intent.processing',
            $intent + ['status' => 'processing', 'latest_charge' => 'py_s10_sepa'])->assertOk();
        $this->assertSame(InvoiceTransactionStatus::Processing, $invoice->transactions()->sole()->status, 'precondition: the debit is on its way');
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()), 'precondition: the invoice is held');

        // E1 again: the same old event (same id, same older «created», same failed card charge).
        $this->stripeWebhook('evt_s10_1a', 1794304800, 'payment_intent.payment_failed', $declined);

        $this->assertSame(InvoiceTransactionStatus::Processing, $invoice->transactions()->sole()->status,
            'a late copy of the old decline turned the bank debit that is on its way at Stripe into «failed»');
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()),
            'nothing holds the invoice while the bank is taking the money: the cron charges the saved card, a second checkout opens');
    }

    /**
     * S10-PAYMENTS-2. A verified PAYMENT.CAPTURE.COMPLETED whose order read is rate-limited (429) by PayPal. A 5xx on
     * that read is thrown so PayPal sends the event again; a 429 is answered 200 «ignored», PayPal never sends it again
     * and the money (a pending capture that completed) is never recorded.
     */
    public function test_s10_payments_2_a_rate_limited_order_read_is_retried_not_dropped(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        foreach (['test_mode' => '1', 'client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'wh'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Http::fake([
            self::PAYPAL_API . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::PAYPAL_API . '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
            self::PAYPAL_API . '/v2/checkout/orders/ORDER-S10' => Http::response(['name' => 'RATE_LIMIT_REACHED', 'message' => 'Too many requests'], 429),
        ]);
        /** @var PayPal $paypal */
        $paypal = ExtensionHelper::getExtension('gateway', 'PayPal', $gateway->fresh()->settings);
        $paypal->boot();

        $invoice = $this->invoice(20);
        PayPal::rememberOrder('ORDER-S10', $invoice);
        // The capture came back PENDING at checkout (an eCheck, PayPal's review): on its way.
        ExtensionHelper::addProcessingPayment($invoice->id, 'PayPal', 20.00, null, 'CAP-S10', currency: 'USD');
        $this->assertSame(InvoiceTransactionStatus::Processing, $invoice->transactions()->sole()->status, 'precondition');

        $response = $this->postJson('/extensions/paypal/webhook', ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => [
            'id' => 'CAP-S10', 'status' => 'COMPLETED', 'amount' => ['value' => '20.00', 'currency_code' => 'USD'],
            'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-S10']],
        ]]);

        $this->assertSame(InvoiceTransactionStatus::Processing, $invoice->transactions()->sole()->status, 'nothing was recorded (expected either way)');
        $this->assertGreaterThanOrEqual(500, $response->getStatusCode(),
            'the completed capture was answered ' . $response->getStatusCode() . ' ' . $response->getContent() . ' on a rate limit: PayPal never sends it again and the payment stays processing for ever');
    }
}
