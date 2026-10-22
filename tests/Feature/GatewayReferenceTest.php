<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\PaymentGatewayEvent;
use App\Models\Service;
use App\Models\User;
use App\Services\Money\GatewayReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Gateways\Mollie\Mollie;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use MyCS\Extensions\Gateways\Stripe\Stripe;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Security audit S0-6: every payment this site starts carries its mark, and an event of one it did not start (another
 * system on the same gateway account: WHMCS's, with invoice numbers of its own) never moves money here; staff are told.
 */
class GatewayReferenceTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_ref';

    private const PAYPAL_API = 'https://api-m.sandbox.paypal.com';

    private const FOREIGN_SUBJECT = 'Gateway event for a payment not made here';

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

    private function invoice(float $price = 100): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function gateway(string $extension, array $settings): Gateway
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => $extension], ['name' => $extension, 'type' => 'gateway']);
        foreach ($settings as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return $gateway->fresh();
    }

    private function stripe(): void
    {
        $this->gateway('Stripe', ['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET]);
        (new Stripe(['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET]))->boot();
    }

    /** @param  array<string, mixed>  $event */
    private function stripeWebhook(array $event)
    {
        $payload = (string) json_encode($event);
        $time = time();

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $payload, self::SECRET),
        ], $payload);
    }

    private function paypal(array $fakes = []): PayPal
    {
        $gateway = $this->gateway('PayPal', ['test_mode' => '1', 'client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'wh']);
        $all = [self::PAYPAL_API . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::PAYPAL_API . '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'])];
        foreach ($fakes as $path => $response) {
            $all[self::PAYPAL_API . $path] = $response;
        }
        Http::fake($all);
        /** @var PayPal $paypal */
        $paypal = ExtensionHelper::getExtension('gateway', 'PayPal', $gateway->settings);
        $paypal->boot();

        return $paypal;
    }

    private function told(): int
    {
        return EmailLog::query()->where('subject', self::FOREIGN_SUBJECT)->count();
    }

    public function test_the_mark_is_the_sites_host_or_the_setting(): void
    {
        config(['app.url' => 'https://my.nixpal.com', 'settings.gateway_reference' => '']);
        $this->assertSame('my-nixpal-com', GatewayReference::mark());
        config(['settings.gateway_reference' => 'Nixpal']);
        $this->assertSame('nixpal', GatewayReference::mark());
        $this->assertTrue(GatewayReference::isOurs('nixpal'));
        $this->assertFalse(GatewayReference::isOurs('myip'));
        $this->assertFalse(GatewayReference::isOurs(null));
        $this->assertSame(42, GatewayReference::invoiceIdFromPaypal('nixpal-42'));
        foreach (['42', 'myip-42', 'nixpal-', 'nixpal-4x2', 'nixpal-42-1'] as $other) {
            $this->assertNull(GatewayReference::invoiceIdFromPaypal($other), $other);
        }
    }

    public function test_a_stripe_payment_without_our_mark_records_nothing_and_staff_are_told(): void
    {
        $this->stripe();
        $invoice = $this->invoice();

        foreach ([null, 'another-site'] as $i => $mark) {
            $metadata = array_filter(['mycs' => $mark, 'invoice_id' => (string) $invoice->id]);
            $this->stripeWebhook(['type' => 'payment_intent.succeeded', 'data' => ['object' => [
                'id' => 'pi_whmcs_' . $i, 'amount' => 10000, 'currency' => 'usd', 'metadata' => $metadata,
            ]]])->assertOk();
        }

        $this->assertSame(0, $invoice->transactions()->count());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame(2, PaymentGatewayEvent::query()->where('outcome', PaymentGatewayEvent::OUTCOME_FOREIGN)->count());
        $this->assertSame(2, $this->told());
    }

    public function test_a_stripe_payment_with_our_mark_is_recorded_as_before(): void
    {
        $this->stripe();
        $invoice = $this->invoice();

        $this->stripeWebhook(['type' => 'payment_intent.succeeded', 'data' => ['object' => [
            'id' => 'pi_ours', 'amount' => 10000, 'currency' => 'usd', 'metadata' => ['mycs' => GatewayReference::mark(), 'invoice_id' => (string) $invoice->id],
        ]]])->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(0, $this->told());
    }

    public function test_a_card_saved_elsewhere_never_becomes_one_of_our_customers_methods(): void
    {
        $this->stripe();

        $this->stripeWebhook(['type' => 'setup_intent.succeeded', 'data' => ['object' => [
            'id' => 'seti_whmcs', 'status' => 'succeeded', 'customer' => 'cus_x', 'payment_method' => 'pm_x',
            'metadata' => ['is_billing_agreement' => '1', 'client_id' => (string) $this->user->client()->id],
        ]]])->assertOk();

        $this->assertSame(0, BillingAgreement::query()->count());
        $this->assertSame(1, $this->told());
    }

    public function test_every_stripe_intent_started_here_carries_the_mark(): void
    {
        Http::fake(['https://api.stripe.com/v1/*' => Http::response(['id' => 'pi_new', 'client_secret' => 'cs'])]);
        $this->gateway('Stripe', ['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET]);
        $stripe = new Stripe(['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET]);
        $stripe->boot();

        $stripe->pay($this->invoice(), 100);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/payment_intents')
            && ($request['metadata']['mycs'] ?? null) === GatewayReference::mark());
    }

    public function test_a_paypal_order_not_made_here_is_never_captured(): void
    {
        $invoice = $this->invoice(20);
        $other = $this->invoice(20);
        $order = ['id' => 'ORDER-X', 'status' => 'APPROVED', 'purchase_units' => [[
            'invoice_id' => GatewayReference::paypalInvoiceId($invoice), 'amount' => ['currency_code' => 'USD', 'value' => '0.01'],
            'payee' => ['merchant_id' => 'SOMEONE-ELSE'],
        ]]];
        $paypal = $this->paypal(['/v2/checkout/orders/ORDER-X' => Http::response($order)]);

        // Made by anyone with the public client id, our invoice_id written in: not made here.
        $this->assertAbortsWith400(fn () => $paypal->capture($invoice, 'ORDER-X'));
        // Made here for another invoice.
        PayPal::rememberOrder('ORDER-X', $other);
        $this->assertAbortsWith400(fn () => $paypal->capture($invoice, 'ORDER-X'));

        $this->assertSame(0, $invoice->transactions()->count());
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/capture'));
    }

    private function assertAbortsWith400(\Closure $call): void
    {
        try {
            $call();
            $this->fail('captured');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    public function test_a_paypal_capture_of_another_systems_order_records_nothing(): void
    {
        $invoice = $this->invoice(20);
        $this->paypal(['/v2/checkout/orders/ORDER-W' => Http::response(['id' => 'ORDER-W', 'status' => 'COMPLETED', 'purchase_units' => [[
            'invoice_id' => (string) $invoice->id, // WHMCS's: its bare invoice number
            'payments' => ['captures' => [['id' => 'CAP-W', 'amount' => ['value' => '20.00', 'currency_code' => 'USD']]]],
        ]]])]);

        $this->postJson('/extensions/paypal/webhook', ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => [
            'id' => 'CAP-W', 'amount' => ['value' => '20.00', 'currency_code' => 'USD'],
            'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-W']],
        ]])->assertOk();

        $this->assertSame(0, $invoice->transactions()->count());
        $this->assertSame(1, $this->told());
    }

    public function test_a_paypal_subscription_made_elsewhere_never_takes_a_service(): void
    {
        $invoice = $this->invoice(20);
        $this->paypal();

        $this->postJson('/extensions/paypal/webhook', ['event_type' => 'BILLING.SUBSCRIPTION.CREATED', 'resource' => [
            'id' => 'I-EVIL', 'custom_id' => (string) $invoice->id,
        ]])->assertOk();

        $this->assertSame(0, Service::query()->where('subscription_id', 'I-EVIL')->count());
        $this->assertSame(1, $this->told());
    }

    public function test_a_mollie_payment_without_our_mark_records_nothing(): void
    {
        $invoice = $this->invoice(40);
        $this->gateway('Mollie', ['api_key' => 'test_key']);
        Http::fake(['https://api.mollie.com/v2/payments/tr_w' => Http::response([
            'id' => 'tr_w', 'status' => 'paid', 'amount' => ['value' => '40.00', 'currency' => 'USD'], 'metadata' => ['invoice_id' => $invoice->id],
        ])]);
        (new Mollie(['api_key' => 'test_key']))->boot();

        $this->post('/extensions/gateways/mollie/webhook', ['id' => 'tr_w'])->assertOk();

        $this->assertSame(0, $invoice->transactions()->count());
        $this->assertSame(1, $this->told());
    }

    public function test_a_dispute_of_a_payment_not_made_here_is_told_to_staff(): void
    {
        $this->stripe();

        $this->stripeWebhook(['type' => 'charge.dispute.created', 'data' => ['object' => [
            'id' => 'dp_whmcs', 'payment_intent' => 'pi_whmcs_old', 'amount' => 5000, 'currency' => 'usd',
        ]]])->assertOk();

        $this->assertSame(1, $this->told());
        $this->assertSame(PaymentGatewayEvent::OUTCOME_FOREIGN, PaymentGatewayEvent::query()->sole()->outcome);
    }
}
