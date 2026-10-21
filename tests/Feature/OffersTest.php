<?php

namespace Tests\Feature;

use App\Admin\Resources\OfferResource;
use App\Admin\Resources\OfferResource\Pages\CreateOffer;
use App\Models\Cart;
use App\Models\ConfigOption;
use App\Models\Coupon;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Offers (ROADMAP 6α, owner 2026-10-21): /offer/{slug} puts its products in the cart and applies its coupon, set up from
 * the admin (nixpal's «Zonecloud Kit» with FREETRIAL).
 */
class OffersTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<Product> */
    private function products(int $count): array
    {
        return array_map(fn () => $this->createProduct()->product, range(1, $count));
    }

    /** @param  list<Product>  $products */
    private function offer(array $products, ?Coupon $coupon = null, array $attributes = []): Offer
    {
        $offer = Offer::query()->create($attributes + ['name' => 'Zonecloud Kit', 'slug' => 'zonecloud-kit', 'active' => true, 'coupon_id' => $coupon?->id]);
        foreach ($products as $i => $product) {
            $offer->items()->create(['product_id' => $product->id, 'quantity' => 1, 'sort' => $i]);
        }

        return $offer;
    }

    private function cart(): ?Cart
    {
        return Cart::query()->latest('id')->with('items')->first();
    }

    public function test_the_link_fills_the_cart_and_applies_the_coupon(): void
    {
        $products = $this->products(3);
        $trial = Coupon::create(['code' => 'FREETRIAL', 'type' => 'override', 'applies_to' => 'all', 'value' => 0, 'recurring' => 2]);
        $trial->products()->sync(array_map(fn (Product $p) => $p->id, $products));
        $this->offer($products, $trial);

        $this->get('/offer/zonecloud-kit')->assertRedirect(route('cart'))->assertSessionHas('offer', fn ($offer) => $offer['coupon'] === 'FREETRIAL'
            && count($offer['added']) === 3 && $offer['error'] === null);

        $this->assertSame(1, Cart::query()->count(), 'one cart for the offer');
        $cart = $this->cart();
        $this->assertSame(3, $cart->items->count());
        $this->assertSame($trial->id, (int) $cart->coupon_id);
    }

    public function test_a_closed_offer_is_not_found(): void
    {
        $this->offer($this->products(1), attributes: ['active' => false]);
        $this->get('/offer/zonecloud-kit')->assertNotFound();

        Offer::query()->update(['active' => true, 'expires_at' => now()->subDay()]);
        $this->get('/offer/zonecloud-kit')->assertNotFound();
        $this->get('/offer/nothing-here')->assertNotFound();
    }

    public function test_a_product_that_needs_the_customers_input_is_left_to_them(): void
    {
        [$simple, $hostname] = $this->products(2);
        $option = ConfigOption::create(['name' => 'Hostname', 'type' => 'text', 'sort' => 0]);
        $option->products()->attach($hostname->id);
        $this->offer([$simple, $hostname]);

        $this->get('/offer/zonecloud-kit')->assertRedirect(route('cart'))->assertSessionHas('offer', fn ($offer) => count($offer['added']) === 1
            && count($offer['to_configure']) === 1 && $offer['to_configure'][0]['name'] === $hostname->name);
        $this->assertSame(1, $this->cart()->items->count());
    }

    public function test_staff_set_one_up(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        [$product] = $this->products(1);
        $this->actingAs(Staff::factory()->withPermissions(['admin.coupons.viewAny'])->create())->get(OfferResource::getUrl())->assertForbidden();
        $staff = Staff::factory()->withPermissions(['admin.offers.viewAny', 'admin.offers.create'])->create();

        $this->livewireAs($staff)->test(CreateOffer::class)
            ->fillForm(['name' => 'Zonecloud Kit', 'slug' => 'zonecloud-kit', 'active' => true, 'items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->call('create')->assertHasNoFormErrors();

        $offer = Offer::query()->sole();
        $this->assertSame([$product->id], $offer->items->pluck('product_id')->map(fn ($id) => (int) $id)->all());
        $this->assertStringEndsWith('/offer/zonecloud-kit', $offer->url());
    }

    public function test_a_coupon_its_rules_refuse_leaves_the_products_and_says_why(): void
    {
        $user = User::factory()->create();
        Service::factory()->create(['client_id' => $user->client()->id, 'status' => 'active']); // not a new customer
        $products = $this->products(1);
        $trial = Coupon::create(['code' => 'FREETRIAL', 'type' => 'override', 'applies_to' => 'all', 'value' => 0, 'customers' => 'new']);
        $trial->products()->sync([$products[0]->id]);
        $this->offer($products, $trial);

        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $user->client()->id]))
            ->get('/offer/zonecloud-kit')->assertRedirect(route('cart'))
            ->assertSessionHas('offer', fn ($offer) => $offer['coupon'] === null && $offer['error'] === __('This promotion is for new customers only.') && count($offer['added']) === 1);
    }
}
