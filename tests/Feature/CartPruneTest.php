<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Carts left behind are deleted after a month nobody touched them (sweep 9γ, owner 2026-10-30), with their items: one
 * touched since, the cart or an item of it, is kept.
 */
class CartPruneTest extends TestCase
{
    use RefreshDatabase;

    private function cart(string $touched, ?string $itemTouched = null): Cart
    {
        $cart = Cart::query()->create(['currency_code' => 'USD']);
        $product = $this->createProduct();
        $item = CartItem::query()->create(['cart_id' => $cart->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'quantity' => 1, 'epp_code' => 'secret-epp']);
        DB::table('carts')->where('id', $cart->id)->update(['updated_at' => $touched]);
        DB::table('cart_items')->where('id', $item->id)->update(['updated_at' => $itemTouched ?? $touched]);

        return $cart;
    }

    public function test_a_month_untouched_cart_goes_with_its_items_one_touched_since_stays(): void
    {
        Carbon::setTestNow('2026-11-30 03:00:00');
        $old = $this->cart('2026-10-01 10:00:00');
        $recent = $this->cart('2026-11-20 10:00:00');
        $itemTouched = $this->cart('2026-10-01 10:00:00', '2026-11-25 10:00:00');

        $this->assertSame(1, Cart::prune());

        $this->assertNull(Cart::query()->find($old->id));
        $this->assertSame(0, CartItem::query()->where('cart_id', $old->id)->count(), 'its EPP code with it');
        $this->assertNotNull(Cart::query()->find($recent->id));
        $this->assertNotNull(Cart::query()->find($itemTouched->id), 'an item touched since: kept');
        Carbon::setTestNow();
    }

    public function test_the_daily_cron_prunes_them(): void
    {
        $old = $this->cart(now()->subDays(Cart::KEEP_DAYS + 5)->toDateTimeString());

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertNull(Cart::query()->find($old->id));
    }
}
