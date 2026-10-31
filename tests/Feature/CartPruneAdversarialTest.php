<?php

namespace Tests\Feature;

use App\Events\Cart\Deleted as CartDeleted;
use App\Events\Cart\Deleting;
use App\Events\CartItem\Deleted as CartItemDeleted;
use App\Livewire\Cart as CartPage;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CronStat;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Support\AuthCookies;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of the cart pruning (Cart::prune(), cron step `carts_pruned`): what a delete by query, without
 * model events, outside a transaction, can take with it.
 */
class CartPruneAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-11-30 03:00:00');
        config(['settings.tax_enabled' => false, 'settings.default_currency' => 'USD', 'settings.tos' => false,
            'settings.mail_must_verify' => false, 'settings.captcha' => 'disabled']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A cart with one product item, last touched at $touched and its item at $itemTouched (null: stored with no date). */
    private function cart(?string $touched, ?string $itemTouched = 'same', array $attributes = []): Cart
    {
        $cart = Cart::query()->create(['currency_code' => 'USD'] + $attributes);
        $product = $this->createProduct();
        $item = CartItem::query()->create(['cart_id' => $cart->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'config_options' => [], 'checkout_config' => [], 'quantity' => 1]);
        DB::table('carts')->where('id', $cart->id)->update(['updated_at' => $touched]);
        DB::table('cart_items')->where('id', $item->id)->update(['updated_at' => $itemTouched === 'same' ? $touched : $itemTouched]);

        return $cart;
    }

    private function old(): string
    {
        return now()->subDays(Cart::KEEP_DAYS + 1)->toDateTimeString();
    }

    /**
     * ADV-CART-1: the carts are chosen, then their items deleted by cart_id with no second look. An item the customer
     * adds (or changes) between the choice and the delete goes with the cart: the delete does not repeat the condition
     * that chose it (nothing touched since the cutoff). Here the item is added right after the chunk's select ran.
     */
    public function test_bug_an_item_added_after_the_carts_were_chosen_is_deleted_with_them(): void
    {
        $cart = $this->cart($this->old());
        $product = $this->createProduct();
        $added = null;
        DB::listen(function (QueryExecuted $query) use ($cart, $product, &$added) {
            if ($added === null && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'carts')
                && str_contains($query->sql, 'not exists')) {
                $added = 0; // once
                $added = DB::table('cart_items')->insertGetId(['cart_id' => $cart->id, 'product_id' => $product->product->id,
                    'plan_id' => $product->plan->id, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        Cart::prune();

        $this->assertNotNull($added, 'the chunk select ran');
        $this->assertTrue(DB::table('cart_items')->where('id', $added)->exists(),
            'an item added a moment ago is the customer\'s cart in use, not a cart left behind');
        $this->assertTrue(DB::table('carts')->where('id', $cart->id)->exists(), 'nor its cart');
    }

    /** A cart nobody touched for a month, its customer on the cart page meanwhile: the checkout says empty, orders nothing. */
    public function test_holds_a_checkout_of_a_cart_pruned_under_the_page_orders_nothing(): void
    {
        $user = User::factory()->create();
        $user->client()->primaryContact->update(['country' => 'GR']);
        $cart = $this->cart($this->old());
        Once::flush();
        $page = Livewire::withCookie(AuthCookies::cart(), $cart->ulid)->actingAs($user->fresh())->test(CartPage::class);

        $this->assertSame(1, Cart::prune());
        Once::flush();
        $page->call('checkout');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Invoice::query()->count());
    }

    /** A cookie with the ulid of a pruned cart: the cart page opens empty, no error. */
    public function test_holds_a_stale_cookie_opens_an_empty_cart(): void
    {
        $cart = $this->cart($this->old());
        Cart::prune();
        Once::flush();

        Livewire::withCookie(AuthCookies::cart(), $cart->ulid)->test(CartPage::class)->assertOk()->assertSet('total', null);
        $this->assertSame(0, Cart::query()->count(), 'opening the page makes no cart');
    }

    /** The coupon on a pruned cart stays (only the cart's pointer goes); a coupon counts services, not carts. */
    public function test_holds_the_coupon_of_a_pruned_cart_stays(): void
    {
        $coupon = Coupon::query()->create(['code' => 'ADVCART', 'type' => 'percentage', 'value' => 10]);
        $this->cart($this->old(), attributes: ['coupon_id' => $coupon->id]);

        $this->assertSame(1, Cart::prune());

        $this->assertNotNull(Coupon::query()->find($coupon->id));
    }

    /** An item with no date never keeps a month-old cart (NULL >= cutoff is not true): it goes. */
    public function test_holds_an_item_with_no_updated_at_does_not_keep_an_old_cart(): void
    {
        $cart = $this->cart($this->old(), null);

        $this->assertSame(1, Cart::prune());
        $this->assertNull(Cart::query()->find($cart->id));
    }

    /** A cart with no date is never pruned (NULL < cutoff is not true): conservative, Eloquent always sets one. */
    public function test_holds_a_cart_with_no_updated_at_is_kept(): void
    {
        $cart = $this->cart(null, $this->old());

        $this->assertSame(0, Cart::prune());
        $this->assertNotNull(Cart::query()->find($cart->id));
    }

    /** One second before the cutoff goes, the cutoff itself stays (strict <, and >= for the items). */
    public function test_holds_the_cutoff_edges(): void
    {
        $cutoff = now()->subDays(Cart::KEEP_DAYS);
        $at = $this->cart($cutoff->toDateTimeString());
        $before = $this->cart($cutoff->copy()->subSecond()->toDateTimeString());
        $itemAt = $this->cart($cutoff->copy()->subDay()->toDateTimeString(), $cutoff->toDateTimeString());

        $this->assertSame(1, Cart::prune());
        $this->assertNotNull(Cart::query()->find($at->id));
        $this->assertNull(Cart::query()->find($before->id));
        $this->assertNotNull(Cart::query()->find($itemAt->id));
    }

    /** An account's cart is pruned as a guest's; the account itself, and others' carts, untouched. */
    public function test_holds_an_accounts_cart_goes_alone(): void
    {
        $user = User::factory()->create();
        $client = $user->client();
        $old = $this->cart($this->old(), attributes: ['client_id' => $client->id]);
        $fresh = $this->cart(now()->toDateTimeString());

        $this->assertSame(1, Cart::prune());

        $this->assertNull(Cart::query()->find($old->id));
        $this->assertNotNull($client->fresh());
        $this->assertNull($client->fresh()->cart);
        $this->assertNotNull(Cart::query()->find($fresh->id));
    }

    /** More than one chunk (500): every old cart and item goes, the fresh ones among them stay, the count is right. */
    public function test_holds_many_carts_over_several_chunks(): void
    {
        $old = $this->old();
        $fresh = now()->toDateTimeString();
        $product = $this->createProduct();
        $carts = [];
        for ($i = 0; $i < 1203; $i++) {
            $carts[] = ['ulid' => strtolower((string) Str::ulid()), 'currency_code' => 'USD',
                'created_at' => $old, 'updated_at' => $i % 100 === 0 ? $fresh : $old];
        }
        foreach (array_chunk($carts, 200) as $rows) {
            DB::table('carts')->insert($rows);
        }
        $items = DB::table('carts')->pluck('id')->map(fn ($id) => ['cart_id' => $id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'quantity' => 1, 'created_at' => $old,
            'updated_at' => $id % 250 === 0 ? $fresh : $old])->all();
        foreach (array_chunk($items, 200) as $rows) {
            DB::table('cart_items')->insert($rows);
        }
        $keep = DB::table('carts')->where('updated_at', $fresh)->orWhereIn('id', DB::table('cart_items')->where('updated_at', $fresh)->select('cart_id'))->count();

        $this->assertSame(1203 - $keep, Cart::prune());

        $this->assertSame($keep, Cart::query()->count());
        $this->assertSame(0, DB::table('cart_items')->whereNotIn('cart_id', DB::table('carts')->select('id'))->count(), 'no orphan item');
    }

    /** No model events: nothing in the app or its extensions listens to a cart's or an item's deletion. */
    public function test_holds_nothing_listens_to_a_carts_deletion(): void
    {
        $this->assertFalse(Event::hasListeners(CartDeleted::class));
        $this->assertFalse(Event::hasListeners(Deleting::class));
        $this->assertFalse(Event::hasListeners(CartItemDeleted::class));
        $this->assertFalse(Event::hasListeners(\App\Events\CartItem\Deleting::class));
    }

    /** The cron step records how many, under its label in both languages. */
    public function test_holds_the_cron_step_counts_and_is_labelled(): void
    {
        $this->cart($this->old());
        $this->cart($this->old());

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(2, (int) CronStat::query()->where('key', 'carts_pruned')->value('value'));
        foreach (['en', 'el'] as $locale) {
            $this->assertNotSame('admin.cronjob.carts_pruned', __('admin.cronjob.carts_pruned', [], $locale));
        }
    }
}
