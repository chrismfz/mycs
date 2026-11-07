<?php

namespace Tests\Feature;

use App\Admin\Resources\CouponResource\Pages\EditCoupon;
use App\Classes\Cart as CartClass;
use App\Exceptions\DisplayException;
use App\Livewire\Products\Checkout;
use App\Livewire\Services\Upgrade;
use App\Models\Cart;
use App\Models\Category;
use App\Models\ConfigOption;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Order\OrderPricing;
use App\Support\AuthCookies;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The fixes of the client-portal sweep (2026-11-07) beyond its own tests: a hidden value is not taken by an upgrade but
 * kept by the service that has it, is not priced in a cart; an imported coupon keeps its last day when edited; a
 * currency link does not move a cart with items. Fake data only.
 */
class SweepClientPortalFixTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ConfigOption, 1: ConfigOption, 2: ConfigOption} the option, a value, a hidden value (both priced) */
    private function optionWithHiddenValue(Product $product, Plan $plan): array
    {
        $option = ConfigOption::query()->create(['name' => 'Extra IP', 'env_variable' => 'ip', 'type' => 'select', 'upgradable' => true]);
        $one = ConfigOption::query()->create(['name' => 'One IP', 'env_variable' => 'one', 'type' => 'select', 'parent_id' => $option->id, 'sort' => 0]);
        $legacy = ConfigOption::query()->create(['name' => 'Legacy free IP', 'env_variable' => 'legacy', 'type' => 'select', 'parent_id' => $option->id, 'sort' => 1, 'hidden' => true]);
        foreach ([$one, $legacy] as $value) {
            $valuePlan = Plan::factory()->create(['priceable_id' => $value->id, 'priceable_type' => ConfigOption::class, 'name' => 'Monthly',
                'type' => 'recurring', 'billing_period' => $plan->billing_period, 'billing_unit' => $plan->billing_unit]);
            Price::factory()->create(['plan_id' => $valuePlan->id, 'price' => $value->is($one) ? 2 : 1, 'currency_code' => 'USD']);
        }
        DB::table('config_option_products')->insert(['config_option_id' => $option->id, 'product_id' => $product->id]);

        return [$option, $one, $legacy];
    }

    public function test_a_hidden_value_is_not_priced_in_a_cart(): void
    {
        $made = $this->createProduct();
        [$option, $one, $legacy] = $this->optionWithHiddenValue($made->product, $made->plan);
        $product = $made->product->fresh();

        $visible = OrderPricing::catalog($product, $made->plan, [['option_id' => $option->id, 'value' => $one->id]], 'USD');
        $hidden = OrderPricing::catalog($product, $made->plan, [['option_id' => $option->id, 'value' => $legacy->id]], 'USD');

        $this->assertSame('2.00', $visible['options']);
        $this->assertSame('0.00', $hidden['options'], 'a hidden value is no choice: not priced (and not given, PlaceOrderService)');
    }

    public function test_the_checkout_offers_only_the_values_that_may_be_ordered(): void
    {
        $category = Category::factory()->create(['slug' => 'hosting']);
        $made = $this->createProduct(['category_id' => $category->id, 'slug' => 'starter']);
        [$option, $one, $legacy] = $this->optionWithHiddenValue($made->product, $made->plan);

        Livewire::test(Checkout::class, ['category' => $category, 'product' => 'starter'])
            ->assertSet("configOptions.{$option->id}", $one->id)
            ->assertSee('One IP')
            ->assertDontSee('Legacy free IP');
    }

    /** @return array{0: Service, 1: Product, 2: ConfigOption, 3: ConfigOption, 4: ConfigOption} */
    private function upgradeWithHiddenValue(User $user): array
    {
        $from = $this->createProduct();
        $to = $this->createProduct();
        DB::table('product_upgrades')->insert(['product_id' => $from->product->id, 'upgrade_id' => $to->product->id]);
        [$option, $one, $legacy] = $this->optionWithHiddenValue($to->product, $to->plan);
        $service = Service::factory()->create([
            'client_id' => $user->client()->id, 'product_id' => $from->product->id, 'plan_id' => $from->plan->id,
            'status' => 'active', 'price' => 10.00, 'currency_code' => 'USD', 'expires_at' => now()->addDays(20),
        ]);

        return [$service, $to->product, $option, $one, $legacy];
    }

    public function test_an_upgrade_cannot_take_a_hidden_value(): void
    {
        $user = User::factory()->create();
        [$service, $to, $option, , $legacy] = $this->upgradeWithHiddenValue($user);

        $this->livewireAs($user)->test(Upgrade::class, ['service' => $service])
            ->set('upgrade', $to->id)
            ->set("configOptions.{$option->id}", $legacy->id)
            ->call('doUpgrade')
            ->assertHasErrors("configOptions.{$option->id}");
    }

    public function test_a_service_that_has_a_hidden_value_keeps_it_through_an_upgrade(): void
    {
        $user = User::factory()->create();
        [$service, $to, $option, , $legacy] = $this->upgradeWithHiddenValue($user);
        $service->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $legacy->id]);

        $this->livewireAs($user)->test(Upgrade::class, ['service' => $service->fresh()])
            ->set('upgrade', $to->id)
            ->set("configOptions.{$option->id}", $legacy->id)
            ->call('doUpgrade')
            ->assertHasNoErrors("configOptions.{$option->id}");
    }

    public function test_an_imported_coupon_keeps_its_last_day_when_edited(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]), 'staff');
        $coupon = Coupon::query()->create(['code' => 'OLD', 'value' => '10', 'type' => 'percentage', 'applies_to' => 'all',
            'expires_at' => '2026-12-31 23:59:59']); // as the WHMCS import stores it (endOfDay)

        Livewire::test(EditCoupon::class, ['record' => $coupon->getRouteKey()])
            ->fillForm(['max_uses' => 5])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-12-31 23:59:59', $coupon->fresh()->expires_at->format('Y-m-d H:i:s'));
        Carbon::setTestNow('2027-01-01 00:00:01');
        try {
            $this->expectException(DisplayException::class);
            CartClass::validateCoupon('OLD');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_currency_link_does_not_move_a_cart_with_items(): void
    {
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $category = Category::factory()->create(['slug' => 'hosting']);
        $made = $this->createProduct(['category_id' => $category->id]);
        $cart = Cart::create(['currency_code' => 'EUR']);
        $cart->items()->create(['product_id' => $made->product->id, 'plan_id' => $made->plan->id, 'config_options' => [], 'checkout_config' => [], 'quantity' => 1]);

        $this->withCookie(AuthCookies::cart(), $cart->ulid)->withSession(['currency' => 'EUR'])
            ->get('/products/hosting?currency=USD')->assertOk();

        $this->assertSame('EUR', session('currency'));
        $this->assertSame('EUR', $cart->fresh()->currency_code);
    }
}
