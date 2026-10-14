<?php

namespace Tests\Feature;

use App\Events\InvoiceTransaction\Created as TransactionCreated;
use App\Helpers\ExtensionHelper;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\PaymentGatewayEvent;
use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Paymenter\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

/**
 * Code review of MT-2a (PR #64): failing tests for the findings.
 */
class GatewayLogCodeReviewTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test';

    private const PAYPAL_API = 'https://api-m.sandbox.paypal.com';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Cache::flush();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $this->user = User::factory()->create();
    }

    private function invoice(float $price = 100, string $currency = 'USD'): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => $currency, 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function stripe(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        foreach (['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        (new Stripe(['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET]))->boot();
    }

    /** @param  array<string, mixed>  $event */
    private function stripeWebhook(array $event)
    {
        $payload = json_encode($event);
        $time = time();
        $signature = hash_hmac('sha256', $time . '.' . $payload, self::SECRET);

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$signature}",
        ], $payload);
    }

    /** @return array<string, mixed> */
    private function succeeded(Invoice $invoice, int $cents, string $id): array
    {
        return ['type' => 'payment_intent.succeeded', 'data' => ['object' => [
            'id' => $id, 'amount' => $cents, 'currency' => 'usd', 'metadata' => ['invoice_id' => (string) $invoice->id],
        ]]];
    }

    /**
     * P2: the InvoiceObserver's afterCommit (ProcessPaidInvoiceService + Paid event) is registered BEFORE
     * GatewayLog::result()'s and rethrows a failed renewal on purpose (so the gateway retries). Laravel stops running
     * a commit's callbacks at the first throw, so the gateway log loses the result of a payment that IS committed:
     * the row says error/http_500 with no amount/invoice/transaction, and staff are mailed «it is NOT recorded».
     */
    public function test_a_committed_payment_whose_renewal_fails_after_commit_keeps_its_money_in_the_log(): void
    {
        $this->stripe();
        $invoice = $this->invoice(100);
        // An after-commit callback that throws, registered before GatewayLog's (Σ4: the Paid event no longer can, its
        // listeners are reported, so an extension's own callback stands in).
        Event::listen(TransactionCreated::class, function () {
            DB::afterCommit(fn () => throw new \RuntimeException('renewal failed after commit'));
        });

        $this->stripeWebhook($this->succeeded($invoice, 10000, 'pi_cr_1'))->assertStatus(500);

        $this->assertSame(1, $invoice->transactions()->count(), 'the money is committed');
        $row = PaymentGatewayEvent::query()->sole();
        $this->assertSame(PaymentGatewayEvent::OUTCOME_ERROR, $row->outcome);
        $this->assertSame($invoice->transactions()->sole()->id, $row->invoice_transaction_id, 'the log must say which transaction was recorded');
        $this->assertSame('100.00', $row->amount);
    }

    /**
     * P3: GatewayLog::finish() runs inside the RequestHandled listener, which Kernel::handle() dispatches OUTSIDE its
     * try/catch. alert() calls Cache::add() unguarded (prod: CACHE_STORE=redis): when the cache is down — the very
     * moment webhooks 5xx — the exception escapes the kernel and no Log::warning/mail is written.
     */
    public function test_a_cache_failure_while_alerting_does_not_escape_the_request(): void
    {
        $this->stripe();
        $invoice = $this->invoice(100);
        // An after-commit callback that throws, registered before GatewayLog's (Σ4: the Paid event no longer can, its
        // listeners are reported, so an extension's own callback stands in).
        Event::listen(TransactionCreated::class, function () {
            DB::afterCommit(fn () => throw new \RuntimeException('renewal failed after commit'));
        });
        Cache::swap(new Repository(new class extends ArrayStore
        {
            public function add($key, $value, $seconds)
            {
                throw new \RuntimeException('cache down');
            }
        }));

        $response = $this->stripeWebhook($this->succeeded($invoice, 10000, 'pi_cr_2'));

        $response->assertStatus(500);
        $this->assertSame(PaymentGatewayEvent::OUTCOME_ERROR, PaymentGatewayEvent::query()->sole()->outcome);
    }

    /**
     * P3: anyone can POST id=<anything> to the Mollie webhook; Mollie answers 404, the gateway throws, the request
     * is a 500 → «error» + a staff mail saying a payment is NOT recorded. Forged/unknown ids should be a 4xx-like
     * «rejected», not an alert (and they occupy the hourly cooldown that a real failure needs).
     */
    public function test_a_forged_mollie_webhook_does_not_alert_staff(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Mollie'], ['name' => 'Mollie', 'type' => 'gateway']);
        $gateway->settings()->create(['key' => 'api_key', 'value' => 'test_key']);
        Http::fake(['https://api.mollie.com/*' => Http::response(['status' => 404, 'detail' => 'No payment exists with token tr_forged.'], 404)]);
        ExtensionHelper::getExtension('gateway', 'Mollie', $gateway->fresh()->settings)->boot();

        $this->post('/extensions/gateways/mollie/webhook', ['id' => 'tr_forged']);

        $this->assertSame(0, EmailLog::query()->where('subject', 'Gateway request failed')->count(), 'an unknown id from anyone is not a failed payment');
    }

    /**
     * P3: the PayPal capture webhook (and PayPal_IPN) do their own currency/receiver checks and skip
     * RecordPaymentService, answering 200: PayPal charged, nothing recorded, and the log says ignored/nothing_recorded
     * — no rejected/currency_mismatch, no staff notice (§4: «gateway says charged but the money was not written»).
     */
    public function test_a_paypal_capture_in_another_currency_is_logged_as_rejected(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        foreach (['test_mode' => '1', 'client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'wh'] as $key => $value) {
            $gateway->settings()->create(['key' => $key, 'value' => $value]);
        }
        $invoice = $this->invoice(100, 'USD');
        Http::fake([
            self::PAYPAL_API . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::PAYPAL_API . '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
            self::PAYPAL_API . '/v2/checkout/orders/ORDER1' => Http::response(['id' => 'ORDER1', 'status' => 'COMPLETED', 'purchase_units' => [[
                'invoice_id' => (string) $invoice->id,
                'payments' => ['captures' => [['id' => 'CAP1', 'amount' => ['value' => '100.00', 'currency_code' => 'EUR']]]],
            ]]]),
        ]);
        ExtensionHelper::getExtension('gateway', 'PayPal', $gateway->fresh()->settings)->boot();

        $this->postJson('/extensions/paypal/webhook', ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => [
            'id' => 'CAP1', 'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER1']],
        ]])->assertOk();

        $this->assertSame(0, $invoice->transactions()->count());
        $row = PaymentGatewayEvent::query()->sole();
        $this->assertSame(PaymentGatewayEvent::OUTCOME_REJECTED, $row->outcome, 'PayPal charged, nothing was recorded: not «ignored»');
    }
}
