<?php

namespace Tests\Feature;

use App\Classes\Cart;
use App\Events\Auth\Login;
use App\Models\Cart as CartModel;
use App\Models\User;
use App\Support\AuthCookies;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Once;
use Tests\TestCase;

/**
 * A cart that belongs to an account opens only for that account: its id in another browser's cookie (planted by a
 * sibling domain, review of #346) shows, changes and empties nothing, and a login never takes it into another account.
 */
class CartOwnerTest extends TestCase
{
    use RefreshDatabase;

    private function cartOf(?User $user): CartModel
    {
        return CartModel::query()->create(['client_id' => $user?->client()->id, 'currency_code' => 'USD']);
    }

    private function cookie(CartModel $cart): void
    {
        request()->cookies->set(AuthCookies::cart(), $cart->ulid);
        Once::flush();
    }

    private function as(?User $user): void
    {
        Auth::guard('web')->forgetUser();
        if ($user !== null) {
            Auth::guard('web')->setUser($user);
        }
        app(CurrentClient::class)->forget();
    }

    public function test_the_cookie_is_prefixed_where_the_browser_takes_it(): void
    {
        config(['session.secure' => true, 'session.domain' => null]);
        $this->assertSame('__Host-cart', AuthCookies::cart());
    }

    public function test_an_accounts_cart_opens_only_for_that_account(): void
    {
        $owner = User::factory()->create();
        $cart = $this->cartOf($owner);
        $this->cookie($cart);

        $this->as(null);
        $this->assertFalse(Cart::getOnce()->exists, 'a guest holding its id');
        $this->as(User::factory()->create());
        $this->assertFalse(Cart::getOnce()->exists, 'another account holding its id');
        $this->as($owner);
        $this->assertSame($cart->id, Cart::getOnce()->id);
    }

    public function test_a_cart_of_no_account_is_whoever_holds_its_id(): void
    {
        $cart = $this->cartOf(null);
        $this->cookie($cart);

        $this->as(null);
        $this->assertSame($cart->id, Cart::getOnce()->id);
    }

    public function test_emptying_someone_elses_cart_leaves_it(): void
    {
        $owner = User::factory()->create();
        $cart = $this->cartOf($owner);
        $this->cookie($cart);
        $this->as(User::factory()->create());

        Cart::clear();

        $this->assertTrue(CartModel::query()->whereKey($cart->id)->exists());
    }

    public function test_a_login_never_takes_another_accounts_cart(): void
    {
        $owner = User::factory()->create();
        $cart = $this->cartOf($owner);
        $this->cookie($cart);
        $victim = User::factory()->create();
        $this->as($victim);

        event(new Login($victim));

        $this->assertSame($owner->client()->id, $cart->fresh()->client_id);
        $this->assertTrue(collect(Cookie::getQueuedCookies())->contains(fn ($c) => $c->getName() === AuthCookies::cart() && $c->getValue() === null));
    }

    public function test_a_login_that_drops_another_accounts_cart_brings_back_its_own(): void
    {
        $this->cookie($this->cartOf(User::factory()->create()));
        $user = User::factory()->create();
        $own = $this->cartOf($user);
        $this->as($user);

        event(new Login($user));

        $this->assertTrue(collect(Cookie::getQueuedCookies())->contains(fn ($c) => $c->getName() === AuthCookies::cart() && $c->getValue() === $own->ulid));
    }

    public function test_a_login_takes_a_cart_of_no_account_into_its_own(): void
    {
        $cart = $this->cartOf(null);
        $this->cookie($cart);
        $user = User::factory()->create();
        $this->as($user);

        event(new Login($user));

        $this->assertSame($user->client()->id, $cart->fresh()->client_id);
    }
}
