<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainTldResource\Pages\ImportTlds;
use App\Models\Currency;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Role;
use App\Models\Staff;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\Pricing\TldImport;
use App\Services\Domains\Pricing\TldImportOptions;
use App\Support\Domains\TldPricing;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Adversarial review of #424 (reseller_price and min_period): each test fails on the PR's code for a real defect.
 */
class TldResellerPriceAdversarialTest extends TestCase
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

    /** @return array<string, mixed> */
    private function answer(string $name, mixed $min, array $prices): array
    {
        $block = fn (float $price) => ['product' => ['price' => $price + 1, 'currency' => 'USD'], 'reseller' => ['price' => $price, 'currency' => 'EUR']];

        return ['name' => $name, 'status' => 'ACT', 'min_period' => $min, 'max_period' => 10,
            'prices' => ['min_period' => $min] + array_map($block, $prices)];
    }

    private function pricing(string $tld): TldPricing
    {
        return app(DomainRegistrarFactory::class)->for($this->op)->getTldPricing($tld, app(DomainRegistrarFactory::class)->credentialsFor($this->op));
    }

    /**
     * F1: a `min_period` sent as a numeric string ("2", as the days next to it may be: they are accepted as strings) is
     * read as 1 year: a .gr is priced as 12.50 a YEAR (2 years: 30.00 for a 12.50 cost) and a 1-year .gr, which the
     * registrar does not sell, is written.
     */
    public function test_f1_a_min_period_given_as_a_numeric_string_is_still_two_years(): void
    {
        Http::fake([
            self::PROD . '/v1/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::PROD . '/v1/tlds/gr?*' => Http::response(['code' => 0, 'data' => $this->answer('gr', '2',
                ['reseller_price' => 12.50, 'renew_price' => 12.50])]),
        ]);

        $gr = $this->pricing('gr');
        $prices = app(TldImport::class)->prices($gr, new TldImportOptions(marginType: 'percent', margin: '20', years: 2), ['EUR'])['EUR'];

        $this->assertSame(2, $gr->minYears, 'min_period "2" is 2 years, as "40" is 40 days');
        $this->assertSame([2], array_keys($prices['register']), 'no 1-year .gr');
        $this->assertSame('15.00', $prices['register'][2]['price'], '12.50 for 2 years + 20%, not 30.00');
    }

    /**
     * F2: an offer's `reseller_price` of 0 (a free first year) now reaches the price: register is written 0.00, which the
     * import then takes for staff's «free» decision and keeps. When the offer ends (reseller_price 18.00) the next import
     * keeps 0.00: every registration is given away while it costs us 18.00. With `create_price` (before #424) a list
     * price was never 0.
     */
    public function test_f2_a_free_offer_does_not_become_a_free_price_kept_after_the_offer_ends(): void
    {
        $reseller = 0.0;
        Http::fake(function ($request) use (&$reseller) {
            if (str_contains($request->url(), '/v1/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }

            return Http::response(['code' => 0, 'data' => $this->answer('xyz', 1,
                ['create_price' => 18.00, 'reseller_price' => $reseller, 'renew_price' => 18.00])]);
        });
        $options = new TldImportOptions(marginType: 'percent', margin: '20');

        app(TldImport::class)->import($this->op, ['xyz' => $this->pricing('xyz')], $options);
        $reseller = 18.00; // the offer ends
        app(TldImport::class)->import($this->op, ['xyz' => $this->pricing('xyz')], $options);

        $tld = DomainTld::query()->where('tld', 'xyz')->firstOrFail()->load('prices');
        $this->assertSame('18.00', $tld->prices->firstWhere('operation', 'register')->cost);
        $this->assertNotSame('0.00', $tld->priceFor('register', 1, 'EUR'), 'a registration that costs 18.00 is not sold for nothing');
    }

    /**
     * F3: the rounding staff chose is lost on the periods between multiples of the shortest one: a .gr rounded to .00
     * (15.00 for 2 years) costs 22.50 for 3, rounded to .50 (15.50) 23.25 for 3. For a 1-year TLD N × a whole or .50
     * price always kept its ending.
     */
    public function test_f3_odd_years_of_a_two_year_tld_keep_the_rounding_ending(): void
    {
        $gr = new TldPricing(tld: 'gr', costs: ['register' => ['cost' => '12.50', 'currency' => 'EUR']], minYears: 2);
        $whole = app(TldImport::class)->prices($gr, new TldImportOptions(marginType: 'percent', margin: '20', rounding: '.00', years: 3), ['EUR'])['EUR']['register'];
        $half = app(TldImport::class)->prices(
            new TldPricing(tld: 'gr', costs: ['register' => ['cost' => '12.80', 'currency' => 'EUR']], minYears: 2),
            new TldImportOptions(marginType: 'percent', margin: '20', rounding: '.50', years: 3), ['EUR'])['EUR']['register'];

        $this->assertSame('15.00', $whole[2]['price']);
        $this->assertMatchesRegularExpression('/\.00$/', $whole[3]['price'], 'rounded to whole: not 22.50');
        $this->assertSame('15.50', $half[2]['price']);
        $this->assertMatchesRegularExpression('/\.(00|50)$/', $half[3]['price'], 'rounded to .50: not 23.25');
    }

    /**
     * F4: a fetch kept from before #424 (up to 3 hours, the page left open across the deploy) has no `min_years`: it is
     * taken as 1 year, and importing a .gr from it writes 1-year .gr prices from a 2-year quote.
     */
    public function test_f4_a_fetch_kept_from_before_the_change_does_not_import_a_one_year_gr(): void
    {
        Http::fake([
            self::PROD . '/v1/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::PROD . '/v1/tlds/gr?*' => Http::response(['code' => 0, 'data' => $this->answer('gr', 2,
                ['create_price' => 29.98, 'reseller_price' => 12.50, 'renew_price' => 12.50])]),
        ]);
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R', 'permissions' => ['*']])->id]);
        $page = $this->livewireAs($staff)->test(ImportTlds::class)
            ->fillForm(['connection_id' => $this->op->id, 'tlds' => 'gr', 'margin_type' => 'percent', 'margin' => '20', 'rounding' => 'none', 'years' => 1])
            ->call('fetch');
        // The same entry as the code before #424 kept it: create_price, no min_years.
        $key = 'tld-import:' . $page->get('fetchKey');
        $kept = Cache::get($key);
        $kept['tlds']['gr'] = ['costs' => ['register' => ['cost' => '29.98', 'currency' => 'EUR'], 'renew' => ['cost' => '12.50', 'currency' => 'EUR']]];
        Cache::put($key, $kept, 3600);

        $page->set('selected', ['gr'])->call('import');

        $gr = DomainTld::query()->where('tld', 'gr')->first();
        $this->assertSame([], $gr === null ? [] : $gr->prices()->where('years', 1)->whereIn('operation', ['register', 'renew'])->pluck('price')->all(),
            'no 1-year .gr from a fetch that does not say its shortest period');
    }
}
