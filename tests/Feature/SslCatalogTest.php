<?php

namespace Tests\Feature;

use App\Admin\Resources\ProductResource\Pages\ImportSsl;
use App\Models\Category;
use App\Models\Currency;
use App\Models\DomainRegistrarConnection;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\Staff;
use App\Services\Domains\Pricing\TldImportOptions;
use App\Services\Ssl\SslCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «SSL catalogue & pricing» (owner 2026-10-22: what can be sold, at what price, with what margin): Openprovider's
 * products with this account's cost per period, which MyCS can sell, the MyCS product each is, and the prices a margin
 * makes; the import writes only the ticked ones.
 */
class SslCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    private Category $category;

    /** @var list<array<string, mixed>> Openprovider's products */
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Cache::flush();
        Http::preventStrayRequests();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        $connection = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $connection->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->server = Server::query()->create(['name' => 'SSL', 'extension' => 'OpenproviderSsl', 'type' => 'server', 'enabled' => true]);
        $this->server->settings()->create(['key' => 'connection_id', 'value' => (string) $connection->id]);
        $this->category = Category::factory()->create(['name' => 'SSL']);
        $this->products = [
            $this->product(9, 'Positive SSL', 'domain_validation', 'domain', [1 => 6.53, 2 => 11.9, 3 => 16.5]),
            $this->product(11, 'Positive SSL Wildcard', 'wildcard', 'domain', [1 => 65.42], ['is_wildcard_supported' => true]),
            $this->product(58, 'Code Signing', 'organization_validation', 'organization', [1 => 61.59]),
            $this->product(40, 'Positive SSL multi-domain', 'multi_domain', 'domain', [1 => 16.81], ['max_domains' => 250]),
            $this->product(70, 'EV SSL', 'extended_validation', 'extended', []),
        ];
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match ($path) {
                '/v1/auth/login' => Http::response(['code' => 0, 'data' => ['token' => 'tok']]),
                '/v1/ssl/products' => Http::response(['code' => 0, 'data' => ['results' => $this->products, 'total' => count($this->products)]]),
                default => Http::response(['code' => 404, 'desc' => 'no'], 404),
            };
        });
    }

    /** @param  array<int, float>  $costs  years => EUR */
    private function product(int $id, string $name, string $category, string $validation, array $costs, array $extra = []): array
    {
        return $extra + ['id' => $id, 'name' => $name, 'brand_name' => 'Sectigo', 'category' => $category, 'validation_method' => $validation,
            'max_domains' => 0, 'prices' => array_map(fn (int $years, float $cost) => ['period' => $years, 'price' => [
                'product' => ['price' => $cost * 1.07, 'currency' => 'USD'], 'reseller' => ['price' => $cost, 'currency' => 'EUR']]], array_keys($costs), $costs)];
    }

    private function staff(array $permissions = ['*']): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function importOptions(int $years = 1, array $rates = []): TldImportOptions
    {
        return new TldImportOptions(marginType: 'percent', margin: '30', rounding: '.99', years: $years, rates: $rates);
    }

    public function test_what_can_be_sold_and_why_not(): void
    {
        $entries = SslCatalog::entries($this->products);

        $this->assertSame([9 => null, 11 => null, 58 => 'code_signing', 40 => 'multi_domain', 70 => 'no_price'], array_map(fn ($e) => $e['reason'], $entries));
        $this->assertTrue($entries[11]['wildcard']);
        $this->assertSame(['6.53', '11.90', '16.50'], array_column($entries[9]['costs'], 'cost'), 'Openprovider\'s own price per period');
    }

    public function test_prices_from_each_periods_cost_with_margin_and_rounding(): void
    {
        $entry = SslCatalog::entries($this->products)[9];

        $prices = SslCatalog::prices($entry, $this->importOptions(3, ['USD' => '1.10']));

        $this->assertSame(['8.99', '15.99', '21.99'], array_column($prices['EUR'], 'price'), '6.53, 11.90, 16.50 + 30%, up to .99');
        $this->assertSame('9.99', $prices['USD'][1]['price'], '6.53 × 1.10 + 30% = 9.34 → 9.99');
        $this->assertArrayNotHasKey('USD', SslCatalog::prices($entry, $this->importOptions()), 'no rate, no price');
        $this->assertSame('37.6', SslCatalog::marginPercent('8.99', '6.53'));
        $this->assertSame('-8.1', SslCatalog::marginPercent('6.00', '6.53'));
    }

    public function test_import_makes_a_hidden_product_with_its_plans(): void
    {
        $entry = SslCatalog::entries($this->products)[9];

        $this->assertSame(1, SslCatalog::import($this->server, [$entry], $this->importOptions(2), $this->category->id, true));

        $product = Product::query()->where('server_id', $this->server->id)->sole();
        $this->assertSame(['Sectigo Positive SSL', true, $this->category->id, '9'],
            [$product->name, (bool) $product->hidden, $product->category_id, $product->settings()->where('key', 'product_id')->value('value')]);
        $plans = $product->plans()->with('prices')->get();
        $this->assertSame([[1, '8.99'], [2, '15.99']], $plans->map(fn (Plan $p) => [$p->billing_period, (string) $p->prices->firstWhere('currency_code', 'EUR')->price])->all());
        $this->assertSame(['year', 'year'], $plans->pluck('billing_unit')->all());
    }

    public function test_import_reprices_an_existing_product_and_keeps_the_rest(): void
    {
        $entry = SslCatalog::entries($this->products)[9];
        SslCatalog::import($this->server, [$entry], $this->importOptions(), $this->category->id, true);
        $product = Product::query()->sole();
        $product->update(['name' => 'Our PositiveSSL', 'hidden' => false]);
        $monthly = (new Plan)->forceFill(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'type' => 'recurring', 'name' => 'Monthly', 'billing_unit' => 'month', 'billing_period' => 1]);
        $monthly->save();
        $monthly->prices()->create(['currency_code' => 'EUR', 'price' => '1.00']);

        SslCatalog::import($this->server, [$entry], new TldImportOptions(marginType: 'fixed', margin: '5', rounding: 'none'), $this->category->id, true);

        $product->refresh();
        $this->assertSame(['Our PositiveSSL', false], [$product->name, (bool) $product->hidden], 'its name and listing kept');
        $this->assertSame(1, Product::query()->count());
        $this->assertSame('11.53', (string) $product->plans()->where('billing_unit', 'year')->sole()->prices()->value('price'));
        $this->assertSame('1.00', number_format((float) $monthly->prices()->value('price'), 2), 'another plan untouched');
        $this->assertSame(['EUR' => [1 => '11.53']], SslCatalog::linked($this->server)[9]['prices']);
    }

    public function test_a_product_that_cannot_be_sold_is_never_imported(): void
    {
        $entries = SslCatalog::entries($this->products);

        $this->assertSame(0, SslCatalog::import($this->server, [$entries[58], $entries[40], $entries[70]], $this->importOptions(), $this->category->id, true));
        $this->assertSame(0, Product::query()->count());
    }

    public function test_the_page_shows_the_catalogue_with_margins_and_imports_the_ticked(): void
    {
        $page = $this->livewireAs($this->staff())->test(ImportSsl::class)
            ->fillForm(['server_id' => $this->server->id, 'category_id' => $this->category->id, 'margin_type' => 'percent', 'margin' => '30', 'rounding' => '.99', 'years' => 1])
            ->call('fetch')
            ->assertSee('Positive SSL Wildcard')->assertSee('Code signing: not a website certificate')->assertSee('8.99')->assertSee('37.6%');
        $this->assertSame(0, Product::query()->count(), 'fetching writes nothing');

        $page->set('selected', [9, 58])->call('import');

        $this->assertSame(['Sectigo Positive SSL'], Product::query()->pluck('name')->all(), 'only the sellable one ticked');
        $this->livewireAs($this->staff())->test(ImportSsl::class)
            ->fillForm(['server_id' => $this->server->id, 'margin_type' => 'percent', 'margin' => '30', 'rounding' => '.99', 'years' => 1])
            ->call('fetch')->assertSee('Sectigo Positive SSL')->assertSee('hidden');
    }

    public function test_fetched_costs_cannot_be_forged_and_staff_need_the_products_permissions(): void
    {
        $page = $this->livewireAs($this->staff())->test(ImportSsl::class)
            ->fillForm(['server_id' => $this->server->id, 'category_id' => $this->category->id, 'margin_type' => 'percent', 'margin' => '30', 'rounding' => '.99', 'years' => 1]);
        $this->assertInstanceOf(\Throwable::class, rescue(fn () => $page->set('fetched', [9 => ['id' => 9, 'sellable' => true, 'costs' => [1 => ['cost' => '0.01', 'currency' => 'EUR']]]]), fn ($e) => $e, false));

        \Illuminate\Support\Facades\Auth::guard('staff')->logout();
        $this->assertFalse(ImportSsl::canAccess(), 'no staff');
        $this->actingAs($this->staff(['admin.products.view']));
        $this->assertFalse(ImportSsl::canAccess());
        $this->actingAs($this->staff(['admin.products.create', 'admin.products.update']));
        $this->assertTrue(ImportSsl::canAccess());
    }
}
