<?php

namespace Tests\Feature;

use App\Classes\Cart;
use App\Classes\Price;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The guest's pages, more polish (2026-10-20): the home's cycles, Greek everywhere, a quieter cart, shorter cards. */
class GuestUiPolishTest extends TestCase
{
    use RefreshDatabase;

    private Category $hosting;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']);
        $this->hosting = Category::factory()->create(['name' => 'Wordpress Hosting', 'slug' => 'wordpress-hosting', 'show_in_menu' => true]);
    }

    protected function product(string $description = '<ul><li>1</li></ul>'): Product
    {
        $product = Product::factory()->create(['category_id' => $this->hosting->id, 'name' => 'LITESPEED 10', 'slug' => 'litespeed-10', 'description' => $description, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Annually', 'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'year']);
        \App\Models\Price::factory()->create(['plan_id' => $plan->id, 'price' => 192, 'currency_code' => 'USD']);

        return $product;
    }

    public function test_the_home_page_reads_the_cycle_as_the_shop_does(): void
    {
        $this->product();
        app()->setLocale('el');

        $this->assertSame('/ έτος', $this->hosting->fresh()->startingPrice()['cycle'], 'not the plan\'s WHMCS name «Annually»');
    }

    public function test_the_shops_last_english_words_read_in_greek(): void
    {
        app()->setLocale('el');

        $this->assertSame('Δωρεάν', (new Price(free: true))->formatted->price);
        $this->assertSame('Δεν διατίθεται στο νόμισμά σας', (new Price(['price' => 1, 'currency' => null]))->formatted->price);
        $this->assertSame('Το καλάθι χωρά έως 15 προϊόντα.', __('product.cart_full', ['count' => 15]));
    }

    public function test_a_long_feature_list_shows_its_first_six_and_says_how_many_more(): void
    {
        $this->product('<ul>' . implode('', array_map(fn ($i) => "<li>Feature {$i}</li>", range(1, 9))) . '</ul>');

        $this->get('/products/wordpress-hosting')->assertOk()->assertSee('Feature 9')->assertSee(__('product.more_features', ['count' => 3]))
            ->assertSee('[&amp;_li:nth-of-type(n+7)]:hidden', false);
    }

    public function test_a_short_list_has_nothing_to_unfold(): void
    {
        $this->product();

        $this->get('/products/wordpress-hosting')->assertOk()->assertDontSee(__('product.more_features', ['count' => 0]))->assertDontSee('nth-of-type(n+7)', false);
    }

    public function test_the_cart_keeps_the_coupon_behind_a_link(): void
    {
        $this->product();
        Cart::add(Product::query()->sole(), Plan::query()->sole(), [], []);

        Livewire::withCookie('cart', (string) \App\Models\Cart::query()->latest('id')->value('ulid'))->test(\App\Livewire\Cart::class)
            ->assertSee(__('product.have_coupon'))->assertSee(__('product.remove'))->assertDontSee('bg-red-700', false);
    }
}
