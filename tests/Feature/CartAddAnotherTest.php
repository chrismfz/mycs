<?php

namespace Tests\Feature;

use App\Classes\Cart as CartClass;
use App\Livewire\Cart as CartPage;
use App\Models\Cart;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner 2026-11-16 (Ilias: «I must press Order one by one»): a product sold one service each («Separated») gets «Add
 * another» in the cart, a line of its own; ordering them makes as many services and invoice lines.
 */
class CartAddAnotherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['settings.default_currency' => 'USD', 'settings.tos' => false, 'settings.mail_must_verify' => false, 'settings.captcha' => 'disabled']);
    }

    private function product(string $quantity): Product
    {
        $product = Product::factory()->create(['name' => 'Agent', 'allow_quantity' => $quantity, 'autosetup' => Product::AUTOSETUP_NEVER, 'server_id' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 1.80, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function cart(Product $product, array $checkoutConfig = []): Cart
    {
        $cart = Cart::create(['currency_code' => 'USD']);
        $cart->items()->create(['product_id' => $product->id, 'plan_id' => $product->plans()->firstOrFail()->id,
            'config_options' => [], 'checkout_config' => $checkoutConfig, 'quantity' => 1]);
        Once::flush();

        return $cart;
    }

    public function test_a_separated_product_gets_two_more_lines_and_the_order_makes_three_services(): void
    {
        $user = User::factory()->create();
        $cart = $this->cart($this->product('separated'));
        $item = $cart->items()->sole();

        $page = Livewire::withCookie('cart', $cart->ulid)->actingAs($user)->test(CartPage::class)
            ->assertSee(__('product.add_another'))
            ->call('addAnother', $item->id)->call('addAnother', $item->id);
        $this->assertSame(3, $cart->items()->count());
        $this->assertSame([1, 1, 1], $cart->items()->pluck('quantity')->map(fn ($q) => (int) $q)->all());

        app(CurrentClient::class)->forget();
        $page->call('checkout')->assertHasNoErrors();

        $this->assertSame(3, Service::query()->count(), 'one service each');
        $this->assertSame(3, Invoice::query()->sole()->items()->count());
        $this->assertSame('5.40', number_format((float) Invoice::query()->sole()->items()->sum('price'), 2));
    }

    public function test_not_for_a_combined_or_single_product_nor_one_with_its_own_details(): void
    {
        foreach ([[$this->product('combined'), []], [$this->product('disabled'), []], [$this->product('separated'), ['hostname' => 'a.example']]] as [$product, $config]) {
            $cart = $this->cart($product, $config);
            $item = $cart->items()->sole();

            Livewire::withCookie('cart', $cart->ulid)->test(CartPage::class)
                ->assertDontSee(__('product.add_another'))
                ->call('addAnother', $item->id);

            $this->assertSame(1, $cart->items()->count(), $product->allow_quantity);
        }
    }

    public function test_the_cart_size_limit_holds(): void
    {
        $cart = $this->cart($this->product('separated'));
        $item = $cart->items()->sole();
        $page = Livewire::withCookie('cart', $cart->ulid)->test(CartPage::class);

        foreach (range(1, 20) as $i) {
            $page->call('addAnother', $item->id);
        }

        $this->assertSame(15, $cart->items()->count(), 'never past the cart\'s 15 items');
        $this->assertFalse(CartClass::canAddAnother($cart->items()->with('product')->first()->fill(['checkout_config' => ['x' => 1]])));
    }
}
