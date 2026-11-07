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
use App\Services\Service\RemoveCoupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Sweep ADMIN-4: a coupon taken off a service gives the full price back only when its discount is in the price. */
class RemoveCouponTest extends TestCase
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

    private static function price(Service $service): string
    {
        return number_format((float) $service->fresh()->price, 2, '.', '');
    }

    public function test_a_coupon_for_ever_gives_the_full_price_back(): void
    {
        $service = $this->placed(Coupon::create(['code' => 'EVER', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 0]));
        $this->assertSame('5.00', self::price($service));

        RemoveCoupon::handle($service);

        $this->assertNull($service->fresh()->coupon_id);
        $this->assertSame('10.00', self::price($service));
    }

    public function test_a_first_cycle_coupon_keeps_the_price_it_never_was_in(): void
    {
        $service = $this->placed(Coupon::create(['code' => 'FIRST', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50]));
        $service->update(['price' => '8.00']); // staff's price

        RemoveCoupon::handle($service);

        $this->assertNull($service->fresh()->coupon_id);
        $this->assertSame('8.00', self::price($service));
    }

    public function test_an_ended_coupon_keeps_the_price_staff_set_after_it(): void
    {
        $service = $this->placed(Coupon::create(['code' => 'ENDED', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 3]));
        Service::query()->whereKey($service->id)->update(['coupon_expired_at' => now(), 'price' => '7.00']);

        RemoveCoupon::handle($service);

        $this->assertNull($service->fresh()->coupon_id);
        $this->assertSame('7.00', self::price($service));
    }

    public function test_deleting_a_coupon_gives_each_of_its_services_the_full_price_back(): void
    {
        $coupon = Coupon::create(['code' => 'TWO', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 2]);
        $a = $this->placed($coupon);
        $b = $this->placed($coupon);

        $coupon->delete();

        $this->assertSame(['10.00', '10.00'], [self::price($a), self::price($b)]);
        $this->assertSame([null, null], [$a->fresh()->coupon_id, $b->fresh()->coupon_id]);
    }
}
