<?php

namespace Tests\Feature;

use App\Admin\Resources\ProductResource\Pages\ImportSsl;
use App\Models\Category;
use App\Models\Currency;
use App\Models\DomainRegistrarConnection;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Adversarial review of #442 («SSL catalogue & pricing» as a grid, with a price typed per product, currency and years).
 * Each test_adv_N exposes a defect and fails until it is fixed; the last one is a defence that holds.
 */
class SslCatalogGridAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    private Server $other;

    private Category $category;

    /** @var list<array<string, mixed>> */
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
        $this->server = Server::query()->create(['name' => 'SSL A', 'extension' => 'OpenproviderSsl', 'type' => 'server', 'enabled' => true]);
        $this->server->settings()->create(['key' => 'connection_id', 'value' => (string) $connection->id]);
        $this->other = Server::query()->create(['name' => 'SSL B', 'extension' => 'OpenproviderSsl', 'type' => 'server', 'enabled' => true]);
        $this->other->settings()->create(['key' => 'connection_id', 'value' => (string) $connection->id]);
        $this->category = Category::factory()->create(['name' => 'SSL']);
        $this->products = [$this->product(9, 'Positive SSL', 'domain_validation', 'domain', [1 => 6.53, 2 => 11.9])];
        Http::fake(function (Request $request) {
            return match ((string) parse_url($request->url(), PHP_URL_PATH)) {
                '/v1/auth/login' => Http::response(['code' => 0, 'data' => ['token' => 'tok']]),
                '/v1/ssl/products' => Http::response(['code' => 0, 'data' => ['results' => $this->products, 'total' => count($this->products)]]),
                default => Http::response(['code' => 404, 'desc' => 'no'], 404),
            };
        });
    }

    /** @param  array<int, float>  $costs */
    private function product(int $id, string $name, string $category, string $validation, array $costs): array
    {
        return ['id' => $id, 'name' => $name, 'brand_name' => 'Sectigo', 'category' => $category, 'validation_method' => $validation, 'max_domains' => 0,
            'prices' => array_map(fn (int $years, float $cost) => ['period' => $years, 'price' => ['reseller' => ['price' => $cost, 'currency' => 'EUR']]], array_keys($costs), $costs)];
    }

    private function form(array $extra = []): array
    {
        return $extra + ['server_id' => $this->server->id, 'category_id' => $this->category->id, 'margin_type' => 'percent', 'margin' => '30', 'rounding' => '.99', 'years' => 1];
    }

    private function page()
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => ['*']])->id]);

        return $this->livewireAs($staff)->test(ImportSsl::class)->fillForm($this->form())->call('fetch');
    }

    private static function eur(?Product $product): ?string
    {
        $price = $product?->plans()->first()?->prices()->where('currency_code', 'EUR')->value('price');

        return $price === null ? null : number_format((float) $price, 2, '.', '');
    }

    /**
     * ADV-1: a price typed while looking at server A survives a «Fetch» from server B (fetch() clears `selected`, not
     * `overrides`), and the next import on B writes A's price to B's product instead of B's margin price.
     */
    public function test_adv_1_a_price_typed_for_one_server_is_written_to_another_after_a_fetch(): void
    {
        $page = $this->page()->set('overrides.' . ImportSsl::overrideKey(9, 'EUR', 1), '20.00');

        $page->fillForm($this->form(['server_id' => $this->other->id]))->call('fetch');
        $page->set('selected', [9])->call('import');

        $this->assertSame('8.99', self::eur(Product::query()->where('server_id', $this->other->id)->first()), 'B gets its margin price, not A\'s typed one');
    }

    /**
     * ADV-2: typedFor() parses keys with (int) casts, so `09:EUR:1`, `9:EUR:01` or `9:EUR:1.5` are taken as product 9,
     * 1 year. The grid reads only the canonical `9:EUR:1` (20.00), but the import writes whichever alias comes last (5.00).
     */
    public function test_adv_2_an_alias_key_overrides_the_price_the_grid_shows(): void
    {
        foreach (['09:EUR:1', '9:EUR:01', '9:EUR:1.5', ' 9:EUR:1'] as $alias) {
            Product::query()->each(fn (Product $p) => $p->delete());
            $page = $this->page()->set('selected', [9])->set('overrides', [ImportSsl::overrideKey(9, 'EUR', 1) => '20.00', $alias => '5.00']);
            $this->assertSame('20.00', collect($page->instance()->grid()['rows'])->firstWhere('id', 9)['new']);
            $page->call('import');

            $this->assertNotSame('5.00', self::eur(Product::query()->first()), "«{$alias}» is not the key of product 9, EUR, 1 year");
        }
    }

    /**
     * ADV-3: a key that is not `id:currency:years` (`9:EUR`, `9:EUR:1:x`) is skipped silently by typedFor(), and the
     * import writes the margin's price. The PR promises "anything else stops the import without writing anything".
     */
    public function test_adv_3_a_malformed_key_is_skipped_silently_instead_of_refusing(): void
    {
        foreach (['9:EUR', '9:EUR:1:x'] as $key) {
            $this->page()->set('selected', [9])->set('overrides', [$key => '5.00'])->call('import');

            $this->assertSame(0, Product::query()->count(), "«{$key}» refused, nothing written");
        }
    }

    /**
     * ADV-4: a value that is not a string (a number or an array sent over the wire) is marked «Not a price» by the grid
     * (typed_invalid), but typedFor() skips it with `continue` and the import writes the margin's price: grid and import
     * disagree, and the bad price is "skipped unseen".
     */
    public function test_adv_4_a_non_string_typed_value_is_flagged_by_the_grid_but_ignored_by_the_import(): void
    {
        foreach ([20, 12.5, ['20.00']] as $value) {
            $page = $this->page()->set('selected', [9])->set('overrides', [ImportSsl::overrideKey(9, 'EUR', 1) => $value]);
            $this->assertTrue(collect($page->instance()->grid()['rows'])->firstWhere('id', 9)['typed_invalid'], 'the grid says: not a price');
            $page->call('import');

            $this->assertSame(0, Product::query()->count(), var_export($value, true) . ' refused as the grid shows it, nothing written');
        }
    }

    /**
     * ADV-5: «Typed prices (N)» and its filter count only the currency and years on screen. A EUR price typed, then the
     * view switched to USD: the heading says 0 typed and the filter hides the product, while the import still writes it.
     */
    public function test_adv_5_the_typed_count_hides_prices_typed_in_another_view(): void
    {
        $page = $this->page()->set('overrides.' . ImportSsl::overrideKey(9, 'EUR', 1), '5.00')->set('data.rates.USD', '1.10')->set('currency', 'USD');

        $this->assertSame(1, $page->instance()->grid()['typed'], 'one typed price the import will write');
        $this->assertSame([9], array_column($page->set('filter', 'typed')->instance()->grid()['rows'], 'id'));
    }

    /**
     * ADV-6: a price typed for a currency with no rate is written by the import (the «Your price» box is shown), but the
     * grid's row says «No USD price: give its rate.» and never shows the price it will write.
     */
    public function test_adv_6_a_typed_price_in_a_currency_without_a_rate_is_hidden_by_the_grid(): void
    {
        $page = $this->page()->set('currency', 'USD')->set('overrides.' . ImportSsl::overrideKey(9, 'USD', 1), '20.00');

        $page->assertSee('→ 20.00', false)->assertDontSee('No USD price: give its rate.');
        $page->call('import');
        $this->assertSame('20.00', number_format((float) Product::query()->sole()->plans()->sole()->prices()->where('currency_code', 'USD')->value('price'), 2, '.', ''));
    }

    /**
     * Defence holds: odd typed values are refused (nothing written) or normalised to exact cents, never floated.
     */
    public function test_adv_7_odd_typed_values_are_refused_or_written_exactly(): void
    {
        foreach (['12.', '.5', '12,345.00', '١٢', '１２', '0.001', '99999999.995', '1.000', '+12', '12 50'] as $bad) {
            $this->page()->set('selected', [9])->set('overrides.' . ImportSsl::overrideKey(9, 'EUR', 1), $bad)->call('import');
            $this->assertSame(0, Product::query()->count(), "«{$bad}» refused");
        }
        $this->page()->set('overrides.' . ImportSsl::overrideKey(9, 'EUR', 1), ' 0012.5 ')->call('import');
        $this->assertSame('12.50', self::eur(Product::query()->sole()));
    }
}
