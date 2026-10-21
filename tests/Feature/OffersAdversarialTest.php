<?php

namespace Tests\Feature;

use App\Exceptions\CouponUnusableException;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Offer;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial review of #285 (offers): written without the author's context. Tests marked FINDING fail on the PR as it
 * is; the others are guards that pass and stay.
 */
class OffersAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $attributes = []): Product
    {
        return $this->createProduct($attributes)->product;
    }

    /** @param  list<Product|array{0: Product, 1: int|null, 2: int}>  $items  a product, or [product, plan_id, quantity] */
    private function offer(array $items, ?Coupon $coupon = null, array $attributes = []): Offer
    {
        $offer = Offer::query()->create($attributes + ['name' => 'Kit', 'slug' => 'kit', 'active' => true, 'coupon_id' => $coupon?->id]);
        foreach ($items as $i => $item) {
            [$product, $planId, $quantity] = $item instanceof Product ? [$item, null, 1] : $item;
            $offer->items()->create(['product_id' => $product->id, 'plan_id' => $planId, 'quantity' => $quantity, 'sort' => $i]);
        }

        return $offer;
    }

    private function trial(array $products, array $attributes = []): Coupon
    {
        $coupon = Coupon::create($attributes + ['code' => 'FREETRIAL', 'type' => 'override', 'applies_to' => 'all', 'value' => 0, 'recurring' => 1]);
        $coupon->products()->sync(array_map(fn (Product $p) => $p->id, $products));

        return $coupon;
    }

    private function cart(): ?Cart
    {
        return Cart::query()->latest('id')->with('items')->first();
    }

    private function asCustomer(User $user): static
    {
        return $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $user->client()->id]));
    }

    // ---------------------------------------------------------------- findings (fail on the PR)

    /**
     * FINDING ADV-1: the link adds its products again on every visit (the cart cookie is kept, createCart() returns the
     * same cart). A customer who clicks the site's button twice has two kits in the cart, billed twice.
     */
    public function test_adv1_visiting_the_link_twice_does_not_double_the_cart(): void
    {
        $products = [$this->product(), $this->product(), $this->product()];
        $this->offer($products, $this->trial($products));

        $this->get('/offer/kit')->assertRedirect(route('cart'));
        $cart = $this->cart();
        $this->assertSame(3, $cart->items->count());

        $this->withCookie('cart', $cart->ulid)->get('/offer/kit')->assertRedirect(route('cart'));

        $this->assertSame(1, Cart::query()->count());
        $this->assertSame(3, $this->cart()->items->count(), 'the offer is in the cart once, whatever the visits');
    }

    /**
     * FINDING ADV-2 (money): a product sold one per order (allow_quantity = disabled; the product page refuses a second
     * copy) is put in the cart again by the offer link, each copy at the coupon's price. Placed as one order, the
     * once-per-client trial is given twice: refusalFor() only sees services made before this order.
     */
    public function test_adv2_a_one_per_order_product_is_not_added_twice_at_the_trial_price(): void
    {
        $controller = $this->product(['allow_quantity' => 'disabled']);
        $trial = $this->trial([$controller], ['once_per_client' => true]);
        $this->offer([$controller], $trial);
        $user = User::factory()->create();

        $this->asCustomer($user)->get('/offer/kit')->assertRedirect(route('cart'));
        $cart = $this->cart();
        $this->asCustomer($user)->withCookie('cart', $cart->ulid)->get('/offer/kit')->assertRedirect(route('cart'));

        $cart = $this->cart()->load('items.plan.prices', 'items.product', 'coupon');
        $units = (int) $cart->items->where('product_id', $controller->id)->sum('quantity');

        if ($units > 1) {
            // What checkout would do with this cart: both copies at the trial price, once-per-client notwithstanding.
            $lines = $cart->items->map(fn ($item) => new OrderLine($item->product, $item->plan, (int) $item->quantity, $item->config_options ?? [], $item->checkout_config ?? []))->values()->all();
            (new PlaceOrderService)->place($user->client(), $cart->currency_code, $lines, $cart->coupon);
            $this->assertSame(1, Service::query()->where('coupon_id', $trial->id)->count(), 'the once-per-client trial was given more than once in one order');
        }
        $this->assertSame(1, $units, 'a product sold one per order is in the cart once');
    }

    /**
     * FINDING ADV-3 (low, staff input): an offer item's quantity is taken as is, also for a product whose quantity cannot
     * be chosen (allow_quantity = disabled). Neither the admin form nor OfferCart caps it.
     */
    public function test_adv3_an_offer_quantity_is_capped_for_a_one_per_order_product(): void
    {
        $product = $this->product(['allow_quantity' => 'disabled']);
        $this->offer([[$product, null, 5]], $this->trial([$product]));

        $this->get('/offer/kit')->assertRedirect(route('cart'));

        $this->assertSame(1, (int) $this->cart()->items->sole()->quantity);
    }

    /**
     * FINDING ADV-4 (low): a cart that cannot take the offer (full, or the add rate limit hit by a few visits) is not
     * handled: Cart::add()'s DisplayException leaves OfferCart half way, uncaught, and the customer gets an error page
     * with some of the products in the cart instead of the cart and why.
     */
    public function test_adv4_an_offer_bigger_than_the_add_limit_still_lands_on_the_cart(): void
    {
        $products = array_map(fn () => $this->product(), range(1, 11)); // Cart::add allows 10 a minute
        $this->offer($products);

        $this->get('/offer/kit')->assertRedirect(route('cart'));
    }

    /**
     * FINDING ADV-5 (low, money for the customer): a cart with the customer's own coupon loses it when the offer's coupon
     * validates but discounts nothing there (here: for yearly plans, the offer's product monthly): Cart::applyCoupon()
     * clears the cart's coupon on that path, so the discount the customer had is gone silently.
     */
    public function test_adv5_the_offer_does_not_drop_a_coupon_the_cart_already_had(): void
    {
        $mine = $this->product();
        $offered = $this->product();
        $save10 = Coupon::create(['code' => 'SAVE10', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 10]);
        $save10->products()->sync([$mine->id]);
        $yearly = $this->trial([$offered], ['billing_cycles' => ['year:1']]);
        $this->offer([$offered], $yearly);

        $cart = Cart::query()->create(['currency_code' => 'USD', 'coupon_id' => $save10->id]);
        $cart->items()->create(['product_id' => $mine->id, 'plan_id' => $mine->plans()->first()->id, 'config_options' => [], 'checkout_config' => [], 'quantity' => 1]);

        $this->withCookie('cart', $cart->ulid)->get('/offer/kit')->assertRedirect(route('cart'));

        $this->assertSame($save10->id, (int) $cart->fresh()->coupon_id, 'the customer\'s own coupon is kept');
    }

    // ---------------------------------------------------------------- guards (pass on the PR)

    /** GUARD: a retired product, one out of stock, or one without a price in the currency is left out. */
    public function test_guard_retired_out_of_stock_and_unpriced_products_are_left_out(): void
    {
        $ok = $this->product();
        $retired = $this->product(['retired' => true]);
        $empty = $this->product(['stock' => 0]);
        $unpriced = $this->product();
        Price::query()->whereIn('plan_id', $unpriced->plans()->pluck('id'))->update(['currency_code' => 'EUR']);
        $this->offer([$ok, $retired, $empty, $unpriced]);

        $this->get('/offer/kit')->assertRedirect(route('cart'));

        $this->assertSame([$ok->id], $this->cart()->items->pluck('product_id')->map(fn ($id) => (int) $id)->all());
    }

    /** GUARD: a plan of another product set on an item is not used; the product's own first plan is. */
    public function test_guard_a_plan_of_another_product_is_not_used(): void
    {
        $product = $this->product();
        $other = $this->createProduct();
        Price::query()->where('plan_id', $other->plan->id)->update(['price' => 0.01]);
        $this->offer([[$product, $other->plan->id, 1]]);

        $this->get('/offer/kit')->assertRedirect(route('cart'));

        $this->assertSame((int) $product->plans()->first()->id, (int) $this->cart()->items->sole()->plan_id);
    }

    /** GUARD: an offer not started yet is not found. */
    public function test_guard_an_offer_not_started_is_not_found(): void
    {
        $this->offer([$this->product()], attributes: ['starts_at' => now()->addDay()]);

        $this->get('/offer/kit')->assertNotFound();
        $this->assertSame(0, Cart::query()->count());
    }

    /** GUARD: an expired coupon, or one at its max uses, is not applied by the offer. */
    public function test_guard_an_expired_or_used_up_coupon_is_not_applied(): void
    {
        $product = $this->product();
        $trial = $this->trial([$product], ['expires_at' => now()->subMinute()]);
        $this->offer([$product], $trial);

        $this->get('/offer/kit')->assertSessionHas('offer', fn ($o) => $o['coupon'] === null && $o['error'] !== null);
        $this->assertNull($this->cart()->coupon_id);

        $trial->update(['expires_at' => null, 'max_uses' => 1]);
        Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'coupon_id' => $trial->id, 'product_id' => $product->id]);
        Cart::query()->delete();
        $this->get('/offer/kit')->assertSessionHas('offer', fn ($o) => $o['coupon'] === null && $o['error'] !== null);
        $this->assertNull($this->cart()->coupon_id);
    }

    /** GUARD: a deleted coupon leaves the offer without one (nullOnDelete); the products still go in. */
    public function test_guard_a_deleted_coupon_leaves_the_products_without_a_discount(): void
    {
        $product = $this->product();
        $trial = $this->trial([$product]);
        $this->offer([$product], $trial);
        $trial->delete();

        $this->get('/offer/kit')->assertRedirect(route('cart'))->assertSessionHas('offer', fn ($o) => $o['coupon'] === null && count($o['added']) === 1);
        $this->assertNull($this->cart()->coupon_id);
    }

    /** GUARD: once per client is refused by the link to a customer who used it, and again at checkout for a guest. */
    public function test_guard_once_per_client_is_enforced_for_a_customer_who_used_it(): void
    {
        $product = $this->product();
        $trial = $this->trial([$product], ['once_per_client' => true]);
        $this->offer([$product], $trial);
        $user = User::factory()->create();
        Service::factory()->create(['client_id' => $user->client()->id, 'coupon_id' => $trial->id, 'product_id' => $product->id]);

        $this->asCustomer($user)->get('/offer/kit')->assertSessionHas('offer', fn ($o) => $o['coupon'] === null && $o['error'] === __('You have already used this promotion.'));
        $this->assertNull($this->cart()->coupon_id);

        // A guest gets it in the cart; the order refuses it.
        $cart = $this->cart();
        $cart->update(['coupon_id' => $trial->id]);
        $this->expectException(CouponUnusableException::class);
        (new PlaceOrderService)->place($user->client(), 'USD', [new OrderLine($product->fresh(), Plan::query()->where('priceable_id', $product->id)->firstOrFail()->load('prices'), 1)], $trial);
    }
}
