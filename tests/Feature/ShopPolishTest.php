<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The shop's pages, polished (2026-10-20): the category over its products, prices with their cycle, one way to order. */
class ShopPolishTest extends TestCase
{
    use RefreshDatabase;

    private Category $hosting;

    private Category $licenses;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']);
        $this->hosting = Category::factory()->create(['name' => 'Wordpress Hosting', 'slug' => 'wordpress-hosting', 'show_in_menu' => true, 'description' => '<p>Fast sites.</p>']);
        $this->licenses = Category::factory()->create(['name' => 'Licenses', 'slug' => 'licenses', 'show_in_menu' => true]);
    }

    /** @param  list<array{0: int, 1: string, 2: string}>  $plans  [period, unit, name] */
    private function product(Category $category, string $name, array $plans = [[1, 'year', 'Annually'], [2, 'year', 'Biennially'], [3, 'year', 'Triennially']]): Product
    {
        $product = Product::factory()->create(['category_id' => $category->id, 'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)),
            'description' => '<ul><li>20 GB NVMe</li></ul>', 'stock' => null]);
        foreach ($plans as $i => [$period, $unit, $planName]) {
            $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => $planName,
                'type' => 'recurring', 'billing_period' => $period, 'billing_unit' => $unit, 'sort' => $i]);
            Price::factory()->create(['plan_id' => $plan->id, 'price' => 504 * $period, 'currency_code' => 'USD']);
        }

        return $product;
    }

    public function test_the_category_names_itself_over_its_products_with_the_menu_marking_it(): void
    {
        $this->product($this->hosting, 'LITESPEED 20');
        $this->product($this->licenses, 'Zonecloud');

        $page = $this->get('/products/wordpress-hosting')->assertOk();

        $page->assertSeeInOrder(['Wordpress Hosting', 'Fast sites.', __('product.categories'), 'Licenses', 'LITESPEED 20'], false);
        $this->assertMatchesRegularExpression('#<a href="[^"]*/products/wordpress-hosting"[^>]*aria-current="page"#', $page->getContent());
        $this->assertDoesNotMatchRegularExpression('#<a href="[^"]*/products/licenses"[^>]*aria-current#', $page->getContent());
    }

    public function test_a_card_has_its_price_per_cycle_what_it_gives_and_one_way_to_order(): void
    {
        $product = $this->product($this->hosting, 'LITESPEED 20');

        $page = $this->get('/products/wordpress-hosting')->assertOk();

        $page->assertSee('$504.00')->assertSee('/ year')->assertSee(__('product.also_for', ['cycles' => '2 years, 3 years']))->assertSee('20 GB NVMe');
        $page->assertSee(route('products.checkout', ['category' => $this->hosting, 'product' => $product->slug]))->assertSee(__('product.order'));
        $page->assertSee(__('product.details'))->assertDontSee('ri-shopping-bag', false);
    }

    public function test_the_cycle_reads_in_greek(): void
    {
        app()->setLocale('el');
        $product = $this->product($this->hosting, 'P', [[1, 'month', 'Monthly'], [2, 'year', 'Biennially']]);
        [$monthly, $biennially] = $product->plans->all();

        $this->assertSame(['μήνα', '2 έτη', 'Μήνα', '2 έτη'], [$monthly->cycle(), $biennially->cycle(), $monthly->label(), $biennially->label()]);
        $own = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Starter', 'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'year']);
        $this->assertSame('Starter (έτος)', $own->label(), "a name of staff's own is kept");
        $this->assertSame('εφάπαξ', Plan::factory()->make(['type' => 'one-time'])->cycle());
    }

    public function test_the_checkout_names_the_plans_by_their_cycle(): void
    {
        app()->setLocale('el');
        $this->product($this->hosting, 'LITESPEED 20');

        $this->get('/products/wordpress-hosting/litespeed-20/checkout')->assertOk()
            ->assertSee(__('product.select_plan'))->assertSee('Έτος -')->assertSee('3 έτη -')->assertDontSee('Annually')->assertDontSee('Select a plan');
    }

    public function test_the_product_page_has_the_way_back_and_one_working_order_button(): void
    {
        $product = $this->product($this->hosting, 'LITESPEED 20');

        $this->get('/products/wordpress-hosting/litespeed-20')->assertOk()
            ->assertSeeInOrder(['Wordpress Hosting', 'LITESPEED 20', '$504.00', '/ year'])
            ->assertSee(route('products.checkout', ['category' => $this->hosting, 'product' => $product->slug]))
            ->assertDontSee('ri-shopping-bag', false);
    }

    public function test_an_empty_cart_leads_back_to_the_shop(): void
    {
        $this->product($this->hosting, 'LITESPEED 20');

        $this->get('/cart')->assertOk()->assertSee(__('product.empty_cart'))->assertSee(__('product.continue_shopping'))
            ->assertSee(route('category.show', ['category' => $this->hosting->slug]));
    }
}
