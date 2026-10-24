<?php

namespace Tests\Feature;

use App\Admin\Resources\ProductResource;
use App\Admin\Resources\ProductResource\Pages\ImportSsl;
use App\Models\Category;
use App\Models\Currency;
use App\Models\DomainRegistrarConnection;
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
 * «SSL catalogue & pricing» polished as the TLD import's grid (owner 2026-10-24): price now → new over the cost, tones
 * below or near the cost, search, filters, currency and years, a «Pricing» link per MyCS product, and a price staff type
 * for one product written instead of the margin's.
 */
class SslCatalogGridTest extends TestCase
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

    private function form(array $extra = []): array
    {
        return $extra + ['server_id' => $this->server->id, 'category_id' => $this->category->id, 'margin_type' => 'percent', 'margin' => '30', 'rounding' => '.99', 'years' => 1];
    }

    private function page()
    {
        return $this->livewireAs($this->staff())->test(ImportSsl::class)->fillForm($this->form())->call('fetch');
    }

    /** @return array<int, array<string, mixed>> the grid's rows by Openprovider id */
    private static function rows($page): array
    {
        return collect($page->instance()->grid()['rows'])->keyBy('id')->all();
    }

    /** The product of id 9 in MyCS at this EUR price for 1 year. */
    private function existing(string $price): Product
    {
        SslCatalog::import($this->server, [SslCatalog::entries($this->products)[9]], $this->importOptions(), $this->category->id, true);
        $product = Product::query()->sole();
        $product->plans()->sole()->prices()->where('currency_code', 'EUR')->update(['price' => $price]);

        return $product;
    }

    public function test_the_grid_shows_now_new_and_cost_with_tones_and_a_pricing_link(): void
    {
        $product = $this->existing('6.00'); // below its 6.53 cost
        $page = $this->page();
        $row = self::rows($page)[9];

        $this->assertSame(['6.00', '8.99', '6.53', 'loss', '-8.1', '37.7'], [$row['now'], $row['new'], $row['cost'], $row['alert'], $row['now_margin'], $row['new_margin']]);
        $page->assertSee('6.00')->assertSee('→ 8.99')->assertSee(ProductResource::getUrl('edit', ['record' => $product]))->assertSee('Pricing');
        $this->assertSame(1, $page->instance()->grid()['alerts']);

        $product->plans()->sole()->prices()->where('currency_code', 'EUR')->update(['price' => '7.00']); // 7.2% over: thin
        $this->assertSame('thin', self::rows($page)[9]['alert']);
    }

    public function test_search_filters_currency_years_and_sort(): void
    {
        $this->existing('8.99');
        $page = $this->page();

        $this->assertSame([11], array_keys(self::rows($page->set('search', 'wildcard'))));
        $page->set('search', '');
        $this->assertSame([9], array_keys(self::rows($page->set('filter', 'mycs'))));
        $this->assertSame([11], array_keys(self::rows($page->set('filter', 'new'))));
        $this->assertEqualsCanonicalizing([58, 40, 70], array_keys(self::rows($page->set('filter', 'unsellable'))));
        $this->assertSame(['9', '11'], array_map('strval', array_keys(self::rows($page->set('filter', 'sellable')))));
        $this->assertCount(5, self::rows($page->set('filter', 'nonsense')), 'an unknown filter shows all');

        $page->set('filter', 'sellable')->call('sortBy', 'cost')->call('sortBy', 'cost'); // descending
        $this->assertSame([11, 9], array_keys(self::rows($page)));
        $page->call('sortBy', 'drop table');
        $this->assertSame('cost', $page->get('sort'));

        $page->fillForm($this->form(['years' => 2]))->set('gridYears', 2);
        $this->assertSame(['11.90', '15.99'], [self::rows($page)[9]['cost'], self::rows($page)[9]['new']]);
        $this->assertNull(self::rows($page)[11]['line'], 'the wildcard is sold for one year only');

        $page->set('gridYears', 1)->set('currency', 'USD');
        $this->assertNull(self::rows($page)[9]['line'], 'no USD rate, no USD price');
        $page->set('data.rates.USD', '1.10');
        $this->assertSame('9.99', self::rows($page)[9]['new']);
    }

    public function test_a_typed_price_is_written_instead_of_the_margins_and_ticks_its_product(): void
    {
        $page = $this->page()->set('overrides.' . ImportSsl::overrideKey(9, 'EUR', 1), '12,50');

        $this->assertSame(['9'], array_map('strval', $page->get('selected')), 'typing a price ticks its product');
        $this->assertSame(['12.50', '91.4'], [self::rows($page)[9]['new'], self::rows($page)[9]['new_margin']]);
        $page->call('import');

        $this->assertSame('12.50', number_format((float) Product::query()->sole()->plans()->sole()->prices()->where('currency_code', 'EUR')->value('price'), 2, '.', ''));
        $this->assertSame([], $page->get('overrides'), 'cleared once written');
    }

    public function test_a_typed_price_overwrites_a_free_one_the_margin_keeps(): void
    {
        $product = $this->existing('0.00');
        $page = $this->page();
        $this->assertTrue(self::rows($page)[9]['line']['kept_free']);

        $page->set('selected', [9])->call('import');
        $this->assertSame('0.00', number_format((float) $product->plans()->sole()->prices()->where('currency_code', 'EUR')->value('price'), 2), 'the margin keeps a free price');

        $page->set('overrides.' . ImportSsl::overrideKey(9, 'EUR', 1), '9.50')->call('import');
        $this->assertSame('9.50', number_format((float) $product->plans()->sole()->prices()->where('currency_code', 'EUR')->value('price'), 2, '.', ''), 'a typed one is staff\'s decision');
    }

    public function test_a_typed_price_that_cannot_be_written_refuses_the_import(): void
    {
        foreach (['0', '-5', '12.345', 'abc', '100000000.00', '1e3'] as $bad) {
            $page = $this->page()->set('selected', [9])->set('overrides.' . ImportSsl::overrideKey(9, 'EUR', 1), $bad);
            $this->assertTrue(self::rows($page)[9]['typed_invalid'], $bad);
            $page->call('import');
            $this->assertSame(0, Product::query()->count(), "«{$bad}» refused, nothing written");
        }
        // Years beyond the plans chosen, a currency MyCS has not, a product not sold for those years: refused too.
        foreach ([ImportSsl::overrideKey(9, 'EUR', 2), ImportSsl::overrideKey(9, 'GBP', 1), ImportSsl::overrideKey(11, 'EUR', 3)] as $key) {
            $this->page()->set('selected', [9, 11])->set('overrides', [$key => '20.00'])->call('import');
            $this->assertSame(0, Product::query()->count(), $key);
        }
        // A price typed for a product then unticked waits: the ticked one is imported with the margin.
        $this->page()->set('overrides', [ImportSsl::overrideKey(11, 'EUR', 1) => '1.00'])->set('selected', [9])->call('import');
        $this->assertSame(['Sectigo Positive SSL'], Product::query()->pluck('name')->all());
    }

    public function test_the_import_confirmation_counts_prices_below_cost(): void
    {
        $page = $this->page()->set('selected', [9]);
        $this->assertSame(0, $page->instance()->belowCostTicked());

        $page->set('overrides.' . ImportSsl::overrideKey(9, 'EUR', 1), '5.00');
        $this->assertSame(1, $page->instance()->belowCostTicked());
        $this->assertSame('loss', self::rows($page)[9]['new_alert']);
    }
}
