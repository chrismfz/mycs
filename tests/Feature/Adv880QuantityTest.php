<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Adversarial review of #880 (Qty on the order page of a separated product). */
class Adv880QuantityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']);
    }

    private function product(string $quantity = 'separated', array $attrs = []): Product
    {
        $category = Category::query()->firstOrCreate(['slug' => 'licenses'], ['name' => 'Licenses']);
        $product = Product::factory()->create(['name' => 'Agent', 'slug' => 'agent', 'category_id' => $category->id, 'allow_quantity' => $quantity,
            'autosetup' => Product::AUTOSETUP_NEVER, 'server_id' => null] + $attrs);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 1.80, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function page(Product $product, array $params = []): mixed
    {
        return Livewire::test('products.checkout', ['category' => $product->category, 'product' => $product->slug] + $params);
    }

    private function lines(): int
    {
        return Cart::query()->count() === 0 ? 0 : (int) Cart::query()->get()->sum(fn ($c) => $c->items()->count());
    }

    /** @return array<string, array{mixed}> */
    public static function tampered(): array
    {
        return [
            'negative' => [-3],
            'huge' => [PHP_INT_MAX],
            'numeric string' => ['3'],
        ];
    }

    #[DataProvider('tampered')]
    public function test_a_tampered_quantity_never_adds_more_than_allowed(mixed $value): void
    {
        $product = $this->product();
        try {
            $this->page($product)->set('quantity', $value)->call('checkout');
        } catch (\Throwable) {
            // a refusal by exception is still a refusal
        }
        $this->assertLessThanOrEqual(\App\Classes\Cart::DEFAULT_MAX_ITEMS, $this->lines());
        $this->assertContains($this->lines(), [0, 3], 'either refused or exactly 3 (the numeric string)');
    }

    /**
     * The field is wire:model.live: a customer who clears it to type another number sends ''. Not a defect: the field
     * falls back to 1 and no server error (recorded so a later change keeps it so).
     */
    public function test_clearing_the_quantity_field_is_no_server_error(): void
    {
        $product = $this->product();

        $this->page($product)->set('quantity', '')->assertOk()->call('checkout')->assertHasNoErrors();
        $this->assertSame(1, $this->lines());
    }

    /** Tampering only (a number field never sends it): Livewire refuses it (419) and nothing is added. */
    public function test_a_non_numeric_quantity_adds_nothing(): void
    {
        $product = $this->product();
        try {
            $this->page($product)->set('quantity', 'abc')->call('checkout');
        } catch (\Throwable) {
        }
        $this->assertSame(0, $this->lines());
    }

    /** Tampering only (a number field never sends it): Livewire refuses it (419) and nothing is added. */
    public function test_an_array_quantity_adds_nothing(): void
    {
        $product = $this->product();
        try {
            $this->page($product)->set('quantity', [5])->call('checkout');
        } catch (\Throwable) {
        }
        $this->assertSame(0, $this->lines());
    }

    public function test_a_nearly_full_cart_takes_all_or_none(): void
    {
        $product = $this->product();
        $page = $this->page($product)->set('quantity', 13)->call('checkout')->assertHasNoErrors();
        $cart = Cart::query()->sole();
        Once::flush();

        Livewire::withCookie('cart', $cart->ulid)->test('products.checkout', ['category' => $product->category, 'product' => $product->slug])
            ->set('quantity', 5)->call('checkout')->assertHasErrors(['quantity']);
        $this->assertSame(13, $cart->items()->count());
    }

    /** The cart filled in another tab after the page was drawn: all or none at the limit. */
    public function test_the_cart_filled_meanwhile_leaves_no_partial_lines(): void
    {
        $product = $this->product();
        $this->page($product)->set('quantity', 10)->call('checkout');
        $cart = Cart::query()->sole();
        Once::flush();
        $page = Livewire::withCookie('cart', $cart->ulid)->test('products.checkout', ['category' => $product->category, 'product' => $product->slug])
            ->set('quantity', 5);
        // Another tab adds 3 more.
        $first = $cart->items()->first();
        for ($i = 0; $i < 3; $i++) {
            $cart->items()->create($first->only(['product_id', 'plan_id', 'config_options', 'checkout_config', 'quantity']));
        }
        Once::flush();
        try {
            $page->call('checkout');
        } catch (\Throwable) {
        }
        $this->assertSame(13, $cart->items()->count(), 'none of the 5 added');
    }

    public function test_the_locked_edit_key_cannot_be_tampered(): void
    {
        $product = $this->product();
        $this->expectException(\Throwable::class);
        $this->page($product)->set('cartProductKey', 999);
    }

    /** Switched to «disabled» between page load and checkout: one line at most, and the customer is told why. */
    public function test_a_product_switched_to_disabled_meanwhile_adds_one_line_at_most(): void
    {
        $product = $this->product();
        $page = $this->page($product)->set('quantity', 4);
        $product->update(['allow_quantity' => 'disabled']);
        try {
            $page->call('checkout');
        } catch (\Throwable) {
        }
        $this->assertLessThanOrEqual(1, $this->lines());
    }

    /** The error of a quantity refused because the product is no longer «separated» must be visible: the field is gone. */
    public function test_a_quantity_refused_after_the_switch_is_shown_to_the_customer(): void
    {
        $product = $this->product();
        $page = $this->page($product)->set('quantity', 4);
        $product->update(['allow_quantity' => 'disabled']);
        // Owner's call (review of #880): the field is gone, so the order is of one, never an error the customer cannot see.
        $page->call('checkout')->assertHasNoErrors();
        $this->assertSame(1, Cart::query()->sole()->items()->count());
    }

    /** The rate limit: repeated checkouts of 15 never pass the cart's 15 lines. */
    public function test_repeated_checkouts_never_pass_the_cart_cap(): void
    {
        $product = $this->product();
        $this->page($product)->set('quantity', 15)->call('checkout')->assertHasNoErrors();
        $cart = Cart::query()->sole();
        for ($i = 0; $i < 5; $i++) {
            Once::flush();
            try {
                Livewire::withCookie('cart', $cart->ulid)->test('products.checkout', ['category' => $product->category, 'product' => $product->slug])
                    ->set('quantity', 15)->call('checkout');
            } catch (\Throwable) {
            }
        }
        $this->assertSame(15, $cart->items()->count());
    }

    /** A setup fee: × N of «Total today» is the price of N lines, each with its fee (as the cart prices them). */
    public function test_times_n_matches_the_cart_total_with_a_setup_fee(): void
    {
        $product = $this->product();
        $product->plans()->first()->prices()->update(['setup_fee' => 5]);
        $page = $this->page($product->fresh())->set('quantity', 3);
        $shown = $page->html();
        $page->call('checkout');
        $cart = Cart::query()->sole();
        Once::flush();
        $sum = $cart->items()->get()->sum(fn ($i) => (float) $i->price->total);
        $this->assertStringContainsString(number_format($sum, 2), $shown);
    }

    /** Per-user limit 1: the page should not offer 15 of what the customer may own one of. */
    public function test_the_quantity_does_not_offer_more_than_the_per_user_limit(): void
    {
        $product = $this->product('separated', ['per_user_limit' => 1]);
        $this->page($product)->set('quantity', 3)->call('checkout')->assertHasErrors(['quantity']);
    }

    /** Stock 2: the page should not offer 15. */
    public function test_the_quantity_does_not_offer_more_than_the_stock(): void
    {
        $product = $this->product('separated', ['stock' => 2]);
        $this->page($product)->set('quantity', 5)->call('checkout')->assertHasErrors(['quantity']);
    }
}
