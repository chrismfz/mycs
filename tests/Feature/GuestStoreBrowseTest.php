<?php

namespace Tests\Feature;

use App\Classes\Navigation;
use App\Models\Category;
use App\Models\DomainTld;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shop for a guest as WHMCS's on myip.gr (owner 2026-10-29): the categories as cards on the home page with the
 * domains', «Show all» first in the shop menu, and its page with every category of the menu.
 */
class GuestStoreBrowseTest extends TestCase
{
    use RefreshDatabase;

    private Category $hosting;

    private Category $ssl;

    private Category $hidden;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']); // empty in the test seed: the menu's currency switch needs one
        $this->hosting = Category::factory()->create(['name' => 'Cloud Hosting', 'slug' => 'cloud-hosting', 'sort' => 1,
            'show_in_menu' => true, 'description' => '<p>Fast &amp; <b>safe</b> hosting</p><ul><li>SSD</li><li>Backups</li></ul>']);
        $this->ssl = Category::factory()->create(['name' => 'SSL Certificates', 'slug' => 'ssl', 'sort' => 2, 'show_in_menu' => true]);
        $this->hidden = Category::factory()->create(['name' => 'Custom Hosting', 'slug' => 'custom-hosting', 'show_in_menu' => false]);
        $dv = Category::factory()->create(['name' => 'Domain Validated', 'slug' => 'ssl-dv', 'parent_id' => $this->ssl->id, 'show_in_menu' => true]);
        $this->product($this->hosting, 'Small', 192);
        $this->product($this->hosting, 'Secret plan', 99, ['hidden' => true]);
        $this->product($this->hosting, 'Old plan', 50, ['retired' => true]);
        $this->product($this->hidden, 'Bespoke', 999);
        $this->product($dv, 'Basic SSL', 10);
    }

    /** @param array<string, mixed> $attributes */
    private function product(Category $category, string $name, int $amount, array $attributes = []): Product
    {
        $product = Product::factory()->create(['category_id' => $category->id, 'name' => $name] + $attributes);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => '1 year',
            'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'year']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $amount, 'currency_code' => 'USD']);

        return $product;
    }

    private function client(): static
    {
        $user = User::factory()->create();

        return $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $user->client()->id]));
    }

    public function test_a_guest_sees_a_card_for_each_category_of_the_menu_on_the_home_page(): void
    {
        $this->get('/')->assertOk()
            ->assertSee(__('home.browse_title'))
            ->assertSeeInOrder(['Cloud Hosting', 'Fast &amp; safe hosting SSD Backups', __('home.browse'), 'SSL Certificates', __('home.browse')], false)
            ->assertSee('href="' . route('category.show', ['category' => 'cloud-hosting']) . '"', false)
            ->assertSee('href="' . route('category.show', ['category' => 'ssl']) . '"', false)
            ->assertSee('href="' . route('products.all') . '"', false);
    }

    public function test_hidden_categories_do_not_show_on_the_home_page(): void
    {
        $empty = Category::factory()->create(['name' => 'Only Hidden Things', 'slug' => 'only-hidden', 'show_in_menu' => true]);
        $this->product($empty, 'Invisible', 5, ['hidden' => true]);

        $this->get('/')->assertOk()
            ->assertDontSee('Custom Hosting')
            ->assertDontSee(route('category.show', ['category' => 'custom-hosting']), false)
            ->assertDontSee('Only Hidden Things')
            // A subcategory is reached through its parent, not on the home page.
            ->assertDontSee('Domain Validated');
    }

    public function test_the_domain_cards_follow_the_shops_settings(): void
    {
        $this->get('/')->assertOk()->assertDontSee(__('home.domain_register_title'))->assertDontSee(__('home.domain_transfer_title'));

        DomainTld::query()->create(['tld' => 'gr', 'is_active' => true]);
        $this->get('/')->assertOk()
            ->assertSee(__('home.domain_register_title'))->assertSee(__('home.domain_transfer_title'))
            ->assertSee('href="' . route('domain.transfer') . '"', false);

        $this->storeSettings(['domain_allow_transfer' => false]);
        $this->get('/')->assertOk()
            ->assertSee(__('home.domain_register_title'))
            ->assertDontSee(__('home.domain_transfer_title'));
    }

    public function test_show_all_is_first_in_the_shop_menu_with_a_line_under_it(): void
    {
        $shop = collect(Navigation::getLinks())->firstWhere('name', __('navigation.shop'));

        $this->assertSame([__('navigation.show_all'), 'Cloud Hosting', 'SSL Certificates'], array_column($shop['children'], 'name'));
        $this->assertSame(route('products.all'), $shop['children'][0]['url']);
        $this->assertTrue($shop['children'][0]['separator']);

        // In the page's menu, before the categories.
        $this->get('/products/cloud-hosting')->assertOk()
            ->assertSeeInOrder([__('navigation.show_all'), 'Cloud Hosting', 'SSL Certificates']);
    }

    public function test_a_logged_in_customer_has_show_all_first_in_new_order(): void
    {
        $this->client()->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder([__('navigation.new_order'), route('products.all'), route('category.show', ['category' => 'cloud-hosting'])], false);
    }

    public function test_the_show_all_page_lists_every_category_of_the_menu_for_a_guest(): void
    {
        $this->get(route('products.all'))->assertOk()
            ->assertSee(__('product.all_title'))
            // From the sections, not the menu above them.
            ->assertSeeInOrder(['id="category-' . $this->hosting->id . '"', 'Small', 'id="category-' . $this->ssl->id . '"', 'Domain Validated'], false)
            ->assertSee(route('products.checkout', ['category' => 'cloud-hosting', 'product' => Product::query()->where('name', 'Small')->value('slug')]), false)
            ->assertSee(route('category.show', ['category' => 'ssl-dv']), false)
            ->assertDontSee('Secret plan')
            ->assertDontSee('Old plan')
            ->assertDontSee('Custom Hosting')
            ->assertDontSee('Bespoke');
    }

    public function test_the_show_all_page_for_a_logged_in_customer(): void
    {
        $this->client()->get(route('products.all'))->assertOk()
            ->assertSee('Cloud Hosting')->assertSee('Small')->assertDontSee('Bespoke');
    }

    /** A category with nothing listed under it, even one the menu still shows through an empty subcategory, is left out. */
    public function test_the_show_all_page_of_an_empty_shop(): void
    {
        Product::query()->update(['hidden' => true]);

        $this->get(route('products.all'))->assertOk()->assertSee(__('product.all_empty'))
            ->assertDontSee('id="category-' . $this->ssl->id . '"', false);
    }
}
