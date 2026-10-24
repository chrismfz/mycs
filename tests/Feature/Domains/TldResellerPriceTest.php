<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainTldResource\Pages\ImportTlds;
use App\Models\Currency;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Role;
use App\Models\Staff;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\Pricing\TldCostSync;
use App\Services\Domains\Pricing\TldImport;
use App\Services\Domains\Pricing\TldImportOptions;
use App\Support\Domains\TldPricing;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What a TLD costs us at Openprovider (owner 2026-10-24, its panel beside the import page): a registration is its
 * `reseller_price` (.com 9.80), not the list's `create_price` (11.23); and a TLD sold from 2 years (.gr: `min_period` 2)
 * is quoted for 2 years (12.50 = 6.25 a year), so no price or cost is ever written for 1 year of it.
 */
class TldResellerPriceTest extends TestCase
{
    use RefreshDatabase;

    private const PROD = 'https://api.openprovider.eu';

    private DomainRegistrarConnection $op;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Cache::flush();
        Http::preventStrayRequests();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->storeSettings(['default_currency' => 'EUR']);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
    }

    /** @return array<string, mixed> as Openprovider answers (2026-10-24: .com, .gr) */
    private function answer(string $name, int $min, array $prices): array
    {
        $block = fn (float $price) => ['product' => ['price' => $price + 1, 'currency' => 'USD'], 'reseller' => ['price' => $price, 'currency' => 'EUR']];

        return ['name' => $name, 'status' => 'ACT', 'min_period' => $min, 'max_period' => 10,
            'prices' => ['min_period' => $min] + array_map($block, $prices)];
    }

    private function fakeOp(): void
    {
        Http::fake([
            self::PROD . '/v1/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::PROD . '/v1/tlds/com?*' => Http::response(['code' => 0, 'data' => $this->answer('com', 1,
                ['create_price' => 11.23, 'reseller_price' => 9.80, 'renew_price' => 9.80, 'transfer_price' => 9.80])]),
            self::PROD . '/v1/tlds/gr?*' => Http::response(['code' => 0, 'data' => $this->answer('gr', 2,
                ['create_price' => 29.98, 'reseller_price' => 12.50, 'renew_price' => 12.50, 'transfer_price' => 0, 'trade_price' => 29.98])]),
            self::PROD . '/v1/tlds/old?*' => Http::response(['code' => 0, 'data' => $this->answer('old', 1, ['create_price' => 5.00, 'renew_price' => 5.00])]),
        ]);
    }

    private function pricing(string $tld): TldPricing
    {
        return app(DomainRegistrarFactory::class)->for($this->op)->getTldPricing($tld, app(DomainRegistrarFactory::class)->credentialsFor($this->op));
    }

    public function test_a_registration_costs_its_reseller_price_for_its_shortest_period(): void
    {
        $this->fakeOp();

        $com = $this->pricing('com');
        $this->assertSame([1, '9.80'], [$com->minYears, $com->costs['register']['cost']], 'what its panel charges, not the list price 11.23');
        $gr = $this->pricing('gr');
        $this->assertSame([2, '12.50', '12.50', '0.00'], [$gr->minYears, $gr->costs['register']['cost'], $gr->costs['renew']['cost'], $gr->costs['transfer']['cost']]);
        $this->assertSame(['6.25', '12.50', '25.00', '0.00'], [$gr->costFor('register', 1), $gr->costFor('register', 2), $gr->costFor('renew', 4), $gr->costFor('transfer', 3)]);
        $this->assertSame('5.00', $this->pricing('old')->costs['register']['cost'], 'no reseller_price: the create_price');
    }

    public function test_the_import_writes_no_price_for_fewer_years_than_the_registrar_sells(): void
    {
        $this->fakeOp();
        $prices = app(TldImport::class)->prices($this->pricing('gr'), new TldImportOptions(marginType: 'percent', margin: '20', years: 3), ['EUR'])['EUR'];

        $this->assertSame([2], array_keys($prices['register']), 'from 2 years, whole periods only: no 3-year .gr');
        $this->assertSame(['price' => '15.00', 'cost' => '12.50', 'cost_currency' => 'EUR'], $prices['register'][2], '6.25 a year + 20% = 7.50');
        $four = app(TldImport::class)->prices($this->pricing('gr'), new TldImportOptions(marginType: 'percent', margin: '20', years: 5), ['EUR'])['EUR']['register'];
        $this->assertSame([2, 4], array_keys($four));
        $this->assertSame(['price' => '30.00', 'cost' => '25.00', 'cost_currency' => 'EUR'], $four[4]);
        $this->assertSame([2], array_keys(app(TldImport::class)->prices($this->pricing('gr'), new TldImportOptions(marginType: 'percent', margin: '20', years: 1), ['EUR'])['EUR']['renew']), '1 year asked: the shortest it sells');
        $this->assertSame([1], array_keys($prices['transfer']));

        $odd = new TldPricing(tld: 'x', costs: ['register' => ['cost' => '29.97', 'currency' => 'EUR']], minYears: 2);
        $this->assertSame(['price' => '29.97', 'cost' => '29.97', 'cost_currency' => 'EUR'],
            app(TldImport::class)->prices($odd, new TldImportOptions(marginType: 'percent', margin: '0', years: 2), ['EUR'])['EUR']['register'][2], 'exact until one rounding: 14.985 a year, 29.97 for two');
    }

    public function test_the_cost_sync_writes_a_two_year_quote_on_two_years_and_leaves_one_year_alone(): void
    {
        $this->fakeOp();
        $gr = DomainTld::query()->create(['tld' => 'gr', 'registrar_connection_id' => $this->op->id]);
        $row = fn (int $years) => DomainTldPrice::query()->create(['domain_tld_id' => $gr->id, 'operation' => 'register', 'years' => $years, 'currency_code' => 'EUR', 'price' => '99.00']);
        [$one, $two, $four] = [$row(1), $row(2), $row(4)];

        app(TldCostSync::class)->sync($gr);

        $this->assertSame([null, '12.50', '25.00'], [$one->fresh()->cost, $two->fresh()->cost, $four->fresh()->cost], 'not 25.00 for two years');
        $this->assertSame('99.00', $two->fresh()->price, 'never a price');
    }

    public function test_the_grid_compares_a_two_year_tld_at_two_years(): void
    {
        $this->fakeOp();
        $gr = DomainTld::query()->create(['tld' => 'gr']);
        DomainTldPrice::query()->create(['domain_tld_id' => $gr->id, 'operation' => 'register', 'years' => 2, 'currency_code' => 'EUR', 'price' => '13.00']);
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R', 'permissions' => ['*']])->id]);

        $page = $this->livewireAs($staff)->test(ImportTlds::class)
            ->fillForm(['connection_id' => $this->op->id, 'tlds' => 'gr com', 'margin_type' => 'percent', 'margin' => '20', 'rounding' => 'none', 'years' => 1])
            ->call('fetch');
        $rows = $page->instance()->grid()['rows'];

        $this->assertSame(2, $rows['gr']['min_years']);
        $this->assertSame(['now' => '13.00', 'new' => '15.00', 'cost' => '12.50', 'cost_own' => '12.50 EUR', 'margin' => '4.0', 'alert' => 'thin'], $rows['gr']['ops']['register']);
        $this->assertSame(['9.80', '11.76'], [$rows['com']['ops']['register']['cost'], $rows['com']['ops']['register']['new']]);
        $page->assertSee('2 years');

        $page->set('selected', ['gr'])->call('import');
        $this->assertSame([2], $gr->prices()->where('operation', 'register')->pluck('years')->all(), 'no 1-year .gr');
        $this->assertSame('15.00', $gr->load('prices')->priceFor('register', 2, 'EUR'));
    }

    public function test_an_import_takes_off_sale_the_periods_the_registrar_does_not_sell(): void
    {
        $this->fakeOp();
        $gr = DomainTld::query()->create(['tld' => 'gr']);
        $row = fn (string $op, int $years, ?string $price) => DomainTldPrice::query()->create(['domain_tld_id' => $gr->id, 'operation' => $op, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
        [$one, $three, $transfer] = [$row('register', 1, '35.98'), $row('renew', 3, '40.00'), $row('transfer', 1, '0.00')];

        app(TldImport::class)->import($this->op, ['gr' => $this->pricing('gr')], new TldImportOptions(marginType: 'percent', margin: '20', years: 4));

        $this->assertSame([null, null], [$one->fresh()->price, $three->fresh()->price], 'a 1- and a 3-year .gr: not offered');
        $this->assertSame('0.00', $transfer->fresh()->price, 'a transfer is not a period: kept');
        $this->assertSame(['15.00', '30.00'], [$gr->load('prices')->priceFor('register', 2, 'EUR'), $gr->priceFor('renew', 4, 'EUR')]);
    }
}
