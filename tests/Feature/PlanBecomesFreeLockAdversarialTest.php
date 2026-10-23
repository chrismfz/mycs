<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial review of the plan lock (billing-cursor §5): ways a service that pays every cycle still ends up on a free
 * or one-time plan without anyone confirming that it is never billed again.
 */
class PlanBecomesFreeLockAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Plan $recurring;

    private Plan $free;

    protected function setUp(): void
    {
        parent::setUp();
        $made = $this->createProduct();
        $this->product = $made->product;
        $this->recurring = $made->plan;
        $this->recurring->update(['type' => 'recurring']);
        $this->free = (new Plan)->forceFill(['name' => 'Free', 'type' => 'free', 'priceable_id' => $this->product->id, 'priceable_type' => Product::class]);
        $this->free->save();
    }

    private function service(array $attributes = []): Service
    {
        return Service::factory()->create(array_merge(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $this->recurring->id,
            'product_id' => $this->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'expires_at' => now()->addMonth()], $attributes));
    }

    private function refused(callable $change): bool
    {
        return rescue($change, fn ($e) => $e, false) instanceof DisplayException;
    }

    /**
     * ADV-1: a paying service set to move to plan B «from the next renewal» (ChangeCycle, next_plan_id). B becomes free:
     * the guard counts only services with plan_id = B, so it lets it through, and the cron then moves the service to the
     * free plan at its next renewal (RenewalBilling::billRenewal), never to be billed again.
     */
    public function test_adv1_a_plan_paying_services_will_move_to_does_not_become_free(): void
    {
        $next = (new Plan)->forceFill(['name' => 'Yearly', 'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'year',
            'priceable_id' => $this->product->id, 'priceable_type' => Product::class]);
        $next->save();
        $this->service(['next_plan_id' => $next->id]);

        $this->assertTrue($this->refused(fn () => $next->update(['type' => 'free'])),
            'a service pays every cycle and moves to this plan at its next renewal');
        $this->assertSame('recurring', $next->fresh()->type);
    }

    /**
     * ADV-2: a pending upgrade (waiting for its invoice to be paid) to plan B. B becomes free; when the upgrade is paid,
     * ServiceUpgradeService moves the service to B and reprices it from the free plan: never billed again, unconfirmed.
     */
    public function test_adv2_a_plan_a_pending_upgrade_goes_to_does_not_become_free(): void
    {
        $other = $this->createProduct();
        $other->plan->update(['type' => 'recurring']);
        $service = $this->service();
        (new ServiceUpgrade)->forceFill(['service_id' => $service->id, 'product_id' => $other->product->id, 'plan_id' => $other->plan->id,
            'status' => ServiceUpgrade::STATUS_PENDING])->save();

        $this->assertTrue($this->refused(fn () => $other->plan->update(['type' => 'free'])),
            'a paying service moves to this plan when its upgrade is paid');
    }

    /**
     * ADV-3: a pending service (ordered, its first invoice open) at a price. Its plan becomes free (the Plan guard counts
     * active and suspended only), or staff move it to a free plan (endsBillingRefusal skips pending): once the order is
     * paid it runs on the free plan and is never billed again.
     */
    public function test_adv3_a_pending_service_is_guarded_too(): void
    {
        $service = $this->service(['status' => Service::STATUS_PENDING, 'expires_at' => null]);

        $this->assertNotNull(Service::endsBillingRefusal($service, $this->free), 'staff move a pending paying service to a free plan unconfirmed');
        $this->assertTrue($this->refused(fn () => $this->recurring->update(['type' => 'free'])), 'its plan becomes free under it');
    }

    /**
     * ADV-4: a service at price 0 only for now: a 100% coupon «for 3 cycles» runs (RenewalBilling bills the full price
     * once the cycles are past). `price <= 0` reads it as free: moved to a free plan unconfirmed, and its plan may become
     * free under it.
     */
    public function test_adv4_a_price_of_zero_under_a_coupon_for_cycles_still_pays_later(): void
    {
        $coupon = Coupon::create(['code' => 'FREE3', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 100, 'recurring' => 3]);
        $service = $this->service(['price' => 0, 'coupon_id' => $coupon->id, 'coupon_started_at' => now()]);

        $this->assertNotNull(Service::endsBillingRefusal($service, $this->free), 'it is billed at the full price after the coupon');
        $this->assertTrue($this->refused(fn () => $this->recurring->update(['type' => 'free'])), 'its plan becomes free under it');
    }
}
