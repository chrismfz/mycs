<?php

namespace Tests\Feature;

use App\Admin\Resources\CategoryResource\Pages\EditCategory;
use App\Admin\Resources\ProductResource\Pages\EditProduct;
use App\Exceptions\DisplayException;
use App\Livewire\Products\Checkout;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Direct links (docs/admin-ux.md, «Απευθείας links»): a hidden product or category is not listed but opens by its link,
 * as WHMCS «Hidden»; a retired one does not. The companies' sites link to these pages (the admin's «Order link»).
 */
class CatalogDirectLinksTest extends TestCase
{
    use RefreshDatabase;

    private Category $shop;

    private Product $listed;

    private Product $hidden;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Category::factory()->create(['slug' => 'cpanel-hosting', 'whmcs_id' => 1]);
        $this->listed = $this->product($this->shop, ['slug' => 'starter', 'whmcs_id' => 10]);
        $this->hidden = $this->product($this->shop, ['slug' => 'custom', 'whmcs_id' => 11, 'hidden' => true]);
        $this->product($this->shop, ['slug' => 'old', 'whmcs_id' => 12, 'hidden' => true, 'retired' => true]);
    }

    /** @param array<string, mixed> $attributes */
    private function product(Category $category, array $attributes): Product
    {
        $product = Product::factory()->create(array_merge(['category_id' => $category->id, 'name' => ucfirst((string) $attributes['slug']) . ' plan'], $attributes));
        foreach ([[1, 'month'], [1, 'year'], [2, 'year']] as [$period, $unit]) {
            $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => "$period $unit",
                'type' => 'recurring', 'billing_period' => $period, 'billing_unit' => $unit]);
            Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);
        }

        return $product;
    }

    private function plan(Product $product, int $period, string $unit): Plan
    {
        return $product->plans()->where('billing_period', $period)->where('billing_unit', $unit)->firstOrFail();
    }

    public function test_a_hidden_product_opens_by_its_link_but_is_not_listed(): void
    {
        $this->get('/products/cpanel-hosting')->assertOk()->assertSee('Starter plan')->assertDontSee('Custom plan')->assertDontSee('Old plan');
        $this->get('/products/cpanel-hosting/custom')->assertOk()->assertSee('Custom plan');
        $this->assertNotSame(404, $this->get('/products/cpanel-hosting/custom/checkout')->getStatusCode());
    }

    public function test_a_retired_product_does_not_open(): void
    {
        $this->get('/products/cpanel-hosting/old')->assertNotFound();
        $this->get('/products/cpanel-hosting/old/checkout')->assertNotFound();
    }

    public function test_a_hidden_category_opens_by_its_link_with_what_can_still_be_ordered(): void
    {
        $custom = Category::factory()->create(['slug' => 'custom-hosting']);
        $this->product($custom, ['slug' => 'bespoke', 'hidden' => true]);
        $this->product($custom, ['slug' => 'gone', 'hidden' => true, 'retired' => true]);
        $retiredOnly = Category::factory()->create(['slug' => 'retired-packages']);
        $this->product($retiredOnly, ['slug' => 'ancient', 'hidden' => true, 'retired' => true]);

        $this->get('/products/custom-hosting')->assertOk()->assertSee('Bespoke plan')->assertDontSee('Gone plan');
        $this->get('/products/retired-packages')->assertNotFound();
    }

    public function test_staff_copy_the_order_link_of_a_category_and_a_product(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]), 'staff');

        Livewire::test(EditCategory::class, ['record' => $this->shop->getRouteKey()])
            ->assertSchemaStateSet(['order_link' => url('/products/cpanel-hosting')]);
        Livewire::test(EditProduct::class, ['record' => $this->hidden->getRouteKey()])
            ->assertSchemaStateSet(['order_link' => url('/products/cpanel-hosting/custom/checkout')]);
    }

    /** A hidden product that sold out opens and says so, as WHMCS: out of stock is not retired (ADV-1 of #216). */
    public function test_a_hidden_product_out_of_stock_is_not_retired(): void
    {
        $this->product($this->shop, ['slug' => 'sold-out', 'hidden' => true, 'stock' => 0]);

        $this->get('/products/cpanel-hosting/sold-out')->assertOk();
    }

    /** Retired is refused when the order is placed and as a new package, whatever its stock (ADV-1 of #216). */
    public function test_a_retired_product_is_not_ordered_nor_an_upgrade(): void
    {
        $retired = Product::query()->where('slug', 'old')->sole();
        $retired->forceFill(['stock' => 5])->save(); // given back by cancellations
        $client = User::factory()->create()->client();

        try {
            (new PlaceOrderService)->place($client, 'USD', [new OrderLine($retired, $retired->plans()->first(), price: '10.00')]);
            $this->fail('a retired product was ordered');
        } catch (DisplayException $e) {
            $this->assertSame(__('product.retired', ['product' => $retired->name]), $e->getMessage());
        }
        $this->assertSame(0, Service::query()->count());

        DB::table('product_upgrades')->insert([['product_id' => $this->listed->id, 'upgrade_id' => $retired->id], ['product_id' => $this->listed->id, 'upgrade_id' => $this->hidden->id]]);
        $service = Service::factory()->create(['client_id' => $client->id, 'product_id' => $this->listed->id, 'plan_id' => $this->plan($this->listed, 1, 'month')->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth()]);
        $this->assertSame([$this->hidden->id], $service->productUpgrades()->pluck('id')->values()->all());
    }

    /** The «Order link» with ?plan= opens the checkout on that plan, its billing cycle chosen (docs/admin-ux.md). */
    public function test_the_order_link_with_a_plan_opens_on_that_plan(): void
    {
        config(['settings.default_currency' => 'USD']);
        $biennial = $this->plan($this->hidden, 2, 'year');

        Livewire::withQueryParams(['plan' => $biennial->id])
            ->test(Checkout::class, ['category' => $this->shop, 'product' => 'custom'])
            ->assertSet('plan_id', $biennial->id);
    }
}
