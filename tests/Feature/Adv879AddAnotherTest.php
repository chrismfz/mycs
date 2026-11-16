<?php

namespace Tests\Feature;

use App\Livewire\Cart as CartPage;
use App\Models\Cart;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\TestCase;

/** Adversarial review of #879 («Add another» for Separated products). */
class Adv879AddAnotherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['settings.default_currency' => 'USD', 'settings.tos' => false, 'settings.mail_must_verify' => false, 'settings.captcha' => 'disabled']);
    }

    private function product(): Product
    {
        $product = Product::factory()->create(['name' => 'Agent', 'allow_quantity' => 'separated', 'autosetup' => Product::AUTOSETUP_NEVER, 'server_id' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 1.80, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function cart(Product $product, array $attributes = []): Cart
    {
        $cart = Cart::create(['currency_code' => 'USD'] + $attributes);
        $cart->items()->create(['product_id' => $product->id, 'plan_id' => $product->plans()->firstOrFail()->id,
            'config_options' => [], 'checkout_config' => [], 'quantity' => 1]);
        Once::flush();

        return $cart;
    }

    /**
     * ADV-879-1: the page drawn after «Add another» is the cart read before it (Cart::get() memoised; add() reloads
     * another instance): the new line is not shown and the total stays the old one until the next request.
     */
    public function test_the_page_shows_the_new_line_and_total_right_after_add_another(): void
    {
        $cart = $this->cart($this->product());
        $item = $cart->items()->sole();

        $page = Livewire::withCookie('cart', $cart->ulid)->test(CartPage::class)
            ->call('addAnother', $item->id);

        $this->assertSame(2, $cart->items()->count());
        $this->assertSame('3.60', number_format((float) $page->get('total')->total, 2), 'the total counts the new line');
        $this->assertSame(2, substr_count($page->html(), __('product.add_another')), 'both lines are drawn');
    }

    /** Another visitor's cart item, by id: never copied into mine (control). */
    public function test_an_item_of_another_cart_is_not_copied(): void
    {
        $product = $this->product();
        $theirs = $this->cart($product);
        $mine = $this->cart($product);

        Livewire::withCookie('cart', $mine->ulid)->test(CartPage::class)
            ->call('addAnother', $theirs->items()->sole()->id);

        $this->assertSame(1, $mine->items()->count());
        $this->assertSame(1, $theirs->items()->count());
    }

    /** An account's cart in another login's cookie: nothing copied (control). */
    public function test_an_accounts_cart_is_not_reached_by_another_login(): void
    {
        $owner = User::factory()->create();
        $cart = $this->cart($this->product(), ['client_id' => $owner->id + UserFactory::CLIENT_ID_OFFSET]);

        Livewire::withCookie('cart', $cart->ulid)->actingAs(User::factory()->create())->test(CartPage::class)
            ->call('addAnother', $cart->items()->sole()->id);

        $this->assertSame(1, $cart->items()->count());
    }
}
