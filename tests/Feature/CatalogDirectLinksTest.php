<?php

namespace Tests\Feature;

use App\Admin\Resources\CategoryResource\Pages\EditCategory;
use App\Admin\Resources\ProductResource\Pages\EditProduct;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Direct links (docs/admin-ux.md, «Απευθείας links»): a hidden product or category is not listed but opens by its link,
 * as WHMCS «Hidden»; a retired one (hidden, out of stock) does not. The WHMCS links of the companies' sites land on
 * our pages.
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
        $this->product($this->shop, ['slug' => 'old', 'whmcs_id' => 12, 'hidden' => true, 'stock' => 0]); // retired
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
        $this->product($custom, ['slug' => 'gone', 'hidden' => true, 'stock' => 0]);
        $retiredOnly = Category::factory()->create(['slug' => 'retired-packages']);
        $this->product($retiredOnly, ['slug' => 'ancient', 'hidden' => true, 'stock' => 0]);

        $this->get('/products/custom-hosting')->assertOk()->assertSee('Bespoke plan')->assertDontSee('Gone plan');
        $this->get('/products/retired-packages')->assertNotFound();
    }

    public function test_whmcs_product_links_open_its_checkout_with_the_cycle(): void
    {
        $checkout = fn (Product $product, ?Plan $plan = null) => route('products.checkout', array_filter(['category' => $this->shop, 'product' => $product, 'plan' => $plan?->id]));

        $this->get('/cart.php?a=add&pid=10&billingcycle=annually')->assertRedirect($checkout($this->listed, $this->plan($this->listed, 1, 'year')));
        $this->get('/clients/cart.php?a=add&pid=11&billingcycle=Biennially')->assertRedirect($checkout($this->hidden, $this->plan($this->hidden, 2, 'year')));
        $this->get('/cart.php?a=add&pid=10&billingcycle=triennially')->assertRedirect($checkout($this->listed));
        $this->get('/clients/store/cpanel-hosting/custom?billingcycle=monthly')->assertRedirect($checkout($this->hidden, $this->plan($this->hidden, 1, 'month')));
        $this->get('/store/cpanel-hosting/starter')->assertRedirect($checkout($this->listed));
    }

    public function test_whmcs_links_to_a_retired_or_unknown_product_land_on_the_shop(): void
    {
        $this->get('/cart.php?a=add&pid=12')->assertRedirect(route('home'));
        $this->get('/cart.php?a=add&pid=999')->assertRedirect(route('home'));
        $this->get('/store/cpanel-hosting/old')->assertRedirect(route('category.show', ['category' => $this->shop]));
        $this->get('/store/no-such-group')->assertRedirect(route('home'));
    }

    public function test_whmcs_category_domain_and_client_area_links(): void
    {
        $this->get('/cart.php?gid=1')->assertRedirect(route('category.show', ['category' => $this->shop]));
        $this->get('/clients/store/cpanel-hosting/')->assertRedirect(route('category.show', ['category' => $this->shop]));
        $this->get('/clients/cart.php?a=add&domain=register&query=example.gr')->assertRedirect(route('domain.search', ['domain' => 'example.gr']));
        $this->get('/clients/cart.php?a=add&domain=transfer')->assertRedirect(route('domain.search'));
        $this->get('/clients/cart.php')->assertRedirect(route('cart'));
        $this->get('/clients/clientarea.php?action=domains')->assertRedirect(route('domains'));
        $this->get('/clientarea.php')->assertRedirect(route('dashboard'));
        $this->get('/clients/domain/pricing')->assertRedirect('/domain-pricing');
        $this->get('/clients/supporttickets.php')->assertRedirect('/tickets');
        $this->get('/submitticket.php?step=2&deptid=2')->assertRedirect('/tickets/create');
        $this->get('/clients/login')->assertRedirect('/login');
    }

    public function test_staff_copy_the_order_link_of_a_category_and_a_product(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]), 'staff');

        Livewire::test(EditCategory::class, ['record' => $this->shop->getRouteKey()])
            ->assertSchemaStateSet(['order_link' => route('category.show', ['category' => $this->shop])]);
        Livewire::test(EditProduct::class, ['record' => $this->hidden->getRouteKey()])
            ->assertSchemaStateSet(['order_link' => route('products.checkout', ['category' => $this->shop, 'product' => $this->hidden])]);
    }
}
