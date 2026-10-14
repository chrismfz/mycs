<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Client\PaymentMethods;
use App\Livewire\Invoices\Show;
use App\Models\AdjustmentNote;
use App\Models\BillingAgreement;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use MyCS\Extensions\Gateways\PayPal\PayPalApiException;
use RuntimeException;
use Tests\TestCase;

/**
 * Adversarial review of PR #46 (PayPal REST errors are errors). test_holds_* pass (edge cases that hold);
 * every other test asserts the CORRECT behaviour and fails on the PR as it stands (a finding).
 */
class PayPalApiErrorsAdversarialTest extends TestCase
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
        foreach (['test_mode' => '1', 'client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'wh', 'paypal_support_billing_agreements' => '1'] as $key => $value) {
            $this->gateway->settings()->create(['key' => $key, 'value' => $value]);
        }
    }

    /** @param  array<string, mixed>  $routes */
    private function fakePayPal(array $routes): void
    {
        $fakes = [self::API . '/v1/oauth2/token' => Http::response(['access_token' => 'SECRET-TOKEN'])];
        foreach ($routes as $path => $response) {
            $fakes[self::API . $path] = $response;
        }
        Http::fake($fakes);
    }

    private function paypal(): PayPal
    {
        return ExtensionHelper::getExtension('gateway', 'PayPal', $this->gateway->fresh()->settings);
    }

    private function service(): Service
    {
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => 20.00]);

        return Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => now()->addDays(3)->toDateString(), 'currency_code' => 'USD', 'price' => 20.00,
        ]);
    }

    private function invoice(?Service $service = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create(array_filter([
            'description' => 'Hosting', 'quantity' => 1, 'price' => 20.00,
            'reference_type' => $service ? Service::class : null, 'reference_id' => $service?->id,
            'period_start' => $service ? $service->expires_at->toDateString() : null,
            'period_end' => $service ? $service->expires_at->copy()->addMonth()->toDateString() : null,
        ], fn ($v) => $v !== null));

        return $invoice->fresh();
    }

    private function agreement(): BillingAgreement
    {
        return BillingAgreement::create([
            'client_id' => $this->user->client()->id, 'gateway_id' => $this->gateway->id, 'name' => 'PayPal', 'external_reference' => 'VAULT-1', 'type' => 'paypal',
        ]);
    }

    private function approvedOrder(Invoice $invoice): array
    {
        return [
            'id' => 'ORDER-1', 'status' => 'APPROVED',
            'purchase_units' => [['invoice_id' => (string) $invoice->id, 'amount' => ['currency_code' => 'USD', 'value' => '20.00']]],
        ];
    }

    private function capturedOrder(Invoice $invoice, string $captureStatus): array
    {
        return [
            'id' => 'ORDER-1', 'status' => 'COMPLETED',
            'purchase_units' => [[
                'invoice_id' => (string) $invoice->id,
                'amount' => ['currency_code' => 'USD', 'value' => '20.00'],
                'payments' => ['captures' => [[
                    'id' => 'CAP-P', 'status' => $captureStatus, 'amount' => ['value' => '20.00', 'currency_code' => 'USD'],
                    'seller_receivable_breakdown' => ['paypal_fee' => ['value' => '0.88']],
                ]]],
            ]],
        ];
    }

    private function captureCompletedWebhook(): array
    {
        return [
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => ['id' => 'CAP-P', 'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-1']]],
        ];
    }

    // ------------------------------------------------------------------ findings

    /**
     * ADV-1: a declined saved-method (vault) charge from the invoice page. Before the PR charge() answered true
     * ("Successfully charged"); now it throws a plain Exception/PayPalApiException, which the Livewire component
     * does not catch and which is not a DisplayException: the customer gets a 500 instead of
     * "Could not process payment". Stripe throws DisplayException for exactly this.
     */
    public function test_a_declined_saved_paypal_charge_on_the_invoice_page_tells_the_customer_instead_of_a_500(): void
    {
        $invoice = $this->invoice();
        $agreement = $this->agreement();
        $this->fakePayPal(['/v2/checkout/orders' => Http::response([
            'name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'INSTRUMENT_DECLINED']], 'debug_id' => 'dbg',
        ], 422)]);

        $this->actingAs($this->user);

        $component = Livewire::test(Show::class, ['invoice' => $invoice])->set('selectedMethod', $agreement->fresh()->ulid);
        try {
            $component->call('processPayment');
        } catch (\Throwable $e) {
            $this->fail('The customer got an unhandled ' . get_class($e) . ': ' . $e->getMessage());
        }
        $this->assertSame(0, $invoice->transactions()->count());
    }

    /**
     * ADV-2: PAYMENT.CAPTURE.COMPLETED for an order this app cannot read (404: created by another REST app on the
     * same PayPal account, e.g. WHMCS during the cut-over, or a deleted sandbox order). Before: 200 and ignored.
     * Now the order GET throws, the webhook answers 500 and PayPal retries it for days, for something that can
     * never succeed (and every retry is a reported exception).
     */
    public function test_a_capture_webhook_for_an_order_paypal_cannot_find_is_acknowledged_not_retried_forever(): void
    {
        $this->fakePayPal([
            '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
            '/v2/checkout/orders/ORDER-1' => Http::response(['name' => 'RESOURCE_NOT_FOUND', 'details' => [['issue' => 'INVALID_RESOURCE_ID']]], 404),
        ]);
        $this->paypal()->boot();

        $this->postJson('/extensions/paypal/webhook', $this->captureCompletedWebhook())->assertStatus(200);
    }

    /**
     * ADV-3: a refund whose HTTP call times out after PayPal accepted it. No Log::critical (refundedAtGateway is
     * never set), nothing recorded, and the staff retry is a NEW refund at PayPal: request() sends a fresh
     * uniqid() PayPal-Request-Id on every call, so PayPal cannot de-duplicate it -> refunded twice.
     */
    public function test_a_retried_refund_reuses_the_paypal_request_id_so_paypal_cannot_refund_twice(): void
    {
        $invoice = $this->invoice();
        $transaction = ExtensionHelper::addPayment($invoice->id, 'PayPal', 20.00, null, 'CAPTURE-1', currency: 'USD');

        $ids = [];
        Http::fake([
            self::API . '/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            self::API . '/v2/payments/captures/CAPTURE-1/refund' => function (Request $request) use (&$ids) {
                $ids[] = $request->header('PayPal-Request-Id')[0] ?? null;
                if (count($ids) === 1) {
                    throw new ConnectionException('cURL error 28: Operation timed out');
                }

                return Http::response(['id' => 'R1', 'status' => 'COMPLETED'], 201);
            },
        ]);

        try {
            ExtensionHelper::refund($transaction, 20.00);
        } catch (ConnectionException) {
        }
        ExtensionHelper::refund($transaction->fresh(), 20.00);

        $this->assertCount(2, $ids);
        $this->assertSame($ids[0], $ids[1], 'Each refund attempt is a new refund at PayPal (no idempotency key).');
    }

    /**
     * ADV-4: a PENDING vault capture. capture() (buyer flow) records it as processing so the invoice page shows
     * "processing" and PAYMENT.CAPTURE.COMPLETED upgrades it; charge() records nothing, returns true, and the
     * customer is told "Successfully charged" while the invoice stays plainly unpaid (and a later DENIED is
     * invisible).
     */
    public function test_a_pending_vault_charge_is_recorded_as_processing_like_a_pending_capture(): void
    {
        $invoice = $this->invoice();
        $this->fakePayPal(['/v2/checkout/orders' => Http::response($this->capturedOrder($invoice, 'PENDING'), 201)]);

        $this->assertTrue(ExtensionHelper::charge($this->gateway, $invoice, $this->agreement()));

        $this->assertSame(InvoiceTransactionStatus::Processing, $invoice->transactions()->sole()->status);
    }

    /**
     * ADV-5: customer removes a saved PayPal method while PayPal answers 5xx. Before: deleted locally. Now
     * cancelBillingAgreement throws and PaymentMethods::removePaymentMethod does not catch: a 500 instead of
     * "Failed to remove payment method".
     */
    public function test_removing_a_paypal_method_while_paypal_is_down_tells_the_customer_instead_of_a_500(): void
    {
        $agreement = $this->agreement();
        $this->fakePayPal(['/v3/vault/payment-tokens/VAULT-1' => Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 500)]);
        $this->actingAs($this->user);

        // Called on the component without rendering it: rendering the account page crashes the PHP process in this
        // test environment (unrelated to PayPal). The method and its notify() path are what is under test.
        try {
            (new PaymentMethods)->removePaymentMethod($agreement->fresh()->ulid);
        } catch (\Throwable $e) {
            $this->fail('The customer got an unhandled ' . get_class($e) . ': ' . $e->getMessage());
        }
        $this->assertNotNull($agreement->fresh(), 'kept locally: PayPal still holds the vault token');
    }

    /**
     * ADV-6: staff "Cancel Subscription" (ServiceResource/OrderResource) on a PayPal subscription id without the
     * has_paypal_subscription marker (id typed in by staff, or the marker lost). Before the PR PayPal was asked
     * to cancel; now cancelSubscription returns false without calling PayPal, the action says "could not be
     * cancelled" and then nulled subscription_id anyway (fixed in #46/#52: the id is kept): the id was lost and PayPal kept billing.
     */
    public function test_staff_cancel_of_a_paypal_subscription_without_the_marker_still_cancels_at_paypal(): void
    {
        $service = $this->service();
        $service->update(['subscription_id' => 'I-SUB1']);
        $this->fakePayPal(['/v1/billing/subscriptions/I-SUB1/cancel' => Http::response(null, 204)]);

        ExtensionHelper::cancelSubscription($service->fresh());

        Http::assertSent(fn (Request $request) => $request->url() === self::API . '/v1/billing/subscriptions/I-SUB1/cancel');
    }

    // ------------------------------------------------------------------ holds

    /** PENDING capture -> processing; COMPLETED webhook -> succeeded once; a replay neither pays nor renews twice. */
    public function test_holds_pending_capture_then_completed_webhook_succeeds_and_renews_once(): void
    {
        $service = $this->service();
        $invoice = $this->invoice($service);
        $expires = $service->expires_at->toDateString();
        $this->fakePayPal([
            '/v2/checkout/orders/ORDER-1/capture' => Http::response($this->capturedOrder($invoice, 'PENDING'), 201),
            '/v2/checkout/orders/ORDER-1' => Http::sequence()
                ->push($this->approvedOrder($invoice))
                ->push($this->capturedOrder($invoice, 'COMPLETED'))
                ->push($this->capturedOrder($invoice, 'COMPLETED')),
            '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        ]);
        $paypal = $this->paypal();
        $paypal->capture($invoice, 'ORDER-1');
        $this->assertSame(InvoiceTransactionStatus::Processing, $invoice->transactions()->sole()->status);
        $this->assertNotSame($expires, null);
        $this->assertSame($expires, $service->fresh()->expires_at->toDateString(), 'processing does not renew');

        $paypal->boot();
        $this->postJson('/extensions/paypal/webhook', $this->captureCompletedWebhook())->assertOk();
        $after = $service->fresh()->expires_at->toDateString();
        $this->postJson('/extensions/paypal/webhook', $this->captureCompletedWebhook())->assertOk();

        $transaction = $invoice->transactions()->sole();
        $this->assertSame(InvoiceTransactionStatus::Succeeded, $transaction->status);
        $this->assertEquals(0.88, $transaction->fee);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertNotSame($expires, $after, 'renewed');
        $this->assertSame($after, $service->fresh()->expires_at->toDateString(), 'renewed once');
    }

    /** The COMPLETED webhook arrives before capture() returns PENDING: succeeded is never downgraded. */
    public function test_holds_completed_webhook_before_a_pending_capture_response_is_not_downgraded(): void
    {
        $invoice = $this->invoice();
        ExtensionHelper::addPayment($invoice->id, 'PayPal', 20.00, 0.88, 'CAP-P', currency: 'USD'); // the webhook
        $this->fakePayPal([
            '/v2/checkout/orders/ORDER-1/capture' => Http::response($this->capturedOrder($invoice, 'PENDING'), 201),
            '/v2/checkout/orders/ORDER-1' => Http::response($this->approvedOrder($invoice)),
        ]);

        $this->paypal()->capture($invoice, 'ORDER-1');

        $this->assertSame(InvoiceTransactionStatus::Succeeded, $invoice->transactions()->sole()->status);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /** A pending capture in another currency is not recorded (RecordPaymentService refuses it). */
    public function test_holds_a_capture_in_another_currency_is_not_recorded(): void
    {
        $invoice = $this->invoice();
        $order = $this->capturedOrder($invoice, 'COMPLETED');
        $order['purchase_units'][0]['payments']['captures'][0]['amount']['currency_code'] = 'EUR';
        $this->fakePayPal([
            '/v2/checkout/orders/ORDER-1/capture' => Http::response($order, 201),
            '/v2/checkout/orders/ORDER-1' => Http::response($this->approvedOrder($invoice)),
        ]);

        $this->paypal()->capture($invoice, 'ORDER-1');

        $this->assertSame(0, $invoice->transactions()->count());
    }

    /** PayPal accepted the refund, the local recording fails: Log::critical is still reached. */
    public function test_holds_refund_accepted_by_paypal_but_not_recorded_logs_critical(): void
    {
        $invoice = $this->invoice();
        $transaction = ExtensionHelper::addPayment($invoice->id, 'PayPal', 20.00, null, 'CAPTURE-1', currency: 'USD');
        $this->fakePayPal(['/v2/payments/captures/CAPTURE-1/refund' => Http::response(['id' => 'R1', 'status' => 'PENDING'], 201)]);
        AdjustmentNote::creating(fn () => throw new RuntimeException('disk full'));
        Log::spy();

        try {
            ExtensionHelper::refund($transaction, 7.5);
            $this->fail('must throw');
        } catch (RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }

        Log::shouldHaveReceived('critical')->once();
        $this->assertEquals(0, $transaction->fresh()->refunded_amount);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/refund') && $request['amount']['value'] === '7.50');
    }

    /** A subscription sale (v1 sale id, not a capture): PayPal 404 now refuses the refund instead of "succeeding". */
    public function test_holds_a_gateway_refund_of_a_subscription_sale_is_refused_not_recorded(): void
    {
        $invoice = $this->invoice();
        $transaction = ExtensionHelper::addPayment($invoice->id, 'PayPal', 20.00, null, 'SALE-9', currency: 'USD');
        $this->fakePayPal(['/v2/payments/captures/SALE-9/refund' => Http::response(['name' => 'RESOURCE_NOT_FOUND', 'details' => [['issue' => 'INVALID_RESOURCE_ID']]], 404)]);

        $this->expectException(PayPalApiException::class);
        try {
            ExtensionHelper::refund($transaction, 20.00);
        } finally {
            $this->assertEquals(0, $transaction->fresh()->refunded_amount);
        }
    }

    /** A processing (pending) transaction cannot be refunded, and PayPal is not called. */
    public function test_holds_a_processing_transaction_is_not_refunded(): void
    {
        $invoice = $this->invoice();
        $transaction = ExtensionHelper::addProcessingPayment($invoice->id, 'PayPal', 20.00, null, 'CAP-P', currency: 'USD');
        $this->fakePayPal([]);

        $this->expectException(\InvalidArgumentException::class);
        try {
            ExtensionHelper::refund($transaction, 20.00);
        } finally {
            Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/refund'));
        }
    }

    /** The exception carries PayPal's error, never the bearer token or the request body. */
    public function test_holds_the_api_exception_does_not_leak_the_token_or_the_request(): void
    {
        $this->fakePayPal(['/v2/checkout/orders' => Http::response([
            'name' => 'INVALID_REQUEST', 'message' => 'Request is not well-formed', 'debug_id' => 'd1',
            'details' => [['issue' => 'INVALID_PARAMETER_VALUE', 'description' => 'bad', 'value' => 'VAULT-1']],
        ], 400)]);

        try {
            ExtensionHelper::charge($this->gateway, $this->invoice(), $this->agreement());
            $this->fail('must throw');
        } catch (DisplayException $shown) { // the customer sees a generic message; PayPal's error is the previous one
            $e = $shown->getPrevious();
            $this->assertInstanceOf(PayPalApiException::class, $e);
            $this->assertStringNotContainsString('SECRET-TOKEN', $shown->getMessage() . $e->getMessage());
            $this->assertStringNotContainsString('VAULT-1', $e->getMessage());
            $this->assertSame([['issue' => 'INVALID_PARAMETER_VALUE', 'description' => 'bad']], $e->toClientArray()['details']);
        }
    }

    /** A cancel PayPal refuses for another reason (404: wrong environment, deleted) throws and keeps the marker. */
    public function test_holds_a_cancel_already_cancelled_with_a_non_first_issue_still_throws(): void
    {
        $service = $this->service();
        $service->update(['subscription_id' => 'I-SUB1']);
        $service->properties()->create(['key' => 'has_paypal_subscription', 'name' => 'PayPal', 'value' => '1']);
        $this->fakePayPal(['/v1/billing/subscriptions/I-SUB1/cancel' => Http::response([
            'name' => 'RESOURCE_NOT_FOUND', 'details' => [['issue' => 'INVALID_RESOURCE_ID']],
        ], 404)]);

        $this->expectException(PayPalApiException::class);
        try {
            $this->paypal()->cancelSubscription($service->fresh());
        } finally {
            $this->assertSame('1', $service->properties()->where('key', 'has_paypal_subscription')->value('value'));
        }
    }
}
