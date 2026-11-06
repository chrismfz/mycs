<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\PaymentGatewayEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use Tests\TestCase;

/**
 * Adversarial review of #601 (PayPal webhook verified on the raw event). Invented data only.
 */
class PayPalWebhookRawEventAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const PAYPAL_API = 'https://api-m.sandbox.paypal.com';

    private const VERIFY = self::PAYPAL_API . '/v1/notifications/verify-webhook-signature';

    /** @var list<string> */
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
            self::VERIFY => function (Request $request) {
                $this->verified[] = $request->body();

                return Http::response(['verification_status' => 'FAILURE']);
            },
        ]);
        /** @var PayPal $paypal */
        $paypal = ExtensionHelper::getExtension('gateway', 'PayPal', $gateway->fresh()->settings);
        $paypal->boot();
    }

    /** @param  array<string, string>  $headers */
    private function postEvent(string $raw, array $headers = [])
    {
        $headers += [
            'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
            'PAYPAL-CERT-URL' => 'https://api.paypal.com/v1/notifications/certs/CERT-1',
            'PAYPAL-TRANSMISSION-ID' => 'T-1',
            'PAYPAL-TRANSMISSION-SIG' => 'c2lnbmF0dXJl',
            'PAYPAL-TRANSMISSION-TIME' => '2026-11-02T12:36:05Z',
        ];
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . str_replace('-', '_', $name)] = $value;
        }

        return $this->call('POST', '/extensions/paypal/webhook', [], [], [], $server, $raw);
    }

    /**
     * ADV-601-1, Medium. Anyone can post to the webhook with a PAYPAL-* header that is not valid UTF-8 (one stray byte).
     * The verification payload is now built with json_encode(..., JSON_THROW_ON_ERROR), so the request dies with an
     * uncaught JsonException: HTTP 500, which the gateway log closes as an «error» with no signature verdict. Such a row
     * is not «noise» (never throttled per address, MT-3), the refused-callback throttle does not count it (5xx), and it
     * mails staff «failed with HTTP 500: if it was a payment, it is NOT recorded» — a forged request raising a money
     * alarm. Expected: a request whose headers cannot be sent to PayPal is refused as any unverifiable one: 400, logged
     * as rejected with the signature not verified (whether PayPal is asked or not).
     */
    public function test_adv_601_1_a_header_that_is_not_utf8_is_refused_not_a_500(): void
    {
        $response = $this->postEvent('{"id":"WH-EV-9","event_type":"PAYMENT.CAPTURE.REFUNDED","resource":{"id":"REF-9"}}',
            ['PAYPAL-TRANSMISSION-SIG' => "c2ln\xC3\x28"]);

        $response->assertStatus(400);
        $event = PaymentGatewayEvent::query()->sole();
        $this->assertSame(PaymentGatewayEvent::OUTCOME_REJECTED, $event->outcome);
        $this->assertFalse((bool) $event->signature_verified);
        $this->assertNotNull($event->signature_verified);
    }
}
