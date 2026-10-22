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
 * Round 2 of the adversarial review of #348, against the fix 83ead4c4: each R2-n fails on it.
 */
class SslCatalogRound2AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    private Category $category;

    /** @var list<array<string, mixed>> Openprovider's products */
    private array $products = [];

    /** At most this many rows per page, whatever the limit asked (null: as asked). */
    private ?int $pageCap = null;

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
        $this->server = $this->sslServer('SSL', $connection);
        $this->category = Category::factory()->create(['name' => 'SSL']);
        $this->products = [
            $this->product(9, 'Positive SSL', 'domain_validation', [1 => 6.53, 2 => 11.9, 3 => 16.5]),
        ];
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $limit = min((int) ($query['limit'] ?? 100), $this->pageCap ?? PHP_INT_MAX);
            $page = array_slice($this->products, (int) ($query['offset'] ?? 0), $limit);

            return match ($path) {
                '/v1/auth/login' => Http::response(['code' => 0, 'data' => ['token' => 'tok']]),
                '/v1/ssl/products' => Http::response(['code' => 0, 'data' => ['results' => $page, 'total' => count($this->products)]]),
                default => Http::response(['code' => 404, 'desc' => 'no'], 404),
            };
        });
    }

    private function sslServer(string $name, DomainRegistrarConnection $connection): Server
    {
        $server = Server::query()->create(['name' => $name, 'extension' => 'OpenproviderSsl', 'type' => 'server', 'enabled' => true]);
        $server->settings()->create(['key' => 'connection_id', 'value' => (string) $connection->id]);

        return $server;
    }

    /** @param  array<int, mixed>  $costs  years => reseller price */
    private function product(int $id, string $name, string $category, array $costs, array $extra = [], string $currency = 'EUR'): array
    {
        return $extra + ['id' => $id, 'name' => $name, 'brand_name' => 'Sectigo', 'category' => $category, 'validation_method' => 'domain',
            'max_domains' => 0, 'included_domains_count' => 1, 'prices' => array_map(fn (int $years, mixed $cost) => ['period' => $years, 'price' => [
                'product' => ['price' => 1, 'currency' => 'USD'], 'reseller' => ['price' => $cost, 'currency' => $currency]]], array_keys($costs), $costs)];
    }

    private function staff(array $permissions = ['*']): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function opts(int $years = 1, array $rates = [], string $rounding = '.99'): TldImportOptions
    {
        return new TldImportOptions(marginType: 'percent', margin: '30', rounding: $rounding, years: $years, rates: $rates);
    }

    private function form(array $extra = []): array
    {
        return $extra + ['server_id' => $this->server->id, 'category_id' => $this->category->id, 'margin_type' => 'percent', 'margin' => '30', 'rounding' => '.99', 'years' => 1];
    }

    private function sslProduct(int $opId, array $attributes = []): Product
    {
        $product = Product::query()->create($attributes + ['category_id' => $this->category->id, 'name' => 'SSL ' . uniqid(), 'slug' => 'ssl-' . uniqid(),
            'description' => '', 'server_id' => $this->server->id, 'hidden' => false]);
        $product->settings()->create(['key' => 'product_id', 'value' => (string) $opId]);

        return $product;
    }

    private function plan(Product $product, string $type, ?string $unit, ?int $period, array $prices): Plan
    {
        $plan = (new Plan)->forceFill(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'type' => $type, 'name' => $type . ' ' . $unit . ' ' . $period,
            'billing_unit' => $unit, 'billing_period' => $period]);
        $plan->save();
        foreach ($prices as $currency => $price) {
            $plan->prices()->create(['currency_code' => $currency, 'price' => $price]);
        }

        return $plan;
    }

    /** @return array<string, string> currency => price of the product's recurring plan of these years */
    private function pricesOf(Product $product, int $years = 1): array
    {
        $plan = $product->plans()->with('prices')->get()->first(fn (Plan $p) => $p->type === 'recurring' && $p->billing_unit === 'year' && (int) $p->billing_period === $years);

        return $plan?->prices->mapWithKeys(fn ($p) => [$p->currency_code => number_format((float) $p->price, 2, '.', '')])->all() ?? [];
    }

    private function priceOfPlan(Plan $plan, string $currency): ?string
    {
        $price = $plan->prices()->where('currency_code', $currency)->value('price');

        return $price === null ? null : number_format((float) $price, 2, '.', '');
    }

    /**
     * R2-1 (MEDIUM). The ADV-11 refusal («a product priced in a currency the import cannot price») reads linked(), which
     * keeps only the LOWEST price of a currency and years. A product with two 1-year plans, one free in USD (0.00, a
     * promo) and one at 5.00 USD, shows 0.00 as its USD price; the refusal skips a price of 0, so the import goes ahead,
     * reprices EUR and leaves the second plan selling at 5.00 USD against a cost of 6.53 EUR, unseen (the page shows the
     * 0.00 too). Fix: refuse on each plan's own price, not on the lowest (and show each plan's price on the page).
     */
    public function test_r2_1_a_free_plan_never_hides_an_unpriceable_plan_from_the_refusal(): void
    {
        $product = $this->sslProduct(9);
        $this->plan($product, 'recurring', 'year', 1, ['EUR' => '9.99', 'USD' => '0.00']);
        $paid = $this->plan($product, 'recurring', 'year', 1, ['EUR' => '9.99', 'USD' => '5.00']);
        $entry = SslCatalog::entries($this->products)[9];

        $refused = rescue(fn () => SslCatalog::import($this->server, [$entry], $this->opts(), $this->category->id, true), fn ($e) => $e, false);

        $this->assertInstanceOf(\InvalidArgumentException::class, $refused,
            'imported without a USD rate: the plan at USD ' . $this->priceOfPlan($paid, 'USD') . ' stays under its 6.53 EUR cost');
    }

    /**
     * R2-2 (MEDIUM). ADV-11's fix marks a price the import will not touch as «kept» only when there is no new price (no
     * rate, years not chosen). A free (0.00) price is also kept, by D-6 (keepFreeAndClosed), yet the page shows it with
     * a new price of 8.99 and a 30% margin: staff tick it, «import» succeeds, and the certificate is still free, sold
     * at 0.00 against a 6.53 cost. Fix: rows() marks a free or not-offered price as kept (new null, or a «kept» flag),
     * as reprice() decides it.
     */
    public function test_r2_2_the_page_never_promises_a_price_the_import_keeps_free(): void
    {
        $product = $this->sslProduct(9);
        $this->plan($product, 'recurring', 'year', 1, ['EUR' => '0.00']);

        $page = $this->livewireAs($this->staff())->test(ImportSsl::class)->fillForm($this->form())->call('fetch');
        $line = collect(collect($page->instance()->rows())->firstWhere('id', 9)['lines'])->first(fn ($l) => $l['currency'] === 'EUR' && $l['years'] === 1);
        $page->set('selected', [9])->call('import');
        $stored = $this->pricesOf($product->fresh())['EUR'];

        $this->assertTrue(($line['kept'] ?? false) || $line['new'] === null || $line['new'] === $stored,
            "the page shows a new price of {$line['new']}, the import kept {$stored}");
    }

    /**
     * R2-3 (LOW). «Never a free price» (ADV-1) is checked on Openprovider's cost only, never on the price written: a
     * converted cost under half a cent (a small rate, here 0.0005, a slip for 0.5) with rounding «none» becomes a price
     * of 0.00, and the import writes a FREE certificate in that currency. Worse, the 0.00 is then «a decision» (D-6): the
     * next import, with the right rate, keeps it free for good. Fix: a price that rounds to zero is no price (skip that
     * currency, or refuse), in prices().
     */
    public function test_r2_3_a_converted_price_never_rounds_to_free(): void
    {
        Currency::query()->firstOrCreate(['code' => 'GBP'], ['name' => 'Pound', 'prefix' => '£', 'format' => '1,000.00']);
        $entry = SslCatalog::entries($this->products)[9];

        SslCatalog::import($this->server, [$entry], $this->opts(rates: ['GBP' => '0.0005'], rounding: 'none'), $this->category->id, true);
        SslCatalog::import($this->server, [$entry], $this->opts(rates: ['GBP' => '0.85'], rounding: 'none'), $this->category->id, true);

        $gbp = $this->pricesOf(Product::query()->sole())['GBP'] ?? null;
        $this->assertTrue($gbp === null || bccomp($gbp, '0', 2) > 0, "a free GBP price was written and kept: {$gbp}");
    }
}
