<?php

namespace Tests\Feature;

use App\Models\Gateway;
use App\Models\PaymentGatewayEvent;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MyCS\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

/**
 * Adversarial review of PR #341 (gateway callback throttle).
 *
 * ADV-341-1: the callback limit counts every request, genuine or forged, in one bucket per address for all gateways.
 * Where callers share an address (behind a proxy with «trust all proxies», or with none set), anyone sending 300
 * forged callbacks a minute holds back every genuine Stripe webhook (and Cardlink return), although both are verified
 * locally and never call the gateway back: the limit protects nothing there and refuses real payments. The refused
 * genuine callbacks are then also «noise» for the gateway log, whose per-address budget the flood has already spent.
 */
class Adv341GatewayTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_adv341';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
    }

    private function stripe(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        $gateway->settings()->updateOrCreate(['key' => 'stripe_secret_key'], ['value' => 'sk_test']);
        $gateway->settings()->updateOrCreate(['key' => 'stripe_webhook_secret'], ['value' => self::SECRET]);
        (new Stripe(['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET]))->boot();
    }

    private function stripeEvent(string $forwardedFor, bool $signed)
    {
        $time = time();
        $payload = (string) json_encode(['type' => 'customer.created', 'data' => ['object' => ['id' => 'cus_adv']]]);
        $signature = $signed ? hash_hmac('sha256', $time . '.' . $payload, self::SECRET) : str_repeat('0', 64);

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1', // the site's reverse proxy
            'HTTP_X_FORWARDED_FOR' => $forwardedFor,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=' . $time . ',v1=' . $signature,
        ], $payload);
    }

    public function test_a_flood_of_forged_callbacks_behind_the_proxy_does_not_hold_back_a_genuine_stripe_event(): void
    {
        config(['settings.trusted_proxies' => ['*']]);
        $this->stripe();

        for ($i = 0; $i < AppServiceProvider::GATEWAY_CALLBACKS_PER_MINUTE; $i++) {
            $this->stripeEvent('198.51.100.' . ($i % 250 + 1), signed: false)->assertStatus(400);
        }

        // Stripe's genuine event, through the same proxy.
        $before = PaymentGatewayEvent::query()->count();
        $this->stripeEvent('3.18.12.63', signed: true)->assertOk();
        $this->assertSame($before + 1, PaymentGatewayEvent::query()->count(), 'the genuine event is in the gateway log');
    }
}
