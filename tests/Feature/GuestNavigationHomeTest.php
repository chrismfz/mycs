<?php

namespace Tests\Feature;

use App\Classes\Navigation;
use App\Models\Category;
use App\Models\DomainTld;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Tests\TestCase;

/** The guest menu and home page (docs/admin-ux.md, «Μενού επισκέπτη»). */
class GuestNavigationHomeTest extends TestCase
{
    use RefreshDatabase;

    private Category $hosting;

    private Category $custom;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']); // empty in the test seed: the menu's currency switch needs one
        $this->hosting = Category::factory()->create(['name' => 'Cloud Hosting', 'slug' => 'cloud-hosting', 'show_in_menu' => true]);
        $this->custom = Category::factory()->create(['name' => 'Custom Hosting', 'slug' => 'custom-hosting', 'show_in_menu' => false]);
        $this->product($this->hosting, 'Small', [[1, 'year', 192], [2, 'year', 336]]);
        $this->product($this->hosting, 'Large', [[1, 'year', 504]]);
        $this->product($this->custom, 'Bespoke', [[1, 'month', 99]]);
    }

    /** @param list<array{int, string, int}> $plans */
    private function product(Category $category, string $name, array $plans): Product
    {
        $product = Product::factory()->create(['category_id' => $category->id, 'name' => $name]);
        foreach ($plans as [$period, $unit, $amount]) {
            $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => "$period $unit",
                'type' => 'recurring', 'billing_period' => $period, 'billing_unit' => $unit]);
            Price::factory()->create(['plan_id' => $plan->id, 'price' => $amount, 'currency_code' => 'USD']);
        }

        return $product;
    }

    /** @return array<string, mixed> name => item */
    private function menu(): array
    {
        return collect(Navigation::getLinks())->keyBy('name')->all();
    }

    public function test_the_shop_menu_has_only_the_categories_in_the_menu(): void
    {
        $shop = $this->menu()[__('navigation.shop')];

        $this->assertSame(['Cloud Hosting'], array_column($shop['children'], 'name'));
        $this->assertArrayNotHasKey(__('navigation.home'), $this->menu(), 'home is the logo');
    }

    public function test_the_domains_menu_and_contact(): void
    {
        $this->assertArrayNotHasKey(__('navigation.domains'), $this->menu(), 'no domains sold: no menu');
        $this->assertArrayHasKey(__('navigation.contact'), $this->menu());

        DomainTld::query()->create(['tld' => 'gr', 'is_active' => true]);
        config(['settings.tickets_disabled' => true]);
        Once::flush(); // Navigation::getLinks() is memoised: read the menu again, through a page
        $this->get('/')->assertOk()
            ->assertSee(route('domain.pricing'), false)
            ->assertSee(route('domain.whois'), false)
            ->assertDontSee(route('tickets.create'), false);
    }

    public function test_the_home_page_lists_the_menu_categories_with_a_from_price(): void
    {
        DomainTld::query()->create(['tld' => 'gr', 'is_active' => true]);

        $this->get('/')->assertOk()
            ->assertSee('Cloud Hosting')
            ->assertDontSee('Custom Hosting')
            ->assertSee('action="' . route('domain.search') . '"', false)
            ->assertSee('192.00') // the lower of the prices the category page shows (Small 192/year, Large 504/year)
            ->assertSee('16.00') // per month
            ->assertSee(__('home.customer_title'));
    }

    public function test_a_category_off_the_menu_still_opens_by_its_link(): void
    {
        $this->get('/products/custom-hosting')->assertOk()->assertSee('Bespoke');
    }

    public function test_a_logged_in_customer_goes_from_home_to_the_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withSession($this->loginUser($user))->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_the_link_back_to_the_company_site(): void
    {
        $this->get('/')->assertDontSee('https://www.example.test', false);

        config(['settings.theme_main_site_url' => 'https://www.example.test/']);

        $this->get('/')->assertSee('href="https://www.example.test/"', false)->assertSee('www.example.test');
    }

    /** Only https, also when set outside the Appearance page (code review of #217). */
    public function test_a_company_site_address_that_is_not_https_is_not_linked(): void
    {
        config(['settings.theme_main_site_url' => 'javascript:alert(1)']);

        $this->get('/')->assertOk()->assertDontSee('javascript:alert(1)', false);
    }

    /** A guest's Contact goes to the login first. */
    public function test_a_guests_contact_goes_to_the_login(): void
    {
        $this->get('/')->assertSee(route('tickets.create'), false);
        $this->get(route('tickets.create'))->assertRedirect(route('login'));
    }
}
