<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\PaymentGatewayEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use Tests\TestCase;

/**
 * PayPal's webhook signature is verified on the event as it came (live, 2026-11-02): two of the first events were
 * refused on every retry, because the event was decoded and encoded again before it was sent to verify-webhook-signature
 * (a `{}` comes back `[]`, Greek text as \u escapes) and no longer matched its signature.
 */
class PayPalWebhookRawEventTest extends TestCase
{
    use RefreshDatabase;

    private const PAYPAL_API = 'https://api-m.sandbox.paypal.com';

    private const VERIFY = self::PAYPAL_API . '/v1/notifications/verify-webhook-signature';

    private const HEADERS = [
        'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
        'PAYPAL-CERT-URL' => 'https://api.paypal.com/v1/notifications/certs/CERT-1',
        'PAYPAL-TRANSMISSION-ID' => 'T-1',
        'PAYPAL-TRANSMISSION-SIG' => 'sig/+="1"',
        'PAYPAL-TRANSMISSION-TIME' => '2026-11-02T12:36:05Z',
    ];

    /** @var list<string> the bodies sent to verify-webhook-signature */
    private array $verified = [];

    protected function setUp(): void
    {
        parent::setUp();
        $gateway = Gateway::query()->create(['extension' => 'PayPal', 'name' => 'PayPal', 'type' => 'gateway']);
        foreach (['client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'WH-1', 'test_mode' => '1'] as $key => $value) {
            $gateway->settings()->create(['key' => $key, 'value' => $value]);
        }
        Http::fake([
            self::PAYPAL_API . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            // As PayPal: SUCCESS only for the event exactly as it was signed (its bytes, here), else FAILURE.
            self::VERIFY => function (Request $request) {
                $this->verified[] = $request->body();
                if ($this->expiredTokens > 0) {
                    $this->expiredTokens--;

                    return Http::response(['error' => 'invalid_token'], 401);
                }
                $signed = $this->signed ?? null;

                return Http::response(['verification_status' => $signed !== null && str_ends_with($request->body(), ',"webhook_event":' . $signed . '}') ? 'SUCCESS' : 'FAILURE']);
            },
        ]);
        /** @var PayPal $paypal */
        $paypal = ExtensionHelper::getExtension('gateway', 'PayPal', $gateway->fresh()->settings);
        $paypal->boot();
    }

    private ?string $signed = null;

    /** How many verification calls answer 401 (an expired token) first. */
    private int $expiredTokens = 0;

    private function postEvent(string $raw)
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach (self::HEADERS as $name => $value) {
            $server['HTTP_' . str_replace('-', '_', $name)] = $value;
        }

        return $this->call('POST', '/extensions/paypal/webhook', [], [], [], $server, $raw);
    }

    public function test_an_event_with_an_empty_object_and_greek_text_is_verified_as_it_came(): void
    {
        $raw = '{"id":"WH-EV-1","event_type":"CHECKOUT.ORDER.APPROVED","resource":{"id":"ORDER-1","payer":{"name":{"given_name":"Χρήστος"}},'
            . '"payment_source":{"paypal":{"attributes":{}}},"links":[{"href":"https://api.paypal.com/v2/checkout/orders/ORDER-1"}]}}';
        $this->signed = $raw;

        $this->postEvent($raw)->assertOk();

        $this->assertCount(1, $this->verified);
        $sent = json_decode($this->verified[0], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('WH-1', $sent['webhook_id']);
        $this->assertSame('sig/+="1"', $sent['transmission_sig'], 'the headers are sent as they came, quotes and all');
        $this->assertSame('SHA256withRSA', $sent['auth_algo']);
        $this->assertSame('T-1', $sent['transmission_id']);
        $this->assertSame('https://api.paypal.com/v1/notifications/certs/CERT-1', $sent['cert_url']);
        $this->assertSame('2026-11-02T12:36:05Z', $sent['transmission_time']);
    }

    /** Review of #601: an expired token's retry sends the same bytes again, not an empty body. */
    public function test_an_expired_token_retry_sends_the_event_as_it_came_again(): void
    {
        $raw = '{"event_type":"CHECKOUT.ORDER.APPROVED","resource":{"id":"ORDER-2","payment_source":{"paypal":{"attributes":{}}}}}';
        $this->signed = $raw;
        $this->expiredTokens = 1;

        $this->postEvent($raw)->assertOk();

        $this->assertCount(2, $this->verified);
        $this->assertSame($this->verified[0], $this->verified[1]);
    }

    public function test_a_handled_event_is_still_recorded(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100]);
        ExtensionHelper::addPayment($invoice->id, 'PayPal', 100, transactionId: 'CAP-1', currency: 'usd');
        // A refund event with an empty object in it: refused before, so a refund in PayPal's dashboard was never recorded.
        $raw = '{"event_type":"PAYMENT.CAPTURE.REFUNDED","resource":{"id":"REF-1","status":"COMPLETED","amount":{"value":"25.00","currency_code":"USD"},'
            . '"seller_payable_breakdown":{},"links":[{"rel":"up","href":"' . self::PAYPAL_API . '/v2/payments/captures/CAP-1"}]}}';
        $this->signed = $raw;

        $this->postEvent($raw)->assertOk();

        $this->assertSame('25.00', (string) $invoice->transactions()->sole()->refunded_amount);
    }

    public function test_an_event_changed_after_signing_is_refused(): void
    {
        $this->signed = '{"event_type":"PAYMENT.CAPTURE.REFUNDED","resource":{"id":"REF-1"}}';

        $this->postEvent('{"event_type":"PAYMENT.CAPTURE.REFUNDED","resource":{"id":"REF-2"}}')->assertStatus(400);
    }

    /** Only a JSON object is an event: anything else is refused without asking PayPal, and never embedded in the call. */
    public function test_a_body_that_is_not_a_json_object_is_refused_without_asking_paypal(): void
    {
        foreach (['', 'not json', '[1,2]', '"text"', '{"a":1}}', '{"a":1},"webhook_id":"WH-OTHER"', ' [] '] as $raw) {
            $this->postEvent($raw)->assertStatus(400);
        }

        $this->assertSame([], $this->verified);
        // Logged as refused calls whose signature failed (noise, throttled), never as errors.
        $this->assertSame(7, PaymentGatewayEvent::query()->where('outcome', PaymentGatewayEvent::OUTCOME_REJECTED)->where('signature_verified', false)->count());
    }
}
