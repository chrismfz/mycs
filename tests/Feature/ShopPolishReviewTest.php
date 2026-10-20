<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Independent review of #268 (shop polish): failing tests for what the review found. */
class ShopPolishReviewTest extends TestCase
{
    use RefreshDatabase;

    private Category $hosting;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']);
        $this->hosting = Category::factory()->create(['name' => 'Hosting', 'slug' => 'hosting', 'show_in_menu' => true]);
    }

    /** @param  list<array{type: string, period?: int, unit?: string, price?: float}>  $plans */
    private function product(Category $category, string $slug, array $plans): Product
    {
        $product = Product::factory()->create(['category_id' => $category->id, 'name' => strtoupper($slug), 'slug' => $slug, 'stock' => null]);
        foreach ($plans as $i => $p) {
            $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Plan ' . $i,
                'type' => $p['type'], 'billing_period' => $p['period'] ?? 1, 'billing_unit' => $p['unit'] ?? 'month', 'sort' => $i]);
            if ($p['type'] !== 'free') {
                Price::factory()->create(['plan_id' => $plan->id, 'price' => $p['price'] ?? 5, 'currency_code' => 'USD']);
            }
        }

        return $product->fresh();
    }

    /**
     * Product::price() is «Free» whenever ANY available plan is free, but the card and the product page take the
     * cycle from $plans->first(): a monthly plan sorted before the free one makes the card read «Free / month».
     */
    public function test_a_free_price_is_not_shown_with_the_cycle_of_another_plan(): void
    {
        $this->product($this->hosting, 'trial', [['type' => 'recurring', 'period' => 1, 'unit' => 'month', 'price' => 5], ['type' => 'free']]);

        $this->get('/products/hosting')->assertOk()->assertSee('Free')->assertDontSee('/ month');
        $this->get('/products/hosting/trial')->assertOk()->assertSee('Free')->assertDontSee('/ month');
    }

    /** A one-time plan's line in the cart reads «Billed per one time» (el: «Χρέωση ανά εφάπαξ»). */
    public function test_a_one_time_line_in_the_cart_does_not_read_per_one_time(): void
    {
        $product = $this->product($this->hosting, 'setup', [['type' => 'one-time', 'price' => 50]]);
        $cart = Cart::create(['currency_code' => 'USD']);
        $cart->items()->create(['product_id' => $product->id, 'plan_id' => $product->plans->first()->id,
            'config_options' => [], 'checkout_config' => [], 'quantity' => 1]);

        $page = $this->withCookie('cart', $cart->ulid)->get('/cart')->assertOk()->assertSee('SETUP');

        $page->assertDontSee(__('product.per_cycle', ['cycle' => __('product.one_time')]));
    }

    /**
     * The menu marks the open category or its parent only: a category two levels down (its root in the menu, the
     * breadcrumb showing the whole chain) leaves nothing marked.
     */
    public function test_a_grandchild_category_marks_its_root_in_the_menu(): void
    {
        $other = Category::factory()->create(['name' => 'Licenses', 'slug' => 'licenses', 'show_in_menu' => true]);
        $this->product($other, 'lic', [['type' => 'recurring']]);
        $child = Category::factory()->create(['name' => 'WordPress', 'slug' => 'wordpress', 'parent_id' => $this->hosting->id]);
        $grandchild = Category::factory()->create(['name' => 'Managed', 'slug' => 'managed', 'parent_id' => $child->id]);
        $this->product($grandchild, 'wp1', [['type' => 'recurring']]);

        $html = $this->get('/products/managed')->assertOk()->assertSeeInOrder(['Hosting', 'WordPress', 'Managed'])->getContent();

        $this->assertMatchesRegularExpression('#<a href="[^"]*/products/hosting"[^>]*aria-current#', $html);
    }

    /** The «Small Images» option (Appearance) no longer applies to the subcategory cards: always a full-width image. */
    public function test_small_images_still_applies_to_subcategory_cards(): void
    {
        config(['settings.theme_small_images' => true]);
        $child = Category::factory()->create(['name' => 'WordPress', 'slug' => 'wordpress', 'parent_id' => $this->hosting->id, 'image' => 'categories/wp.png']);
        $this->product($child, 'wp1', [['type' => 'recurring']]);

        $html = $this->get('/products/hosting')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<img [^>]*alt="WordPress"[^>]*class="[^"]*w-14#', $html);
    }
}
