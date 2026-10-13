<?php

namespace Tests\Feature;

use App\Admin\Actions\CancelSubscriptionAction;
use App\Models\Gateway;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Staff "Cancel Subscription" (service page and the order page's services): the subscription id is cleared only when
 * a gateway confirmed the cancellation. The order page's copy still cleared it on failure, losing the id of a
 * subscription the gateway kept billing.
 */
class AdminCancelSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api-m.sandbox.paypal.com';

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        foreach (['test_mode' => '1', 'client_id' => 'id', 'client_secret' => 'secret'] as $key => $value) {
            $gateway->settings()->create(['key' => $key, 'value' => $value]);
        }

        $product = $this->createProduct();
        $this->service = Service::factory()->create([
            'user_id' => User::factory()->create()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 10.00,
            'subscription_id' => 'I-SUB1',
        ]);
        $this->service->properties()->create(['key' => 'has_paypal_subscription', 'name' => 'PayPal', 'value' => '1']);
    }

    private function fakeCancel(int $status): void
    {
        Http::fake([
            self::API . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::API . '/v1/billing/subscriptions/I-SUB1/cancel' => Http::response($status === 204 ? null : ['name' => 'INTERNAL_SERVER_ERROR'], $status),
        ]);
    }

    public function test_a_confirmed_cancellation_clears_the_id(): void
    {
        $this->fakeCancel(204);

        $this->assertNull(CancelSubscriptionAction::cancel($this->service));

        $this->assertNull($this->service->fresh()->subscription_id);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/I-SUB1/cancel'));
    }

    public function test_a_failed_cancellation_keeps_the_id_and_says_why(): void
    {
        $this->fakeCancel(500);

        $error = CancelSubscriptionAction::cancel($this->service);

        $this->assertStringContainsString('500', (string) $error);
        $this->assertSame('I-SUB1', $this->service->fresh()->subscription_id);
    }

    public function test_no_gateway_taking_it_keeps_the_id(): void
    {
        $this->service->update(['subscription_id' => 'unknown-1']);
        $this->service->properties()->delete(); // no gateway's marker, not a PayPal I- id
        $this->fakeCancel(204);

        $this->assertSame('', CancelSubscriptionAction::cancel($this->service->fresh()));
        $this->assertSame('unknown-1', $this->service->fresh()->subscription_id);
    }
}
