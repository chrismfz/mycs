<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner 2026-11-16 (Ilias: «no Qty» on the Agent's order page): a product sold one service each asks how many and puts
 * that many lines in the cart; the bulk product's page and cart line say how its IPs are added.
 */
class OrderQuantityAndBulkNoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']);
    }

    private function product(string $quantity, string $slug = 'agent'): Product
    {
        $category = Category::query()->firstOrCreate(['slug' => 'licenses'], ['name' => 'Licenses']);
        $product = Product::factory()->create(['name' => ucfirst($slug), 'slug' => $slug, 'category_id' => $category->id, 'allow_quantity' => $quantity,
            'autosetup' => Product::AUTOSETUP_NEVER, 'server_id' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 1.80, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function page(Product $product, array $params = []): mixed
    {
        return Livewire::test('products.checkout', ['category' => $product->category, 'product' => $product->slug] + $params);
    }

    public function test_three_of_a_separated_product_are_three_lines(): void
    {
        $product = $this->product('separated');

        $this->page($product)->assertSee(__('product.quantity'))->set('quantity', 3)->assertSee('× 3')->call('checkout')->assertHasNoErrors();

        $cart = Cart::query()->sole();
        $this->assertSame(3, $cart->items()->count());
        $this->assertSame([1, 1, 1], $cart->items()->pluck('quantity')->map(fn ($q) => (int) $q)->all());
    }

    public function test_never_more_than_the_cart_holds_nor_below_one(): void
    {
        $product = $this->product('separated');

        $this->page($product)->set('quantity', 16)->call('checkout')->assertHasErrors(['quantity']);
        $this->page($product)->set('quantity', 0)->call('checkout')->assertHasErrors(['quantity']);
        $this->assertSame(0, Cart::query()->count() === 0 ? 0 : Cart::query()->sole()->items()->count());
    }

    public function test_no_quantity_when_editing_a_line(): void
    {
        $separated = $this->product('separated', 'node');
        $this->page($separated)->call('checkout');
        $cart = Cart::query()->sole();
        Once::flush();
        $item = $cart->items()->sole();

        // Editing that line: it alone (nothing to choose here, so the page goes back to the cart with it unchanged).
        Livewire::withCookie('cart', $cart->ulid)->test('products.checkout', ['category' => $separated->category, 'product' => $separated->slug, 'cartProductKey' => $item->id])
            ->assertRedirect(route('cart'));
        $this->assertSame(1, $cart->items()->count(), 'no copies from an edit');
    }

    public function test_the_bulk_product_says_how_its_ips_are_added(): void
    {
        $bulk = $this->product('disabled', 'bulk');
        (new Setting)->forceFill(['key' => 'bulk', 'value' => '1', 'type' => 'boolean', 'settingable_id' => $bulk->id,
            'settingable_type' => $bulk->getMorphClass()])->save();
        $agent = $this->product('separated');

        $this->page($agent)->assertDontSee(__('product.bulk_how'));
        // One plan, nothing to choose: the bulk goes straight to the cart, whose line says it.
        $this->page($bulk->fresh())->assertRedirect(route('cart'));
        $cart = Cart::query()->latest('id')->firstOrFail();
        Once::flush();
        Livewire::withCookie('cart', $cart->ulid)->test(\App\Livewire\Cart::class)->assertSee(__('product.bulk_how'));
    }
}
