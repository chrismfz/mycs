<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Review of #273: the «+N more» of a long feature list counts every <li> of the description, but the CSS that hides
 * them (`li:nth-of-type(n+7)`) counts per list. Two lists of five, or a list with a nested one, promise «+4 more»
 * while nothing (or fewer) is hidden.
 */
class GuestUiPolishReviewTest extends TestCase
{
    use RefreshDatabase;

    private Category $hosting;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']);
        $this->hosting = Category::factory()->create(['name' => 'Wordpress Hosting', 'slug' => 'wordpress-hosting', 'show_in_menu' => true]);
    }

    private function product(string $description): void
    {
        $product = Product::factory()->create(['category_id' => $this->hosting->id, 'name' => 'LITESPEED 10', 'slug' => 'litespeed-10', 'description' => $description, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Annually', 'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'year']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 192, 'currency_code' => 'USD']);
    }

    private static function items(string $prefix, int $n): string
    {
        return implode('', array_map(fn ($i) => "<li>{$prefix} {$i}</li>", range(1, $n)));
    }

    public function test_two_short_lists_promise_no_hidden_features(): void
    {
        // Each list has 5 items: `li:nth-of-type(n+7)` hides none of them.
        $this->product('<p>Features</p><ul>' . self::items('Feature', 5) . '</ul><p>Includes</p><ul>' . self::items('Extra', 5) . '</ul>');

        $this->get('/products/wordpress-hosting')->assertOk()
            ->assertDontSee(__('product.more_features', ['count' => 4]), false);
    }

    public function test_a_nested_list_counts_what_is_actually_hidden(): void
    {
        // 4 top-level items, the first with 4 sub-items: nothing is the 7th of its type in its list.
        $this->product('<ul><li>Disk<ul>' . self::items('Sub', 4) . '</ul></li>' . self::items('Feature', 3) . '</ul>');

        $this->get('/products/wordpress-hosting')->assertOk()
            ->assertDontSee(__('product.more_features', ['count' => 2]), false);
    }
}
