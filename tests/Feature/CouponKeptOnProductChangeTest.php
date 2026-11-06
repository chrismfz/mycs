<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\User;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ADV-650-1: a coupon «kept on upgrades» (WHMCS «Lifetime Promotion») follows a service to the product an upgrade gives
 * it, where it covers it, as MyCS always did; one that is not ends with the upgrade, and the new product renews at its
 * full price, as WHMCS drops a promotion that is not lifetime.
 */
class CouponKeptOnProductChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-02 10:00:00');
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        Setting::query()->updateOrCreate(['key' => 'default_currency', 'settingable_type' => null], ['value' => 'USD', 'type' => 'string']);
        config(['settings.default_currency' => 'USD']);
        Cache::forget('settings.rows');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{Product, Plan} */
    private function product(string $name, float $price): array
    {
        $product = Product::factory()->create(['name' => $name, 'server_id' => null, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return [$product, $plan];
    }

    /** @return array{Service, ServiceUpgrade} */
    private function upgradeWith(bool $kept): array
    {
        [$small, $smallPlan] = $this->product('Small', 10.00);
        [$big, $bigPlan] = $this->product('Big', 30.00);
        $coupon = Coupon::query()->create(['code' => 'TWENTY' . (int) $kept, 'type' => 'percentage', 'value' => 20, 'applies_to' => 'all',
            'recurring' => 0, 'kept_on_product_change' => $kept]);
        $coupon->products()->attach([$small->id, $big->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'product_id' => $small->id,
            'plan_id' => $smallPlan->id, 'currency_code' => 'USD', 'price' => 8.00, 'status' => Service::STATUS_ACTIVE,
            'coupon_id' => $coupon->id, 'coupon_started_at' => now()->subMonth(), 'expires_at' => now()->addMonth()]);

        return [$service, ServiceUpgrade::create(['service_id' => $service->id, 'product_id' => $big->id, 'plan_id' => $bigPlan->id, 'status' => 'pending'])];
    }

    public function test_a_coupon_not_kept_ends_with_the_upgrade(): void
    {
        [$service, $upgrade] = $this->upgradeWith(false);
        $this->assertSame('30.00', $upgrade->newRecurringPrice(), 'the new product at its full price');

        (new ServiceUpgradeService)->handle($upgrade);

        $service->refresh();
        $this->assertSame('30.00', number_format((float) $service->price, 2, '.', ''));
        $this->assertNotNull($service->coupon_expired_at, 'ended: never applied again on a recalculation or a renewal');
        $this->assertSame('30.00', (string) $service->calculatePrice());
    }

    public function test_a_coupon_kept_follows_the_upgrade_as_before(): void
    {
        [$service, $upgrade] = $this->upgradeWith(true);
        $this->assertSame('24.00', $upgrade->newRecurringPrice());

        (new ServiceUpgradeService)->handle($upgrade);

        $service->refresh();
        $this->assertSame('24.00', number_format((float) $service->price, 2, '.', ''));
        $this->assertNull($service->coupon_expired_at);
    }
}
