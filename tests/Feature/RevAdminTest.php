<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Independent review of the admin sweep fixes (ADMIN-1..4): each test fails on fix/sweep-admin for the reason in its docblock. */
class RevAdminTest extends TestCase
{
    use RefreshDatabase;

    private function placed(Coupon $coupon): Service
    {
        Queue::fake();
        $product = Product::factory()->create(['autosetup' => Product::AUTOSETUP_NEVER, 'server_id' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => '10.00', 'currency_code' => 'USD']);

        return (new PlaceOrderService)->place(User::factory()->create()->client(), 'USD', [new OrderLine($product->fresh(), $plan)], coupon: $coupon)
            ->order->services()->sole();
    }

    /**
     * ADMIN-4 (the third path in its scenario, not fixed): clearing the Coupon on the service's own page (or
     * coupon_id: null through the admin API, both go through Service::applyStaffChanges) still only sets
     * coupon_id = null. A running 3-cycle 50% coupon's discount stays in the price for good, as before the fix:
     * the coupon page's «Remove coupon» and deleting the coupon give the full price back, this path does not.
     */
    public function test_clearing_a_running_coupon_on_the_service_page_keeps_its_discount_for_ever(): void
    {
        $service = $this->placed(Coupon::create(['code' => 'THREE', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 3]));
        $this->assertSame('5.00', number_format((float) $service->fresh()->price, 2, '.', ''));

        $service->fresh()->applyStaffChanges(['coupon_id' => null]);

        $this->assertNull($service->fresh()->coupon_id);
        $this->assertSame('10.00', number_format((float) $service->fresh()->price, 2, '.', ''),
            'Cleared on the service page, the running coupon\'s discount stays in the renewal price for ever.');
    }
}
