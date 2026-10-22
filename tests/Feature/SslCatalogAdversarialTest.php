<?php

namespace Tests\Feature;

use App\Admin\Resources\ProductResource\Pages\ImportSsl;
use App\Helpers\ExtensionHelper;
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
 * Adversarial review of the «SSL catalogue & pricing» page (feat/ssl-pricing): odd Openprovider answers, prices written
 * to the wrong product, Livewire tampering, authorization. Each ADV-n fails on the code as reviewed; the «regression»
 * tests pass and stay as guards.
 */
class SslCatalogAdversarialTest extends TestCase
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

    /**
     * ADV-1 (HIGH). A cost under half a cent (0.004, a float artefact or a promo price) passes the «> 0» test on the raw
     * float, is then rounded to 0.00, and the margin of zero is zero («zero stays zero», even with .99 rounding): the
     * import writes a FREE certificate that MyCS pays Openprovider for. Fix: test the cost after rounding it (cost >= 0.01),
     * or treat a cost that rounds to zero as «no price».
     */
    public function test_adv1_a_cost_rounding_to_zero_never_becomes_a_free_price(): void
    {
        $entry = SslCatalog::entries([$this->product(9, 'Positive SSL', 'domain_validation', [1 => 0.004])])[9];

        $prices = SslCatalog::prices($entry, $this->opts());
        SslCatalog::import($this->server, [$entry], $this->opts(), $this->category->id, true);

        $this->assertFalse($entry['sellable'] && ($prices['EUR'][1]['price'] ?? null) === '0.00', 'a 0.00 price was offered: ' . json_encode($prices));
        $product = Product::query()->first();
        $this->assertTrue($product === null || !in_array('0.00', $this->pricesOf($product), true), 'a free certificate was written');
    }

    /**
     * ADV-2 (MEDIUM). The rates are «one EURO is how much of each currency», but a cost Openprovider gives in another
     * currency (GBP here; the account's currency is Openprovider's choice) is converted with them as if it were EUR: a
     * GBP cost × the EUR→USD rate becomes the USD price, silently. Fix: only convert a cost in EUR (the rates' base), or
     * mark a product whose cost is in a currency with no rate from it as not priceable.
     */
    public function test_adv2_a_cost_in_another_currency_is_not_converted_with_the_euro_rates(): void
    {
        $entry = SslCatalog::entries([$this->product(9, 'Positive SSL', 'domain_validation', [1 => 100], currency: 'GBP')])[9];

        $prices = SslCatalog::prices($entry, $this->opts(rates: ['USD' => '1.10']));

        $this->assertArrayNotHasKey('USD', $prices, 'GBP 100 priced as USD with the EUR→USD rate: ' . json_encode($prices));
    }

    /**
     * ADV-3 (MEDIUM). Two MyCS products with the same Openprovider id on the server (an old retired one and the live
     * one, or a copy): linked() keeps the first, so the import reprices the retired product and leaves the live one at
     * its old price (below the new cost), and the page shows the retired one as «the» product. Fix: prefer a product
     * that is not retired, and refuse (or reprice all and show all) when two live products share an id.
     */
    public function test_adv3_the_live_product_is_repriced_not_a_retired_twin(): void
    {
        $retired = $this->sslProduct(9, ['retired' => true, 'hidden' => true]);
        $this->plan($retired, 'recurring', 'year', 1, ['EUR' => '1.00']);
        $live = $this->sslProduct(9);
        $this->plan($live, 'recurring', 'year', 1, ['EUR' => '1.00']);

        $entry = SslCatalog::entries($this->products)[9];
        SslCatalog::import($this->server, [$entry], $this->opts(), $this->category->id, true);

        $this->assertSame('8.99', $this->pricesOf($live)['EUR'] ?? null, 'the live product still sells at 1.00 against a 6.53 cost');
    }

    /**
     * ADV-4 (MEDIUM). The same id ticked twice (Livewire: `selected` = [9, 9]; or the same product on two pages) makes
     * TWO MyCS products: linked() is read once before the loop, so the second pass does not see the product the first
     * made. Fix: unique the selected ids, and/or re-read (or remember) the products made inside the transaction.
     */
    public function test_adv4_the_same_product_ticked_twice_makes_one_product(): void
    {
        $this->livewireAs($this->staff())->test(ImportSsl::class)
            ->fillForm($this->form())->call('fetch')
            ->set('selected', [9, '9'])->call('import');

        $this->assertSame(1, Product::query()->count(), 'one Openprovider product, two MyCS products');
    }

    /**
     * ADV-5 (LOW). A huge or non-finite cost («1e400» is numeric: INF; 1e13 is over TldPriceMath's 12 digits) is kept as
     * sellable («inf», «10000000000000.00») and then throws in prices(): rows() lets it through, so one odd product
     * breaks the whole page (500) and every import with it. Fix: in entries(), accept only a cost TldPriceMath can carry
     * (is_finite, isAmount after formatting), else «no price».
     */
    public function test_adv5_one_absurd_cost_does_not_break_the_page(): void
    {
        $this->products[] = $this->product(12, 'Broken SSL', 'domain_validation', [1 => '1e400']);
        $this->products[] = $this->product(13, 'Huge SSL', 'domain_validation', [1 => 1.0e13]);

        $entries = SslCatalog::entries($this->products);
        $this->assertFalse($entries[12]['sellable'], 'an INF cost is sellable: ' . json_encode($entries[12]['costs']));
        $this->assertFalse($entries[13]['sellable'], 'a cost of 13 digits is sellable');

        $this->livewireAs($this->staff())->test(ImportSsl::class)
            ->fillForm($this->form())->call('fetch')
            ->assertOk()->assertSee('Positive SSL');
    }

    /**
     * ADV-6 (LOW). Openprovider's `max_period` (the longest a certificate can be issued for) is ignored: a price row for
     * a longer period becomes a 2- or 3-year plan that customers pay for and the order then cannot place (period over
     * the product's max). Fix: drop costs for periods above max_period when it is given.
     */
    public function test_adv6_no_plan_longer_than_the_products_max_period(): void
    {
        $entry = SslCatalog::entries([$this->product(9, 'Positive SSL', 'domain_validation', [1 => 6.53, 2 => 11.9, 3 => 16.5], ['max_period' => 1])])[9];

        $this->assertSame([1], array_keys($entry['costs']), 'periods over max_period are priced');
    }

    /**
     * ADV-7 (LOW). Not a website certificate, yet sellable: the code-signing / S-MIME test is a substring of the name
     * («code signing», «s/mime»), so «Code-Signing», «CodeSign» or a category of its own slips through, as does a
     * multi-domain product that says so only in `number_of_domains`. The customer pays and the CSR step (one name and
     * its www) then cannot order what was sold. Fix: decide on Openprovider's category/flags first (an allowlist of
     * website categories: domain/organization/extended validation, wildcard), the name only as a fallback.
     */
    public function test_adv7_what_the_extension_cannot_order_is_not_sellable(): void
    {
        $entries = SslCatalog::entries([
            $this->product(58, 'EV Code-Signing', 'code_signing', [1 => 200]),
            $this->product(59, 'Personal Authentication', 'email_validation', [1 => 20]),
            $this->product(41, 'Positive SSL', 'domain_validation', [1 => 30], ['number_of_domains' => 3]),
        ]);

        $this->assertSame([58 => false, 59 => false, 41 => false], array_map(fn ($e) => $e['sellable'], $entries));
    }

    /**
     * ADV-8 (LOW). The same period twice, or the same product twice in the listing, with different prices: the last one
     * wins silently, so a lower duplicate becomes the cost. Fix: keep the higher cost (the safe side), or mark the
     * product «no price» when Openprovider's answer contradicts itself.
     */
    public function test_adv8_a_contradicting_duplicate_never_lowers_the_cost(): void
    {
        $twicePeriod = $this->product(9, 'Positive SSL', 'domain_validation', [1 => 60]);
        $twicePeriod['prices'][] = ['period' => 1, 'price' => ['reseller' => ['price' => 6, 'currency' => 'EUR']]];
        $entries = SslCatalog::entries([$twicePeriod, $this->product(10, 'OV SSL', 'organization_validation', [1 => 80]),
            $this->product(10, 'OV SSL', 'organization_validation', [1 => 8])]);

        $this->assertNotSame('6.00', $entries[9]['costs'][1]['cost'] ?? null, 'the period listed twice: the lower one won');
        $this->assertNotSame('8.00', $entries[10]['costs'][1]['cost'] ?? null, 'the product listed twice: the lower one won');
    }

    /**
     * ADV-9 (LOW). Paging stops at the first page shorter than the 100 asked, and `total` is never read: an Openprovider
     * that caps a page lower (50 here) shows half the catalogue with no sign of it. Fix: page until `total` is reached
     * (or an empty page), and say so when the 1000 cap is hit.
     */
    public function test_adv9_paging_reads_up_to_total(): void
    {
        $this->products = array_map(fn (int $i) => $this->product(1000 + $i, "SSL {$i}", 'domain_validation', [1 => 10]), range(1, 120));
        $this->pageCap = 50;

        $extension = ExtensionHelper::getExtension('server', 'OpenproviderSsl', $this->server->settings);

        $this->assertCount(120, $extension->catalogue());
    }

    /**
     * ADV-10 (MEDIUM). Two plans of the same years (a «1 year» plan and a «12 months» one, both recurring): the import
     * reprices the first only; the other stays at its old price under the new cost, and the page shows only one of them.
     * Fix: reprice every recurring plan of those years (or refuse the product and say why).
     */
    public function test_adv10_every_plan_of_those_years_is_repriced(): void
    {
        $product = $this->sslProduct(9);
        $this->plan($product, 'recurring', 'year', 1, ['EUR' => '1.00']);
        $monthly = $this->plan($product, 'recurring', 'month', 12, ['EUR' => '1.00']);

        SslCatalog::import($this->server, [SslCatalog::entries($this->products)[9]], $this->opts(), $this->category->id, true);

        $this->assertSame('8.99', number_format((float) $monthly->prices()->where('currency_code', 'EUR')->value('price'), 2, '.', ''),
            'the 12-month plan still sells at 1.00 against a 6.53 cost');
    }

    /**
     * ADV-11 (MEDIUM). A currency with no rate is «never a silent conversion», but its CURRENT price is also never shown:
     * a product sold in USD at 5.00 against a 6.53 EUR cost has no USD line on the page, so staff reprice EUR and believe
     * the product is priced, while USD keeps selling under cost. Fix: show each current price of the product, and flag the
     * currencies the import will not touch.
     */
    public function test_adv11_a_price_the_import_will_not_touch_is_still_shown(): void
    {
        $product = $this->sslProduct(9);
        $this->plan($product, 'recurring', 'year', 1, ['EUR' => '1.00', 'USD' => '5.00']);

        $page = $this->livewireAs($this->staff())->test(ImportSsl::class)->fillForm($this->form())->call('fetch');
        $lines = collect($page->instance()->rows())->firstWhere('id', 9)['lines'];

        $this->assertContains('USD', array_column($lines, 'currency'), 'the USD price 5.00 (under cost) is not on the page');
    }

    /** Regression: the import is all or nothing (a throw on the second product rolls back the first). */
    public function test_regression_a_failing_import_writes_nothing(): void
    {
        $good = SslCatalog::entries($this->products)[9];
        $bad = ['id' => 77, 'name' => 'Bad', 'brand' => 'X', 'sellable' => true, 'costs' => [1 => ['cost' => 'inf', 'currency' => 'EUR']]];

        $this->assertNotNull(rescue(fn () => SslCatalog::import($this->server, [$good, $bad], $this->opts(), $this->category->id, true), fn ($e) => $e, false));
        $this->assertSame(0, Product::query()->count());
    }

    /** Regression: fetched from server A, imported to server B: refused; a product of another server is never touched. */
    public function test_regression_another_server_is_never_written(): void
    {
        $other = $this->sslServer('SSL 2', DomainRegistrarConnection::query()->sole());
        $foreign = Product::query()->create(['category_id' => $this->category->id, 'name' => 'Foreign', 'slug' => 'foreign', 'description' => '', 'server_id' => $other->id]);
        $foreign->settings()->create(['key' => 'product_id', 'value' => '9']);
        $this->plan($foreign, 'recurring', 'year', 1, ['EUR' => '1.00']);

        $page = $this->livewireAs($this->staff())->test(ImportSsl::class)->fillForm($this->form())->call('fetch')
            ->fillForm($this->form(['server_id' => $other->id]))->set('selected', [9])->call('import');
        $this->assertSame(1, Product::query()->count(), 'imported to a server it was not fetched from');

        $page->fillForm($this->form())->call('import');
        $this->assertSame('1.00', $this->pricesOf($foreign)['EUR']);
        $this->assertSame(2, Product::query()->count());
    }

    /** Regression: a free plan of the same unit is never priced, and years > 3 from the browser are clamped. */
    public function test_regression_free_plan_untouched_and_years_clamped(): void
    {
        $product = $this->sslProduct(9);
        $free = $this->plan($product, 'free', 'year', 1, []);

        $this->livewireAs($this->staff())->test(ImportSsl::class)->fillForm($this->form())->call('fetch')
            ->set('data.years', 9)->set('selected', [9])->call('import');

        $this->assertSame(0, $free->prices()->count());
        $this->assertLessThanOrEqual(3, (int) $product->plans()->max('billing_period'));
    }

    /** Regression: staff whose permission is gone cannot import from a page opened earlier (hydrate re-checks). */
    public function test_regression_import_rechecks_permission_on_each_request(): void
    {
        $page = $this->livewireAs($this->staff())->test(ImportSsl::class)->fillForm($this->form())->call('fetch')->set('selected', [9]);

        $this->actingAs($this->staff(['admin.products.view']));
        $page->call('import')->assertForbidden();

        $this->assertSame(0, Product::query()->count());
    }

    /** Regression: an Openprovider name is shown escaped. */
    public function test_regression_names_are_escaped(): void
    {
        $this->products = [$this->product(9, '<img src=x onerror=alert(1)>', 'domain_validation', [1 => 6.53])];

        $this->livewireAs($this->staff())->test(ImportSsl::class)->fillForm($this->form())->call('fetch')
            ->assertDontSee('<img src=x onerror=alert(1)>', false)->assertSee('&lt;img src=x', false);
    }
}
