<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Paymenter\Extensions\Gateways\PayPal\PayPal;
use Paymenter\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

/**
 * Service\Updated is dispatched after commit (ServiceObserver). By then the model's originals are synced, so the
 * gateway listeners must read the changes the event carries, not isDirty(): otherwise a service cancelled inside a
 * transaction (cron termination, staff edit, renewal) keeps its Stripe/PayPal subscription billing.
 */
class SubscriptionSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function service(string $gateway, array $attributes = []): Service
    {
        $product = $this->createProduct();

        $service = Service::factory()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => now()->addMonth(),
            'currency_code' => 'USD',
            'price' => 10.00,
            'subscription_id' => 'sub_123',
        ], $attributes));

        $service->properties()->create([
            'key' => $gateway === 'stripe' ? 'has_stripe_subscription' : 'has_paypal_subscription',
            'name' => 'Subscription',
            'value' => '1',
        ]);

        return $service->fresh();
    }

    private function bootStripe(): void
    {
        Http::fake(['https://api.stripe.com/*' => Http::response([
            'id' => 'sub_123',
            'schedule' => null,
            'current_period_end' => now()->addMonth()->timestamp,
            'items' => ['data' => [['id' => 'si_1', 'price' => ['product' => 'prod_1']]]],
        ])]);

        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();
    }

    private function bootPayPal(): void
    {
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            'https://api-m.sandbox.paypal.com/*' => Http::response([], 204),
        ]);

        (new PayPal(['test_mode' => true, 'client_id' => 'id', 'client_secret' => 'secret']))->boot();
    }

    private function terminateViaCron(Service $service): void
    {
        $service->forceFill([
            'status' => 'suspended',
            'dunning_suspended_at' => now()->subDays(20),
            'expires_at' => now()->subDays(30),
        ])->saveQuietly();

        config(['settings.cronjob_order_terminate' => 14]);
        Queue::fake();

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
    }

    public function test_cron_termination_cancels_the_stripe_subscription(): void
    {
        $service = $this->service('stripe');
        $this->bootStripe();

        $this->terminateViaCron($service);

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === 'https://api.stripe.com/v1/subscriptions/sub_123');
        $this->assertNull($service->fresh()->subscription_id);
    }

    public function test_cron_termination_cancels_the_paypal_subscription(): void
    {
        $service = $this->service('paypal');
        $this->bootPayPal();

        $this->terminateViaCron($service);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/sub_123/cancel');
    }

    public function test_staff_cancellation_inside_a_transaction_cancels_the_stripe_subscription(): void
    {
        $service = $this->service('stripe');
        $this->bootStripe();

        DB::transaction(function () use ($service) {
            $service->update(['status' => Service::STATUS_CANCELLED]);
            // A later save in the same transaction must not hide the status change.
            $service->update(['price' => 12.00]);
        });

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === 'https://api.stripe.com/v1/subscriptions/sub_123');
    }

    public function test_cancelled_then_reactivated_in_the_same_transaction_keeps_the_subscription(): void
    {
        $service = $this->service('stripe');
        $this->bootStripe();

        DB::transaction(function () use ($service) {
            $service->update(['status' => Service::STATUS_CANCELLED]);
            $service->update(['status' => 'active']);
        });

        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
    }

    public function test_price_change_inside_a_transaction_updates_the_stripe_subscription(): void
    {
        $service = $this->service('stripe');
        $this->bootStripe();

        DB::transaction(fn () => $service->update(['price' => 12.00]));

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.stripe.com/v1/subscription_items/si_1'
            && $request['price_data']['unit_amount'] == 1200);
    }

    public function test_price_change_inside_a_transaction_updates_the_paypal_subscription(): void
    {
        $service = $this->service('paypal');
        $this->bootPayPal();

        DB::transaction(fn () => $service->update(['price' => 12.00]));

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && $request->url() === 'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/sub_123');
    }

    public function test_an_unrelated_change_does_not_call_the_gateway(): void
    {
        $service = $this->service('stripe');
        $this->bootStripe();

        DB::transaction(fn () => $service->update(['suspend_hold_until' => now()->addDays(5)]));

        Http::assertNothingSent();
    }
}
