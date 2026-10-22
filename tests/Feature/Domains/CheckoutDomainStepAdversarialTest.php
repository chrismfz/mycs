<?php

namespace Tests\Feature\Domains;

use App\Exceptions\DisplayException;
use App\Livewire\Products\Checkout;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Server;
use App\Services\Domains\Lookup\LaravelTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/** Adversarial review of #313: the hosting's domain at checkout. Each test fails on the PR as it is. */
class CheckoutDomainStepAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private Product $hosting;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::flush();
        Http::preventStrayRequests();
        LaravelTransport::$resolve = fn (string $host) => ['93.184.216.34'];
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR', 'settings.domain_billing_enabled' => true]);
        session(['currency' => 'EUR']);
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $com = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'sort' => 1, 'registrar_connection_id' => $op->id, 'epp_code_required' => true]);
        foreach ([['register', 1, '12.00'], ['renew', 1, '12.00'], ['transfer', 1, '10.00']] as [$operation, $years, $price]) {
            DomainTldPrice::query()->create(['domain_tld_id' => $com->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
        }
        // Openprovider: nixpal.com is taken, every other name free.
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/auth/login') => Http::response(['data' => ['token' => 'tok']]),
                str_contains($url, '/domains/check') => Http::response(['data' => ['results' => array_map(fn ($d) => [
                    'domain' => $d['name'] . '.' . $d['extension'],
                    'status' => $d['name'] . '.' . $d['extension'] === 'nixpal.com' ? 'active' : 'free',
                ], $request->data()['domains'])]]),
                default => Http::response([], 404),
            };
        });

        $server = Server::query()->create(['name' => 'WHM', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $this->category = Category::factory()->create(['slug' => 'hosting']);
        $this->hosting = Product::factory()->create(['category_id' => $this->category->id, 'slug' => 'litespeed-20', 'server_id' => $server->id, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $this->hosting->id, 'priceable_type' => Product::class, 'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'year']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 50, 'currency_code' => 'EUR']);
        Plan::factory()->create(['priceable_id' => $this->hosting->id, 'priceable_type' => Product::class, 'type' => 'recurring', 'billing_period' => 2, 'billing_unit' => 'year'])
            ->prices()->create(['price' => 90, 'currency_code' => 'EUR']);
    }

    protected function tearDown(): void
    {
        LaravelTransport::$resolve = null;
        parent::tearDown();
    }

    private function checkout(?Cart $cart = null)
    {
        $params = ['category' => $this->category, 'product' => 'litespeed-20'];

        return $cart === null ? Livewire::test(Checkout::class, $params) : Livewire::withCookie('cart', $cart->ulid)->test(Checkout::class, $params);
    }

    /** A cart with $n other products in it (the cart holds 15). */
    private function cartWith(int $n): Cart
    {
        $cart = Cart::query()->create(['currency_code' => 'EUR']);
        $other = Product::factory()->create(['category_id' => $this->category->id, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $other->id, 'priceable_type' => Product::class, 'type' => 'free']);
        for ($i = 0; $i < $n; $i++) {
            $cart->items()->create(['product_id' => $other->id, 'plan_id' => $plan->id, 'config_options' => [], 'checkout_config' => [], 'quantity' => 1]);
        }

        return $cart;
    }

    /**
     * ADV-1: the domain takes the cart's last place, then the hosting is refused (cart full): a domain registered for a
     * hosting that is not in the cart, and the customer told only that the cart is full.
     */
    public function test_a_full_cart_leaves_no_domain_without_its_hosting(): void
    {
        $cart = $this->cartWith(14);
        $page = $this->checkout($cart)->set('checkoutConfig.domain', 'mybusiness.com')->call('checkDomain')->assertSet('domainAction', 'register');

        try {
            $page->call('checkout');
        } catch (DisplayException) {
            // the cart's refusal of the hosting, as the page shows it
        }

        $this->assertSame(0, CartItem::query()->whereNull('product_id')->count(), 'the domain went in, its hosting did not');
    }

    /**
     * ADV-2: the add limit (10 a minute) is hit twice by one «Add to cart» with a domain: at the 10th the domain goes in
     * and the hosting is refused as «too fast».
     */
    public function test_the_add_limit_leaves_no_domain_without_its_hosting(): void
    {
        $page = $this->checkout()->set('checkoutConfig.domain', 'mybusiness.com')->call('checkDomain')->assertSet('domainAction', 'register');
        $key = 'cart-add:ip:' . request()->ip();
        for ($i = 0; $i < 9; $i++) {
            RateLimiter::hit($key, 60);
        }

        try {
            $page->call('checkout');
        } catch (DisplayException) {
            // too fast, as the page shows it
        }

        $this->assertSame(0, CartItem::query()->whereNull('product_id')->count(), 'the domain went in, its hosting did not');
    }

    /**
     * ADV-3: the check normalises what was typed (www., a scheme, a trailing dot, upper case, an IDN to punycode) and
     * offers «register mybusiness.com», but checkout compares the offer with the raw text: the domain the customer
     * chose is dropped without a word and the hosting alone goes to the cart.
     */
    public function test_a_name_typed_with_www_is_not_silently_dropped(): void
    {
        $this->checkout()->set('checkoutConfig.domain', 'www.mybusiness.com')->call('checkDomain')
            ->assertSee(__('domains.checkout.free', ['domain' => 'mybusiness.com']))->assertSet('domainAction', 'register')
            ->call('checkout');

        $this->assertTrue(
            CartItem::query()->whereNull('product_id')->exists() || CartItem::query()->count() === 0,
            'register was chosen and shown, yet only the hosting went to the cart, silently',
        );
    }

    /**
     * ADV-4: editing the hosting in the cart (?edit=) to another domain registers the new one and keeps the old one: a
     * domain paid for that no hosting in the cart is for any more.
     */
    public function test_editing_the_hosting_to_another_domain_leaves_the_first_one_behind(): void
    {
        $this->checkout()->set('checkoutConfig.domain', 'first.com')->call('checkDomain')->call('checkout')->assertHasNoErrors();
        $cart = Cart::query()->sole();
        $hostingItem = CartItem::query()->where('product_id', $this->hosting->id)->sole();

        Livewire::withCookie('cart', $cart->ulid)->withQueryParams(['edit' => $hostingItem->id])
            ->test(Checkout::class, ['category' => $this->category, 'product' => 'litespeed-20'])
            ->set('checkoutConfig.domain', 'second.com')->call('checkDomain')->assertSet('domainAction', 'register')->call('checkout')->assertHasNoErrors();

        $this->assertSame(['second.com'], CartItem::query()->whereNull('product_id')->get()->map(fn ($i) => $i->domain['fqdn'])->all());
    }
}
