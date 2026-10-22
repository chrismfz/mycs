<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\Currency;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Money\GatewayReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Adversarial review of #328 (S0-6, the instance's mark on its gateway payments). The mark is not a secret: it is the
 * slug of the site's own host (my.nixpal.com → «my-nixpal-com»), so anyone can write it into an order or a subscription
 * made with the public PayPal client id. And the «made here» record of a PayPal order lives in a cache the app itself
 * flushes.
 */
class Adv328GatewayReferenceTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api-m.sandbox.paypal.com';

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

    private function invoice(float $price = 20): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function paypal(array $fakes = []): PayPal
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        foreach (['test_mode' => '1', 'client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'wh'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $all = [self::API . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::API . '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'])];
        foreach ($fakes as $path => $response) {
            $all[self::API . $path] = $response;
        }
        Http::fake($all);
        /** @var PayPal $paypal */
        $paypal = ExtensionHelper::getExtension('gateway', 'PayPal', $gateway->fresh()->settings);
        $paypal->boot();

        return $paypal;
    }

    /** @return array<string, mixed> an order as PayPal's GET answers it */
    private static function order(string $id, Invoice $invoice, string $status, string $payee, array $captures = []): array
    {
        return ['id' => $id, 'status' => $status, 'purchase_units' => [array_filter([
            'invoice_id' => GatewayReference::paypalInvoiceId($invoice),
            'amount' => ['currency_code' => 'USD', 'value' => '20.00'],
            'payee' => ['merchant_id' => $payee, 'email_address' => strtolower($payee) . '@example.test'],
            'payments' => $captures === [] ? null : ['captures' => $captures],
        ])]];
    }

    /**
     * ADV-1. An order made here (pay() remembered it) whose payee was changed before the capture: Orders v2 lets the
     * buyer's side PATCH `purchase_units[].payee.email` (the JS SDK's actions.order.patch, client id only). The money
     * goes to the other merchant; capture() checks only the cache record and the invoice_id, so the invoice is paid here.
     */
    public function test_adv1_an_order_of_ours_whose_payee_was_changed_is_never_captured(): void
    {
        $invoice = $this->invoice();
        $paypal = $this->paypal([
            '/v2/checkout/orders' => Http::response(self::order('ORDER-P', $invoice, 'CREATED', 'OURS'), 201),
            '/v2/checkout/orders/ORDER-P' => Http::response(self::order('ORDER-P', $invoice, 'APPROVED', 'ATTACKER')),
            '/v2/checkout/orders/ORDER-P/capture' => Http::response(self::order('ORDER-P', $invoice, 'COMPLETED', 'ATTACKER', [[
                'id' => 'CAP-P', 'status' => 'COMPLETED', 'amount' => ['value' => '20.00', 'currency_code' => 'USD'],
            ]])),
        ]);
        $paypal->pay($invoice, 20);

        try {
            $paypal->capture($invoice, 'ORDER-P');
        } catch (HttpException) {
            // refused: what is wanted
        }

        $this->assertSame(0, $invoice->transactions()->where('status', InvoiceTransactionStatus::Succeeded)->count(),
            'the invoice is paid here while the money went to another PayPal account');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    /**
     * ADV-2. PAYMENT.CAPTURE.COMPLETED of an order this site never made (not remembered by pay(), no vault charge, no
     * transaction of its capture): built with the public client id, our guessable mark in its invoice_id, another payee.
     * The webhook reads it with our credentials (our app made it) and records it: capture()'s S0-6 check is bypassed.
     */
    public function test_adv2_a_capture_webhook_of_an_order_not_made_here_records_nothing(): void
    {
        $invoice = $this->invoice();
        $this->paypal(['/v2/checkout/orders/ORDER-F' => Http::response(self::order('ORDER-F', $invoice, 'COMPLETED', 'ATTACKER', [[
            'id' => 'CAP-F', 'status' => 'COMPLETED', 'amount' => ['value' => '20.00', 'currency_code' => 'USD'],
        ]]))]);

        $this->postJson('/extensions/paypal/webhook', ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => [
            'id' => 'CAP-F', 'amount' => ['value' => '20.00', 'currency_code' => 'USD'],
            'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-F']],
        ]])->assertSuccessful();

        $this->assertSame(0, $invoice->transactions()->count(), 'an order never made here, paid to another merchant, paid our invoice');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    /**
     * ADV-3. MyCS never creates PayPal subscriptions (no plan, no createSubscription), so every BILLING.SUBSCRIPTION.CREATED
     * is someone else's. The mark is public, so a subscription made with the public client id and custom_id
     * «<mark>-<victim invoice>» still takes the victim's service: subscription_id set, has_paypal_subscription on, and
     * from then on AutoCharge skips it and the reminders say «it will be charged» (PaymentReminders::chargedOnItsOwn).
     */
    public function test_adv3_a_subscription_with_our_public_mark_never_takes_a_service(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 20.00, 'subscription_id' => null,
        ]);
        $invoice = $this->invoice();
        $invoice->items()->create(['description' => 'Renewal', 'quantity' => 1, 'price' => 20, 'reference_type' => Service::class, 'reference_id' => $service->id]);
        $this->paypal();

        $this->postJson('/extensions/paypal/webhook', ['event_type' => 'BILLING.SUBSCRIPTION.CREATED', 'resource' => [
            'id' => 'I-EVIL', 'custom_id' => GatewayReference::paypalInvoiceId($invoice),
        ]])->assertSuccessful();

        $this->assertSame('', (string) $service->fresh()->subscription_id, 'a subscription no one here made took the service');
        $this->assertNull($service->properties()->where('key', 'has_paypal_subscription')->first());
    }

    /**
     * ADV-4. The «made here» record of a PayPal order is only a cache entry, and the app flushes the whole cache itself:
     * every Currency saved (Currency::booted), a WHMCS catalog import adding one, `cache:clear`, a Redis eviction. A
     * customer who approved the order in PayPal's window then has the capture refused: our own payment lost to us.
     */
    public function test_adv4_a_cache_flush_between_pay_and_capture_never_refuses_our_own_order(): void
    {
        $invoice = $this->invoice();
        $paypal = $this->paypal([
            '/v2/checkout/orders' => Http::response(['id' => 'ORDER-OK', 'status' => 'CREATED'], 201),
            '/v2/checkout/orders/ORDER-OK' => Http::response(self::order('ORDER-OK', $invoice, 'APPROVED', 'OURS')),
            '/v2/checkout/orders/ORDER-OK/capture' => Http::response(self::order('ORDER-OK', $invoice, 'COMPLETED', 'OURS', [[
                'id' => 'CAP-OK', 'status' => 'COMPLETED', 'amount' => ['value' => '20.00', 'currency_code' => 'USD'],
            ]])),
        ]);
        $paypal->pay($invoice, 20);

        // Staff edit a currency's format while the customer is in PayPal's window: Currency::saved flushes the cache.
        $currency = Currency::query()->where('code', 'USD')->first() ?? Currency::factory()->create(['code' => 'USD']);
        $currency->update(['prefix' => '$ ']);

        try {
            $paypal->capture($invoice, 'ORDER-OK');
        } catch (HttpException $e) {
            $this->fail('our own order, approved by the customer, refused after a cache flush: ' . $e->getMessage());
        }

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/ORDER-OK/capture'));
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /**
     * ADV-5 (round 2). The payee is patched between capture()'s GET and its capture POST (the buyer's side controls the
     * timing and can retry at will). PayPal's capture answers with its default `Prefer: return=minimal`, which carries no
     * payee, so the after-capture check (`isset($response->purchase_units[0]->payee)`) is skipped and the capture is
     * recorded here, while the money went to the other merchant.
     */
    public function test_adv5_a_payee_patched_between_our_read_and_the_capture_is_never_recorded(): void
    {
        $invoice = $this->invoice();
        $paid = [['id' => 'CAP-R', 'status' => 'COMPLETED', 'amount' => ['value' => '20.00', 'currency_code' => 'USD']]];
        $paypal = $this->paypal([
            '/v2/checkout/orders/ORDER-R' => Http::sequence()
                ->push(self::order('ORDER-R', $invoice, 'APPROVED', 'OURS'))
                ->whenEmpty(Http::response(self::order('ORDER-R', $invoice, 'COMPLETED', 'ATTACKER', $paid))),
            '/v2/checkout/orders/ORDER-R/capture' => function (Request $request) use ($invoice, $paid) {
                if (str_contains(implode(',', $request->header('Prefer')), 'return=representation')) {
                    return Http::response(self::order('ORDER-R', $invoice, 'COMPLETED', 'ATTACKER', $paid), 201);
                }

                // PayPal's minimal capture answer: no payee.
                return Http::response(['id' => 'ORDER-R', 'status' => 'COMPLETED', 'purchase_units' => [['reference_id' => 'default', 'payments' => ['captures' => $paid]]]], 201);
            },
        ]);
        PayPal::rememberOrder('ORDER-R', $invoice, (object) ['merchant_id' => 'OURS', 'email_address' => 'ours@example.test']);

        try {
            $paypal->capture($invoice, 'ORDER-R');
        } catch (HttpException) {
            // refused: what is wanted
        }

        $this->assertSame(0, $invoice->transactions()->whereIn('status', [InvoiceTransactionStatus::Succeeded, InvoiceTransactionStatus::Processing])->count(),
            'a capture to another payee recorded as our payment');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    /**
     * ADV-6 (round 2, regression). A vault charge whose answer is lost (a timeout, a 5xx: SavedMethodCharge keeps the
     * attempt UNKNOWN) may have taken the money. Before #328, PAYMENT.CAPTURE.COMPLETED recorded it; now charge() never
     * reached rememberOrder(), so the webhook calls our own vault charge «foreign» and records nothing. The invoice stays
     * unpaid and, after KEY_REUSE_HOURS, AutoCharge charges it again with a new key: the customer pays twice.
     */
    public function test_adv6_our_vault_charge_whose_answer_was_lost_is_recorded_by_its_capture_webhook(): void
    {
        $invoice = $this->invoice();
        $order = self::order('ORDER-V', $invoice, 'COMPLETED', 'OURS', [[
            'id' => 'CAP-V', 'status' => 'COMPLETED', 'amount' => ['value' => '20.00', 'currency_code' => 'USD'],
        ]]);
        $order['payment_source'] = ['paypal' => ['attributes' => ['vault' => ['id' => 'VAULT-1', 'status' => 'VAULTED']]]];
        $paypal = $this->paypal([
            '/v2/checkout/orders' => Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 500), // taken at PayPal, answer lost
            '/v2/checkout/orders/ORDER-V' => Http::response($order),
        ]);
        $gateway = Gateway::query()->where('extension', 'PayPal')->sole();
        $agreement = BillingAgreement::create([
            'client_id' => $this->user->client()->id, 'gateway_id' => $gateway->id, 'name' => 'PayPal', 'external_reference' => 'VAULT-1', 'type' => 'paypal',
        ]);
        try {
            $paypal->charge($invoice, 20, $agreement);
        } catch (\Throwable) {
            // the 5xx is rethrown: SavedMethodCharge keeps the attempt UNKNOWN
        }

        $this->postJson('/extensions/paypal/webhook', ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => [
            'id' => 'CAP-V', 'amount' => ['value' => '20.00', 'currency_code' => 'USD'],
            'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-V']],
        ]])->assertSuccessful();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'our own vault charge, taken at PayPal, dropped as foreign');
    }
}
