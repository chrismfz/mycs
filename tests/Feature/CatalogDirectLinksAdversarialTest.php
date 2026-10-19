<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial review of #216 (direct links): a retired product must stay unorderable, a hidden one unlisted, and the
 * WHMCS cycle must preselect a plan the visitor can buy. After the fix a retired product carries its own flag (as the
 * importer now brings it); the scenarios are unchanged.
 */
class CatalogDirectLinksAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Category $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Category::factory()->create(['slug' => 'cpanel-hosting', 'whmcs_id' => 1]);
    }

    /** @param array<string, mixed> $attributes */
    private function product(Category $category, array $attributes): Product
    {
        $product = Product::factory()->create(array_merge(['category_id' => $category->id, 'name' => ucfirst((string) $attributes['slug']) . ' plan'], $attributes));
        foreach ([[1, 'month'], [1, 'year']] as [$period, $unit]) {
            $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => "$period $unit",
                'type' => 'recurring', 'billing_period' => $period, 'billing_unit' => $unit]);
            Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);
        }

        return $product;
    }

    /**
     * ADV-1 (high): retired = hidden + stock 0, but every way a service ends gives its stock back (cancellation,
     * termination, cron, Cancel Order). A retired product has old customers: the first one who cancels revives it, and
     * it is sold again by its link and by cart.php?a=add&pid=N.
     */
    public function test_a_retired_product_stays_retired_when_one_of_its_services_ends(): void
    {
        $retired = $this->product($this->shop, ['slug' => 'old', 'whmcs_id' => 12, 'hidden' => true, 'stock' => 0, 'retired' => true]);
        $user = User::factory()->create();
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'product_id' => $retired->id, 'plan_id' => $retired->plans()->first()->id,
            'status' => Service::STATUS_ACTIVE, 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);

        // The customer cancels the old service themselves.
        ServiceCancellation::create(['service_id' => $service->id, 'type' => 'immediate', 'reason' => 'Not needed']);
        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);

        $this->get('/products/cpanel-hosting/old')->assertNotFound();
        $this->get('/products/cpanel-hosting/old/checkout')->assertNotFound();
        $this->get('/cart.php?a=add&pid=12')->assertRedirect(route('home'));
    }

    /**
     * ADV-2 (medium): the «hidden category» fallback fires on a VISIBLE category that has only child categories and
     * lists the parent's own hidden products in it.
     */
    public function test_a_visible_parent_category_does_not_list_its_own_hidden_products(): void
    {
        $parent = Category::factory()->create(['slug' => 'hosting']);
        $child = Category::factory()->create(['slug' => 'shared', 'parent_id' => $parent->id]);
        $this->product($child, ['slug' => 'basic']);
        $this->product($parent, ['slug' => 'secret', 'hidden' => true]);

        $this->get('/products/hosting')->assertOk()->assertDontSee('Secret plan');
    }

    /**
     * ADV-3 (low): the WHMCS cycle picks the first plan of that cycle even when the visitor's currency has no price
     * for it, while another plan of the same cycle has one: the checkout opens on an unpriced plan and the order fails.
     */
    public function test_the_whmcs_cycle_preselects_a_plan_the_visitor_can_buy(): void
    {
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'suffix' => '', 'format' => '1.000,00']);
        $product = Product::factory()->create(['category_id' => $this->shop->id, 'slug' => 'vps', 'whmcs_id' => 20, 'name' => 'Vps']);
        $eurOnly = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly EUR',
            'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'month', 'sort' => 0]);
        Price::factory()->create(['plan_id' => $eurOnly->id, 'price' => 9, 'currency_code' => 'EUR']);
        $usd = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly USD',
            'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'month', 'sort' => 1]);
        Price::factory()->create(['plan_id' => $usd->id, 'price' => 10, 'currency_code' => 'USD']);
        $this->assertSame([$usd->id], $product->fresh()->availablePlans('USD')->pluck('id')->values()->all());

        $this->withSession(['currency' => 'USD'])->get('/cart.php?a=add&pid=20&billingcycle=monthly')
            ->assertRedirect(route('products.checkout', ['category' => $this->shop, 'product' => $product, 'plan' => $usd->id]));
    }

    // ---- Attacks that held -------------------------------------------------------------------------------------

    public function test_held_a_plan_of_another_product_is_not_taken_by_the_checkout(): void
    {
        $hidden = $this->product($this->shop, ['slug' => 'custom', 'hidden' => true]);
        $other = $this->product($this->shop, ['slug' => 'other']);

        $this->get('/products/cpanel-hosting/custom/checkout?plan=' . $other->plans()->first()->id)->assertNotFound();
    }

    public function test_held_the_domain_link_cannot_redirect_off_site(): void
    {
        $response = $this->get('/cart.php?a=add&domain=register&query=' . urlencode('//evil.example/x'));
        $this->assertStringStartsWith(route('domain.search'), (string) $response->headers->get('Location'));
        $response = $this->get('/clients/cart.php?a=add&domain=register&sld=' . urlencode('https://evil.example'));
        $this->assertStringStartsWith(route('domain.search'), (string) $response->headers->get('Location'));
    }

    public function test_held_retired_and_hidden_lookups_by_crafted_ids(): void
    {
        $this->product($this->shop, ['slug' => 'old', 'whmcs_id' => 12, 'hidden' => true, 'stock' => 0, 'retired' => true]);
        $this->product($this->shop, ['slug' => 'local', 'hidden' => true]); // whmcs_id null

        $this->get('/cart.php?a=add&pid[]=12')->assertRedirect(route('home'));
        $this->get('/cart.php?a=add&pid=0')->assertRedirect(route('home'));
        $this->get('/cart.php?a=add&pid=12abc')->assertRedirect(route('home'));
        // A retired product in a category that also lists others is not shown there.
        $this->product($this->shop, ['slug' => 'starter']);
        $this->get('/products/cpanel-hosting')->assertOk()->assertSee('Starter plan')->assertDontSee('Old plan')->assertDontSee('Local plan');
    }
}
