<?php

namespace Tests\Feature;

use App\Exceptions\CouponUnusableException;
use App\Livewire\Services\Cancel;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sweep 9 (regression sweep, adversarial verifier, «coupons-checkout»). Failing tests for what was confirmed; nothing
 * here fixes app code. Invented data only, no network.
 */
class Sweep9CouponsCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Cache::flush();
        Carbon::setTestNow('2026-01-01 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * S9-COUPONS-CHECKOUT-1 (introduced by #793: before it every service carrying the coupon counted). A free-trial
     * coupon (100% of the first cycle, once per client, one use per user). The order is free: no invoice, the service
     * starts at once (PlaceOrderService, «Nothing to pay»), and it is a use. The cron later bills its first renewal;
     * the customer cancels from the client area, the unpaid renewal is cancelled with the service. Coupon::uses() now
     * reads «cancelled, billed, never paid» and drops it: the same customer orders again with the same coupon and gets
     * a second free period. Repeatable every cycle.
     *
     * Should: a service that was delivered free under the coupon stays a use, whatever happened to its later renewal.
     */
    public function test_s9_coupons_checkout_1_a_free_trial_taken_and_cancelled_after_its_renewal_was_billed_stays_a_use(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct()->product;
        $product->update(['autosetup' => Product::AUTOSETUP_PAYMENT, 'server_id' => null]);
        $product = $product->fresh();
        $coupon = Coupon::query()->create(['code' => 'S9TRIAL', 'type' => 'percentage', 'value' => 100, 'once_per_client' => true, 'max_uses_per_user' => 1]);
        $place = fn () => (new PlaceOrderService)->place($user->client(), 'USD',
            [new OrderLine($product, $product->plans()->firstOrFail()->load('prices'), 1, [])], $coupon->fresh());

        $placed = $place();
        $service = $placed->order->fresh()->services()->sole();
        $this->assertNull($placed->order->fresh()->invoice_id, 'precondition: a free order makes no invoice');
        $this->assertSame(Service::STATUS_ACTIVE, $service->status, 'precondition: the free service started at once');
        $this->assertNotNull($service->expires_at);
        $this->assertSame(1, $coupon->uses()->where('client_id', $user->client()->id)->count(), 'precondition: the free trial is a use');

        // The cron bills its first renewal.
        Carbon::setTestNow($service->expires_at->copy()->subDay()->setTime(10, 0));
        $this->artisan('app:cron-job');
        $renewal = $service->fresh()->invoices()->sole();
        $this->assertContains($renewal->status, Invoice::OPEN_STATUSES, 'precondition: an unpaid renewal invoice');

        // The customer cancels from the client area.
        Livewire::actingAs($user)->test(Cancel::class, ['service' => $service->fresh()])
            ->set('type', 'immediate')->set('reason', 'no longer needed')->call('cancelService');
        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status, 'precondition: cancelled');
        $this->assertSame(Invoice::STATUS_CANCELLED, $renewal->fresh()->status, 'precondition: its unpaid renewal cancelled with it');

        // Why (shown here, the failure is below): uses() dropped the service that was delivered free.
        $this->assertSame(0, $coupon->uses()->where('client_id', $user->client()->id)->count(),
            'the reason: the delivered free period is no longer counted as a use');

        try {
            $again = $place();
            $this->fail('the same customer got a second free trial with a once-per-client coupon: service #'
                . $again->order->fresh()->services()->sole()->id . ' is ' . $again->order->fresh()->services()->sole()->status);
        } catch (CouponUnusableException) {
            $this->addToAssertionCount(1);
        }
    }
}
