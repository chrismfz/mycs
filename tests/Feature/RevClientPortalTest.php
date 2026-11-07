<?php

namespace Tests\Feature;

use App\Livewire\Products\Checkout;
use App\Models\Category;
use App\Models\ConfigOption;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Services\Order\OrderPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Review of the client-portal sweep fixes (2026-11-07). Fake data only.
 */
class RevClientPortalTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ConfigOption, 1: ConfigOption} a checkbox option and its one value, hidden and priced */
    private function checkboxWithHiddenValue(Product $product, Plan $plan): array
    {
        $option = ConfigOption::query()->create(['name' => 'Legacy backup', 'env_variable' => 'backup', 'type' => 'checkbox']);
        $value = ConfigOption::query()->create(['name' => 'Legacy backup on', 'env_variable' => 'on', 'type' => 'checkbox',
            'parent_id' => $option->id, 'sort' => 0, 'hidden' => true]);
        $valuePlan = Plan::factory()->create(['priceable_id' => $value->id, 'priceable_type' => ConfigOption::class, 'name' => 'Monthly',
            'type' => 'recurring', 'billing_period' => $plan->billing_period, 'billing_unit' => $plan->billing_unit]);
        Price::factory()->create(['plan_id' => $valuePlan->id, 'price' => 5, 'currency_code' => 'USD']);
        DB::table('config_option_products')->insert(['config_option_id' => $option->id, 'product_id' => $product->id]);

        return [$option, $value];
    }

    /**
     * CLIENT-PORTAL-4: a WHMCS yes/no option whose one sub-option is hidden is still orderable. orderableChildren() is
     * applied to select/radio/slider only; OrderPricing::catalog and PlaceOrderService take a checkbox's
     * children->first() regardless of hidden, so ticking it prices it and gives the service the hidden value.
     */
    public function test_rev_cp_1_a_hidden_checkbox_value_is_not_priced(): void
    {
        $made = $this->createProduct();
        [$option] = $this->checkboxWithHiddenValue($made->product, $made->plan);
        $product = $made->product->fresh();

        $catalog = OrderPricing::catalog($product, $made->plan, [['option_id' => $option->id, 'value' => true]], 'USD');

        $this->assertSame('0.00', $catalog['options'], 'a hidden value is no choice of a new order, a checkbox\'s included');
    }

    /**
     * Same root, a regression in the view: checkout.blade computes $showPriceTag from orderableChildren() (empty here),
     * so the checkbox is still drawn, but now with no price on it, while ticking it charges 5.00 in the cart.
     */
    public function test_rev_cp_2_the_checkout_does_not_offer_a_checkbox_whose_value_is_hidden(): void
    {
        $category = Category::factory()->create(['slug' => 'hosting']);
        $made = $this->createProduct(['category_id' => $category->id, 'slug' => 'starter']);
        $this->checkboxWithHiddenValue($made->product, $made->plan);

        Livewire::test(Checkout::class, ['category' => $category, 'product' => 'starter'])
            ->assertDontSee('Legacy backup');
    }
}
