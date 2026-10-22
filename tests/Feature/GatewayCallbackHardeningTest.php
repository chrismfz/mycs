<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\Gateway;
use App\Models\PaymentGatewayEvent;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use MyCS\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

/**
 * The gateways' callbacks (security audit PAY-SEC-2/7/8): a Stripe event is taken only while fresh, a flood of
 * callbacks from one address is refused, and an approved PayPal vault setup becomes a saved method only of the account
 * and login that started it.
 */
class GatewayCallbackHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_hard';

    private const PAYPAL_API = 'https://api-m.sandbox.paypal.com';

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

    private function stripeEvent(int $time, ?string $signatures = null)
    {
        $payload = (string) json_encode(['type' => 'customer.created', 'data' => ['object' => ['id' => 'cus_1']]]);
        $signature = hash_hmac('sha256', $time . '.' . $payload, self::SECRET);

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=' . $time . ',' . ($signatures ?? 'v1=' . $signature),
        ], $payload);
    }

    public function test_a_fresh_stripe_event_is_taken(): void
    {
        $this->stripe();

        $this->stripeEvent(time())->assertOk();
        $this->stripeEvent(time() - 200)->assertOk();
    }

    public function test_an_old_or_future_stripe_event_is_refused_even_signed(): void
    {
        $this->stripe();

        $this->stripeEvent(time() - Stripe::SIGNATURE_TOLERANCE - 60)->assertStatus(400);
        $this->stripeEvent(time() + Stripe::SIGNATURE_TOLERANCE + 60)->assertStatus(400);
    }

    public function test_any_of_the_headers_signatures_may_match_while_a_secret_rolls(): void
    {
        $this->stripe();
        $time = time();
        $payload = (string) json_encode(['type' => 'customer.created', 'data' => ['object' => ['id' => 'cus_1']]]);
        $good = hash_hmac('sha256', $time . '.' . $payload, self::SECRET);

        $this->stripeEvent($time, 'v1=' . hash_hmac('sha256', $time . '.' . $payload, 'whsec_old') . ',v1=' . $good)->assertOk();
        $this->stripeEvent($time, 'v1=' . str_repeat('0', 64) . ',v0=' . $good)->assertStatus(400);
    }

    public function test_refused_callbacks_that_ask_the_gateway_back_wait_past_the_limit(): void
    {
        Http::fake(['*' => Http::response(['verification_status' => 'FAILURE', 'access_token' => 'token'])]);
        $this->paypal();

        for ($i = 0; $i < AppServiceProvider::GATEWAY_REFUSED_CALLBACKS_PER_MINUTE; $i++) {
            $this->postJson('/extensions/paypal/webhook', ['event_type' => 'PAYMENT.CAPTURE.COMPLETED'])->assertStatus(400);
        }

        $this->postJson('/extensions/paypal/webhook', ['event_type' => 'PAYMENT.CAPTURE.COMPLETED'])
            ->assertStatus(503)->assertHeader('Retry-After', '60');
        $this->assertSame('throttled', PaymentGatewayEvent::query()->latest('id')->first()?->reason);

        // Another address is not held up by it.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
        $this->postJson('/extensions/paypal/webhook', ['event_type' => 'PAYMENT.CAPTURE.COMPLETED'])->assertStatus(400);
    }

    public function test_stripe_events_are_never_held_up_by_forged_ones(): void
    {
        $this->stripe();
        for ($i = 0; $i < AppServiceProvider::GATEWAY_REFUSED_CALLBACKS_PER_MINUTE + 5; $i++) {
            $this->stripeEvent(time(), 'v1=' . str_repeat('0', 64))->assertStatus(400);
        }

        $this->stripeEvent(time())->assertOk();
    }

    public function test_a_cache_that_is_down_never_stops_a_callback(): void
    {
        $this->stripe();
        RateLimiter::shouldReceive('tooManyAttempts')->andThrow(new \RuntimeException('cache down'));
        RateLimiter::shouldReceive('hit')->andThrow(new \RuntimeException('cache down'));
        Http::fake(['*' => Http::response(['verification_status' => 'FAILURE', 'access_token' => 'token'])]);
        $this->paypal();

        $this->postJson('/extensions/paypal/webhook', ['event_type' => 'X'])->assertStatus(400);
    }

    private function paypal(): PayPal
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        foreach (['test_mode' => '1', 'client_id' => 'id', 'client_secret' => 'secret', 'paypal_support_billing_agreements' => '1'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Http::fake([
            self::PAYPAL_API . '/v1/oauth2/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
            self::PAYPAL_API . '/v3/vault/setup-tokens' => Http::sequence()
                ->push(['id' => 'SETUP-A', 'links' => [['href' => 'self'], ['href' => 'https://paypal.test/approve/A']]])
                ->push(['id' => 'SETUP-B', 'links' => [['href' => 'self'], ['href' => 'https://paypal.test/approve/B']]]),
            self::PAYPAL_API . '/v3/vault/payment-tokens' => Http::response(['id' => 'VAULT-1', 'payment_source' => ['paypal' => ['email_address' => 'payer@example.test']]]),
        ]);
        Route::group([], base_path('extensions/Gateways/PayPal/routes.php'));
        app('router')->getRoutes()->refreshNameLookups();
        /** @var PayPal $paypal */
        $paypal = ExtensionHelper::getExtension('gateway', 'PayPal', $gateway->fresh()->settings);

        return $paypal;
    }

    /** Starts a vault setup as $user (as PaymentMethods does), returning the approval link. */
    private function startSetup(PayPal $paypal, User $user): string
    {
        $this->actingAs($user);

        return $paypal->createBillingAgreement($user->client());
    }

    private function approve(User $user, string $token)
    {
        return $this->actingAs($user)->withSession($this->loginUser($user))
            ->get(route('extensions.gateways.paypal.setup-agreement', ['approval_token_id' => $token]));
    }

    public function test_the_account_that_started_a_setup_saves_it_once(): void
    {
        $paypal = $this->paypal();
        $owner = User::factory()->create();
        $this->startSetup($paypal, $owner);

        $this->approve($owner, 'SETUP-A')->assertRedirect(route('account.payment-methods'));
        $this->assertSame(1, BillingAgreement::query()->where('client_id', $owner->client()->id)->count());

        $this->approve($owner, 'SETUP-A');
        $this->assertSame(1, BillingAgreement::query()->count(), 'a setup token is saved once');
    }

    public function test_another_logins_try_leaves_the_setup_to_its_owner(): void
    {
        $paypal = $this->paypal();
        $owner = User::factory()->create();
        $this->startSetup($paypal, $owner);

        $this->approve(User::factory()->create(), 'SETUP-A');
        $this->approve($owner, 'SETUP-A');

        $this->assertSame(1, BillingAgreement::query()->where('client_id', $owner->client()->id)->count());
    }

    public function test_a_setup_started_by_another_account_saves_nothing(): void
    {
        $paypal = $this->paypal();
        $victim = User::factory()->create();
        $attacker = User::factory()->create();
        // The victim's own setup, approved at PayPal: the attacker learns its token and returns with it.
        $this->startSetup($paypal, $victim);

        $this->approve($attacker, 'SETUP-A');
        // A token this site never started.
        $this->approve($attacker, 'SETUP-FORGED');

        $this->assertSame(0, BillingAgreement::query()->count());
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/v3/vault/payment-tokens'));
    }
}
