<?php

namespace Tests\Feature\Domains;

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
use Livewire\Livewire;
use Tests\TestCase;

/** The hosting's domain at checkout (owner 2026-10-22, as WHMCS asks): free → register it along; taken → transfer or keep. */
class CheckoutDomainStepTest extends TestCase
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

    private function checkout()
    {
        return Livewire::test(Checkout::class, ['category' => $this->category, 'product' => 'litespeed-20']);
    }

    public function test_a_free_name_is_offered_and_goes_to_the_cart_with_the_hosting_in_one_cart(): void
    {
        $this->checkout()->set('checkoutConfig.domain', 'mybusiness.com')->call('checkDomain')
            ->assertSee(__('domains.checkout.free', ['domain' => 'mybusiness.com']))->assertSee('12,00')->assertSet('domainAction', 'register')
            ->call('checkout')->assertHasNoErrors()->assertRedirect(route('cart'));

        $this->assertSame(1, Cart::query()->count(), 'the hosting and its domain in one cart, not two');
        $items = CartItem::query()->get();
        $this->assertCount(2, $items);
        $this->assertSame(['register', 'mybusiness.com'], [$items->firstWhere('product_id', null)->domain['operation'], $items->firstWhere('product_id', null)->domain['fqdn']]);
        $this->assertSame('mybusiness.com', $items->firstWhere('product_id', $this->hosting->id)->checkout_config['domain']);
    }

    public function test_unticked_only_the_hosting(): void
    {
        $this->checkout()->set('checkoutConfig.domain', 'mybusiness.com')->call('checkDomain')->set('domainAction', '')->call('checkout');

        $this->assertSame([$this->hosting->id], CartItem::query()->pluck('product_id')->all());
    }

    public function test_a_taken_name_is_kept_unless_a_transfer_is_chosen_with_its_code(): void
    {
        $page = $this->checkout()->set('checkoutConfig.domain', 'NixPal.com')->call('checkDomain')
            ->assertSee(__('domains.checkout.taken', ['domain' => 'nixpal.com']))->assertSee('10,00')->assertSet('domainAction', '');

        $page->set('domainAction', 'transfer')->set('domainEpp', ' Abc-123 ')->call('checkout')->assertHasNoErrors();

        $domain = CartItem::query()->whereNull('product_id')->sole();
        $this->assertSame(['transfer', 'nixpal.com', 'Abc-123'], [$domain->domain['operation'], $domain->domain['fqdn'], $domain->epp_code]);
    }

    public function test_a_check_of_another_name_than_the_one_ordered_adds_no_domain(): void
    {
        $page = $this->checkout()->set('checkoutConfig.domain', 'mybusiness.com')->call('checkDomain')->assertSet('domainAction', 'register');

        $page->set('checkoutConfig.domain', 'otherbusiness.com')->assertSet('domainOffer', null)->assertSet('domainAction', '')->call('checkout');

        $this->assertSame([$this->hosting->id], CartItem::query()->pluck('product_id')->all());
    }

    public function test_a_name_taken_meanwhile_keeps_the_hosting_here_with_why(): void
    {
        $page = $this->checkout()->set('checkoutConfig.domain', 'mybusiness.com')->call('checkDomain');
        Domain::factory()->create(['sld' => 'mybusiness', 'tld' => 'com', 'fqdn' => 'mybusiness.com']);

        $page->call('checkout')->assertHasErrors('checkoutConfig.domain')->assertNoRedirect();
        $this->assertSame(0, CartItem::query()->count());
    }

    public function test_no_step_for_an_ending_we_do_not_sell_or_with_domains_off(): void
    {
        $this->checkout()->set('checkoutConfig.domain', 'mybusiness.xyz')->call('checkDomain')->assertSet('domainOffer', null)
            ->call('checkout')->assertHasNoErrors();
        $this->assertSame([$this->hosting->id], CartItem::query()->pluck('product_id')->all());

        config(['settings.domain_billing_enabled' => false]);
        $this->checkout()->assertDontSee(__('domains.checkout.lead'));
    }

    public function test_it_reads_in_greek(): void
    {
        app()->setLocale('el');
        $this->checkout()->assertSee('Για ποιο domain είναι το hosting;');
    }
}
