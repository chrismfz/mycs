<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Paymenter\Extensions\Gateways\PayPal\PayPal;
use Paymenter\Extensions\Gateways\PayPal\PayPalApiException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * PayPal's REST errors must never read as success (audit sweep KR-2, PAY-1, PAY-7): before, request() returned the
 * error body as an object and refund/charge/cancel answered true, so a refund PayPal refused was recorded locally.
 */
class PayPalApiErrorsTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api-m.sandbox.paypal.com';

    private User $user;

    private Gateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['settings.credits_enabled' => true]);

        $this->user = User::factory()->create();
        $this->gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        foreach (['test_mode' => '1', 'client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'wh'] as $key => $value) {
            $this->gateway->settings()->create(['key' => $key, 'value' => $value]);
        }
    }

    /** @param  array<string, mixed>  $routes  URL path (after the API host) => response */
    private function fakePayPal(array $routes): void
    {
        $fakes = [self::API . '/v1/oauth2/token' => Http::response(['access_token' => 'token'])];
        foreach ($routes as $path => $response) {
            $fakes[self::API . $path] = $response;
        }

        Http::fake($fakes);
    }

    private function paypal(): PayPal
    {
        return ExtensionHelper::getExtension('gateway', 'PayPal', $this->gateway->fresh()->settings);
    }

    private function paidInvoice(float $amount = 20.00): InvoiceTransaction
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $amount]);

        return ExtensionHelper::addPayment($invoice->id, 'PayPal', $amount, null, 'CAPTURE-1', currency: 'USD');
    }

    private function declined(): Response|PromiseInterface
    {
        return Http::response([
            'name' => 'UNPROCESSABLE_ENTITY',
            'details' => [['issue' => 'INSTRUMENT_DECLINED', 'description' => 'The instrument was declined.']],
            'debug_id' => 'dbg123',
        ], 422);
    }

    public function test_a_refund_paypal_refuses_is_not_recorded(): void
    {
        $transaction = $this->paidInvoice();
        $this->fakePayPal(['/v2/payments/captures/CAPTURE-1/refund' => Http::response([
            'name' => 'RESOURCE_NOT_FOUND', 'details' => [['issue' => 'INVALID_RESOURCE_ID']], 'debug_id' => 'dbg404',
        ], 404)]);

        try {
            ExtensionHelper::refund($transaction, 20.00);
            $this->fail('A refused refund must throw.');
        } catch (PayPalApiException $e) {
            $this->assertSame(404, $e->status);
            $this->assertSame('INVALID_RESOURCE_ID', $e->issue);
            $this->assertStringContainsString('dbg404', $e->getMessage());
        }

        $this->assertEquals(0, $transaction->fresh()->refunded_amount);
        $this->assertSame(0, $transaction->invoice->adjustmentNotes()->count());
    }

    public function test_a_refund_that_is_not_completed_or_pending_is_not_recorded(): void
    {
        $transaction = $this->paidInvoice();
        $this->fakePayPal(['/v2/payments/captures/CAPTURE-1/refund' => Http::response(['id' => 'R1', 'status' => 'FAILED'], 201)]);

        $this->expectExceptionMessage('PayPal did not refund capture CAPTURE-1');
        try {
            ExtensionHelper::refund($transaction, 20.00);
        } finally {
            $this->assertEquals(0, $transaction->fresh()->refunded_amount);
        }
    }

    public function test_a_completed_refund_is_recorded(): void
    {
        $transaction = $this->paidInvoice();
        $this->fakePayPal(['/v2/payments/captures/CAPTURE-1/refund' => Http::response(['id' => 'R1', 'status' => 'COMPLETED'], 201)]);

        ExtensionHelper::refund($transaction, 5.00);

        $this->assertEquals(5.00, $transaction->fresh()->refunded_amount);
        Http::assertSent(fn (Request $request) => $request->url() === self::API . '/v2/payments/captures/CAPTURE-1/refund'
            && $request['amount']['value'] === '5.00');
    }

    private function agreement(): BillingAgreement
    {
        return BillingAgreement::create([
            'client_id' => $this->user->client()->id, 'gateway_id' => $this->gateway->id, 'name' => 'PayPal', 'external_reference' => 'VAULT-1', 'type' => 'paypal',
        ]);
    }

    private function unpaidInvoice(): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 20.00]);

        return $invoice->fresh();
    }

    public function test_a_declined_vault_charge_throws(): void
    {
        $this->fakePayPal(['/v2/checkout/orders' => $this->declined()]);

        // A DisplayException: the customer sees "could not process", the cron sends the failed-payment email.
        $this->expectException(DisplayException::class);
        ExtensionHelper::charge($this->gateway, $this->unpaidInvoice(), $this->agreement());
    }

    public function test_a_vault_charge_with_a_declined_capture_throws(): void
    {
        $this->fakePayPal(['/v2/checkout/orders' => Http::response([
            'id' => 'ORDER-1', 'status' => 'COMPLETED',
            'purchase_units' => [['payments' => ['captures' => [['id' => 'C1', 'status' => 'DECLINED']]]]],
        ], 201)]);

        $this->expectException(DisplayException::class);
        ExtensionHelper::charge($this->gateway, $this->unpaidInvoice(), $this->agreement());
    }

    public function test_a_completed_vault_charge_succeeds_and_is_recorded(): void
    {
        $this->fakePayPal(['/v2/checkout/orders' => Http::response([
            'id' => 'ORDER-1', 'status' => 'COMPLETED',
            'purchase_units' => [['payments' => ['captures' => [[
                'id' => 'C1', 'status' => 'COMPLETED', 'amount' => ['value' => '20.00', 'currency_code' => 'USD'],
            ]]]]],
        ], 201)]);
        $invoice = $this->unpaidInvoice();

        $this->assertTrue(ExtensionHelper::charge($this->gateway, $invoice, $this->agreement()));
        $this->assertSame(InvoiceTransactionStatus::Succeeded, $invoice->transactions()->sole()->status);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_an_expired_token_is_renewed_once_with_the_same_request_id(): void
    {
        $ids = [];
        Http::fake([
            self::API . '/v1/oauth2/token' => Http::sequence()->push(['access_token' => 'old'])->push(['access_token' => 'new']),
            self::API . '/v2/payments/captures/CAPTURE-1/refund' => function (Request $request) use (&$ids) {
                $ids[] = $request->header('PayPal-Request-Id')[0];

                return $request->header('Authorization')[0] === 'Bearer old'
                    ? Http::response(['name' => 'AUTHENTICATION_FAILURE'], 401)
                    : Http::response(['id' => 'R1', 'status' => 'COMPLETED'], 201);
            },
        ]);

        ExtensionHelper::refund($this->paidInvoice(), 5.00);

        $this->assertCount(2, $ids);
        $this->assertSame($ids[0], $ids[1]);
    }

    private function subscribedService(bool $paypal = true): Service
    {
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 10.00,
            'subscription_id' => 'I-SUB1',
        ]);
        if ($paypal) {
            $service->properties()->create(['key' => 'has_paypal_subscription', 'name' => 'PayPal', 'value' => '1']);
        }

        return $service;
    }

    public function test_cancel_subscription_ignores_services_that_are_not_paypal_subscriptions(): void
    {
        $this->fakePayPal([]);

        $service = $this->subscribedService(paypal: false);
        $service->update(['subscription_id' => 'sub_stripe1']); // a Stripe subscription: no marker, not an I- id

        $this->assertFalse($this->paypal()->cancelSubscription($service));
        Http::assertNothingSent();
    }

    public function test_cancel_subscription_already_cancelled_at_paypal_counts_as_cancelled(): void
    {
        $service = $this->subscribedService();
        $this->fakePayPal(['/v1/billing/subscriptions/I-SUB1/cancel' => Http::response([
            'name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'SUBSCRIPTION_STATUS_INVALID']],
        ], 422)]);

        $this->assertTrue($this->paypal()->cancelSubscription($service));
        $this->assertNull($service->properties()->where('key', 'has_paypal_subscription')->first());
    }

    public function test_cancel_subscription_failure_throws_and_keeps_the_marker(): void
    {
        $service = $this->subscribedService();
        $this->fakePayPal(['/v1/billing/subscriptions/I-SUB1/cancel' => Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 500)]);

        try {
            $this->paypal()->cancelSubscription($service);
            $this->fail('A failed cancellation must throw.');
        } catch (PayPalApiException $e) {
            $this->assertSame(500, $e->status);
        }

        $this->assertNotNull($service->properties()->where('key', 'has_paypal_subscription')->first());
    }

    /**
     * Adversarial review of #45, ADV-4: a cancel PayPal refused (500) used to delete the marker anyway, so the
     * subscription kept billing and nothing retried. Through the Service\Updated listener, inside a transaction.
     */
    public function test_a_failed_cancel_from_the_listener_keeps_the_subscription_marker(): void
    {
        $service = $this->subscribedService();
        $this->fakePayPal(['/v1/billing/subscriptions/I-SUB1/cancel' => Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 500)]);
        $this->paypal()->boot();

        DB::transaction(fn () => $service->update(['status' => Service::STATUS_CANCELLED]));

        Http::assertSent(fn (Request $request) => $request->url() === self::API . '/v1/billing/subscriptions/I-SUB1/cancel');
        $this->assertSame('1', $service->properties()->where('key', 'has_paypal_subscription')->value('value'));
    }

    public function test_cancel_billing_agreement_already_deleted_at_paypal_succeeds(): void
    {
        $this->fakePayPal(['/v3/vault/payment-tokens/VAULT-1' => Http::response(['name' => 'RESOURCE_NOT_FOUND'], 404)]);

        $this->assertTrue($this->paypal()->cancelBillingAgreement($this->agreement()));
    }

    public function test_cancel_billing_agreement_failure_is_reported_and_answers_false(): void
    {
        $this->fakePayPal(['/v3/vault/payment-tokens/VAULT-1' => Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 500)]);

        // false: the customer sees "Failed to remove payment method" and the agreement stays (PayPal still has it).
        $this->assertFalse($this->paypal()->cancelBillingAgreement($this->agreement()));
    }

    private function approvedOrder(Invoice $invoice): array
    {
        return [
            'id' => 'ORDER-1', 'status' => 'APPROVED',
            'purchase_units' => [['invoice_id' => (string) $invoice->id, 'amount' => ['currency_code' => 'USD', 'value' => '20.00']]],
        ];
    }

    public function test_a_declined_capture_returns_paypal_error_to_the_buyer_and_records_nothing(): void
    {
        $invoice = $this->unpaidInvoice();
        $this->fakePayPal([
            '/v2/checkout/orders/ORDER-1/capture' => $this->declined(),
            '/v2/checkout/orders/ORDER-1' => Http::response($this->approvedOrder($invoice)),
        ]);

        $response = $this->paypal()->capture($invoice, 'ORDER-1');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('INSTRUMENT_DECLINED', $response->getData(true)['details'][0]['issue']);
        $this->assertSame('dbg123', $response->getData(true)['debug_id']);
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_a_pending_capture_is_recorded_as_processing_without_a_fee(): void
    {
        $invoice = $this->unpaidInvoice();
        $this->fakePayPal([
            '/v2/checkout/orders/ORDER-1/capture' => Http::response([
                'id' => 'ORDER-1', 'status' => 'COMPLETED',
                'purchase_units' => [['payments' => ['captures' => [[
                    'id' => 'CAP-P', 'status' => 'PENDING', 'amount' => ['value' => '20.00', 'currency_code' => 'USD'],
                ]]]]],
            ], 201),
            '/v2/checkout/orders/ORDER-1' => Http::response($this->approvedOrder($invoice)),
        ]);

        $this->paypal()->capture($invoice, 'ORDER-1');

        $transaction = $invoice->transactions()->sole();
        $this->assertSame(InvoiceTransactionStatus::Processing, $transaction->status);
        $this->assertSame('CAP-P', $transaction->transaction_id);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    public function test_an_unknown_order_is_a_bad_request(): void
    {
        $invoice = $this->unpaidInvoice();
        $this->fakePayPal(['/v2/checkout/orders/ORDER-X' => Http::response(['name' => 'RESOURCE_NOT_FOUND'], 404)]);

        try {
            $this->paypal()->capture($invoice, 'ORDER-X');
            $this->fail('An unknown order must abort.');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    public function test_webhook_verification_refused_by_paypal_is_a_400_and_unavailable_is_retried(): void
    {
        $this->fakePayPal(['/v1/notifications/verify-webhook-signature' => Http::sequence()
            ->push(['name' => 'VALIDATION_ERROR'], 400)
            ->push(['name' => 'SERVICE_UNAVAILABLE'], 503)]);
        $payload = ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => []];
        $this->paypal()->boot(); // registers the webhook route (the app booted before the gateway existed)

        $this->postJson('/extensions/paypal/webhook', $payload)->assertStatus(400);
        $this->postJson('/extensions/paypal/webhook', $payload)->assertStatus(500);
    }
}
