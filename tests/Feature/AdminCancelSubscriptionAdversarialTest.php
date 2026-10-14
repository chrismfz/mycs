<?php

namespace Tests\Feature;

use App\Admin\Actions\CancelSubscriptionAction;
use App\Admin\Resources\OrderResource\Pages\EditOrder;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Models\Gateway;
use App\Models\Order;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Paymenter\Extensions\Gateways\PayPal\PayPal;
use Paymenter\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

/**
 * Adversarial review of #52 (staff "Cancel Subscription" on the service and order pages).
 */
class AdminCancelSubscriptionAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const PAYPAL = 'https://api-m.sandbox.paypal.com';

    private const STRIPE = 'https://api.stripe.com/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function paypalGateway(): Gateway
    {
        $gateway = Gateway::query()->create(['extension' => 'PayPal', 'name' => 'PayPal', 'type' => 'gateway']);
        foreach (['test_mode' => '1', 'client_id' => 'id', 'client_secret' => 'secret'] as $key => $value) {
            $gateway->settings()->create(['key' => $key, 'value' => $value]);
        }

        return $gateway;
    }

    private function stripeGateway(): Gateway
    {
        $gateway = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway']);
        $gateway->settings()->create(['key' => 'stripe_secret_key', 'value' => 'sk_test']);

        return $gateway;
    }

    private function makeService(string $subscriptionId, array $markers = [], ?int $orderId = null): Service
    {
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 10.00,
            'subscription_id' => $subscriptionId, 'order_id' => $orderId,
        ]);
        foreach ($markers as $marker) {
            $service->properties()->create(['key' => $marker, 'name' => $marker, 'value' => '1']);
        }

        return $service;
    }

    private function admin(array $permissions = ['*']): User
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return User::factory()->create(['role_id' => Role::create(['name' => 'Adv' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function cancelOnOrderPage($page, Service $service)
    {
        return $page->callAction(TestAction::make('Cancel Subscription ID')->schemaComponent('services.record-' . $service->id . '.subscription_id', 'form'));
    }

    // ---- holds ----

    public function test_holds_service_page_cancel_then_save_does_not_write_the_id_back(): void
    {
        $this->paypalGateway();
        $service = $this->makeService('I-SUB1', ['has_paypal_subscription']);
        Http::fake([
            self::PAYPAL . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::PAYPAL . '/v1/billing/subscriptions/I-SUB1/cancel' => Http::response(null, 204),
        ]);

        $page = Livewire::actingAs($this->admin())->test(EditService::class, ['record' => $service->getRouteKey()]);
        $page->callAction(TestAction::make('Cancel Subscription ID')->schemaComponent('subscription_id', 'form'))
            ->assertNotified('The subscription has been successfully cancelled');
        $page->call('save')->assertHasNoErrors();

        $this->assertNull($service->fresh()->subscription_id);
    }

    public function test_holds_order_page_stripe_cancel_then_save_cancels_once_and_clears(): void
    {
        $this->paypalGateway(); // listed first; must not touch a Stripe id
        $this->stripeGateway();
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();
        (new PayPal(['test_mode' => true, 'client_id' => 'id', 'client_secret' => 'secret']))->boot();
        $order = Order::query()->create(['user_id' => User::factory()->create()->id, 'currency_code' => 'USD']);
        $service = $this->makeService('sub_123', ['has_stripe_subscription'], $order->id);
        Http::fake([self::STRIPE . '/subscriptions/sub_123' => Http::response(['id' => 'sub_123', 'status' => 'canceled'])]);

        $page = Livewire::actingAs($this->admin())->test(EditOrder::class, ['record' => $order->getRouteKey()]);
        $this->cancelOnOrderPage($page, $service)->assertNotified('The subscription has been successfully cancelled');
        $page->call('save')->assertHasNoErrors();

        $this->assertNull($service->fresh()->subscription_id);
        $this->assertSame(0, $service->properties()->where('key', 'has_stripe_subscription')->count());
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'paypal'));
    }

    public function test_holds_order_page_cancelling_one_service_keeps_the_others_id(): void
    {
        $this->paypalGateway();
        $order = Order::query()->create(['user_id' => User::factory()->create()->id, 'currency_code' => 'USD']);
        $a = $this->makeService('I-AAA', ['has_paypal_subscription'], $order->id);
        $b = $this->makeService('I-BBB', ['has_paypal_subscription'], $order->id);
        Http::fake([
            self::PAYPAL . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::PAYPAL . '/v1/billing/subscriptions/I-AAA/cancel' => Http::response(null, 204),
        ]);

        $page = Livewire::actingAs($this->admin())->test(EditOrder::class, ['record' => $order->getRouteKey()]);
        $this->cancelOnOrderPage($page, $a)->assertNotified('The subscription has been successfully cancelled');
        $page->call('save')->assertHasNoErrors();

        $this->assertNull($a->fresh()->subscription_id);
        $this->assertSame('I-BBB', $b->fresh()->subscription_id);
    }

    public function test_holds_stale_stripe_marker_on_a_paypal_id_with_stripe_listed_first(): void
    {
        $this->stripeGateway();
        $this->paypalGateway();
        $service = $this->makeService('I-SUB1', ['has_stripe_subscription', 'has_paypal_subscription']);
        Http::fake([
            self::STRIPE . '/*' => Http::response(['error' => ['code' => 'resource_missing']], 404),
            self::PAYPAL . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::PAYPAL . '/v1/billing/subscriptions/I-SUB1/cancel' => Http::response(null, 204),
        ]);

        $this->assertNull(CancelSubscriptionAction::cancel($service));
        $this->assertNull($service->fresh()->subscription_id);
    }

    public function test_holds_unconfigured_stripe_row_does_not_break_a_paypal_cancel(): void
    {
        Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway']); // no settings
        $this->paypalGateway();
        $service = $this->makeService('I-SUB1', ['has_paypal_subscription']);
        Http::fake([
            self::PAYPAL . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::PAYPAL . '/v1/billing/subscriptions/I-SUB1/cancel' => Http::response(null, 204),
        ]);

        $this->assertNull(CancelSubscriptionAction::cancel($service));
    }

    // ---- findings ----

    /** ADV-1: ExtensionHelper catches Exception, not Throwable: an Error in one gateway stops the others. */
    public function test_an_error_in_one_gateway_does_not_stop_the_others(): void
    {
        $this->paypalGateway(); // listed first, stale PayPal marker on a Stripe id
        $this->stripeGateway();
        $service = $this->makeService('sub_123', ['has_paypal_subscription', 'has_stripe_subscription']);
        Http::fake([
            self::PAYPAL . '/v1/oauth2/token' => Http::response(['access_token' => null]), // generateAccessToken(): string -> TypeError
            self::STRIPE . '/subscriptions/sub_123' => Http::response(['id' => 'sub_123', 'status' => 'canceled']),
        ]);

        $this->assertNull(CancelSubscriptionAction::cancel($service));
        $this->assertNull($service->fresh()->subscription_id);
    }

    /** ADV-2: a Stripe subscription already gone at Stripe (404 resource_missing) can never be cleared by the action. */
    public function test_a_stripe_subscription_already_gone_at_stripe_is_cleared(): void
    {
        $this->stripeGateway();
        $service = $this->makeService('sub_gone', ['has_stripe_subscription']);
        Http::fake([self::STRIPE . '/subscriptions/sub_gone' => Http::response([
            'error' => ['code' => 'resource_missing', 'message' => 'No such subscription: sub_gone', 'type' => 'invalid_request_error'],
        ], 404)]);

        $this->assertNull(CancelSubscriptionAction::cancel($service));
        $this->assertNull($service->fresh()->subscription_id);
    }

    /** ADV-3: order-only staff (no admin.services.update) can stop a service's recurring billing from the order page. */
    public function test_staff_without_services_update_cannot_cancel_from_the_order_page(): void
    {
        $this->paypalGateway();
        $order = Order::query()->create(['user_id' => User::factory()->create()->id, 'currency_code' => 'USD']);
        $service = $this->makeService('I-SUB1', ['has_paypal_subscription'], $order->id);
        Http::fake([
            self::PAYPAL . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::PAYPAL . '/v1/billing/subscriptions/I-SUB1/cancel' => Http::response(null, 204),
        ]);
        $staff = $this->admin(['admin.orders.viewAny', 'admin.orders.viewAny', 'admin.orders.update']);

        $page = Livewire::actingAs($staff)->test(EditOrder::class, ['record' => $order->getRouteKey()]);
        try {
            $this->cancelOnOrderPage($page, $service);
        } catch (\Throwable) {
            // hidden/unauthorized is the expected outcome
        }

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/cancel'));
        $this->assertSame('I-SUB1', $service->fresh()->subscription_id);
    }

    /** ADV-4 (pre-existing): a Stripe sub_ id without the marker is cancelled by staff now, but never by automatic termination. */
    public function test_automatic_termination_cancels_a_stripe_subscription_without_marker(): void
    {
        $service = $this->makeService('sub_legacy');
        Http::fake([self::STRIPE . '/*' => Http::response(['id' => 'sub_legacy', 'status' => 'canceled'])]);
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();

        $service->forceFill(['status' => 'suspended', 'dunning_suspended_at' => now()->subDays(20), 'expires_at' => now()->subDays(30)])->saveQuietly();
        config(['settings.cronjob_order_terminate' => 14]);
        Queue::fake();
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === self::STRIPE . '/subscriptions/sub_legacy');
    }
}
