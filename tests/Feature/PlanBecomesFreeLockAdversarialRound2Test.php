<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial review of the plan lock, round 2 (#398, «WHMCS's 0»): the coupon check reads every coupon not expired as
 * «pays every cycle». None of these loses money; each refuses what staff asked for, or blocks a plan for a service that
 * is never billed again anyway.
 */
class PlanBecomesFreeLockAdversarialRound2Test extends TestCase
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
     * ADV2-1: the «0» contract (owner 2026-10-23, the toggle's helper text: «Or set its price to 0 in the same save»).
     * A service at 10 under a 50% coupon for 3 cycles: staff set the price to 0 and pick the free plan in one save, as
     * WHMCS's «0». $after still has the coupon running, so it is read as paying and refused: the «0» does not work for
     * any service with a coupon, and on a free plan the coupon bills nothing anyway.
     */
    public function test_adv2_1_a_price_set_to_0_in_the_same_save_works_under_a_running_coupon_too(): void
    {
        $coupon = Coupon::create(['code' => 'HALF3', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 3]);
        $service = $this->service(['coupon_id' => $coupon->id, 'coupon_started_at' => now()]);

        $this->assertFalse($this->refused(fn () => $service->applyStaffChanges(['plan_id' => $this->free->id, 'price' => 0])),
            'staff set the price to 0 in the same save: WHMCS\'s «0», no confirmation needed');
        $this->assertSame($this->free->id, (int) $service->fresh()->plan_id);
    }

    /**
     * ADV2-2: a 100% coupon «for ever» (recurring 0: cycles() = 0) with no required product: RenewalBilling never expires
     * it, so the service is billed 0 every cycle for good. paysEveryCycle() reads it as paying: its plan cannot become
     * free, and staff must confirm «never bill it again» for a service that is never billed.
     */
    public function test_adv2_2_a_forever_free_coupon_is_not_paying(): void
    {
        $coupon = Coupon::create(['code' => 'FOREVER', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 100, 'recurring' => 0]);
        $service = $this->service(['price' => 0, 'coupon_id' => $coupon->id, 'coupon_started_at' => now()]);

        $this->assertFalse($service->paysEveryCycle(), 'free for ever: nothing is billed, now or later');
        $this->assertFalse($this->refused(fn () => $this->recurring->update(['type' => 'free'])), 'blocked by a service that never pays');
    }

    /**
     * ADV2-3: a first-cycle coupon (recurring empty: cycles() = 1) is never in the renewal price (RenewalBilling: «nothing
     * to take back»), and coupon_expired_at is only stamped at the next renewal. A service staff set to 0 is counted as
     * paying until then, though its renewals bill 0.
     */
    public function test_adv2_3_a_first_cycle_coupon_on_a_price_of_0_is_not_paying(): void
    {
        $coupon = Coupon::create(['code' => 'FIRST', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 100, 'recurring' => null]);
        $service = $this->service(['price' => 0, 'coupon_id' => $coupon->id, 'coupon_started_at' => now()]);

        $this->assertFalse($service->paysEveryCycle(), 'a first-cycle coupon never changes a renewal price');
        $this->assertSame(0, Service::query()->payingEveryCycle()->whereKey($service->id)->count());
    }
}
