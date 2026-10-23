<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainTldResource;
use App\Admin\Resources\DomainTldResource\Pages\ImportTlds;
use App\Models\Currency;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Role;
use App\Models\Staff;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\Registrars\OpenproviderRegistrar;
use App\Services\Domains\TldNotOffered;
use App\Support\Domains\TldPricing;
use Filament\Facades\Filament;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use RuntimeException;
use Tests\TestCase;

/**
 * «TLD Import & Pricing Sync» as a grid (owner 2026-10-24): Openprovider read many TLDs at once, or all of them; a TLD it
 * does not sell (.gov.gr: code 307 in an HTTP 500) is «not offered», never a failure; one row per TLD with today's price,
 * its cost and its margin, red below cost and orange up to 10% above; search, filters, sort and pages; «Pricing».
 */
class TldImportGridTest extends TestCase
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

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => ['*']])->id]);
    }

    /** @return array<string, mixed> one TLD as Openprovider answers it (alone, or as an entry of its list) */
    private function tld(string $name, string $register, string $renew, ?string $transfer = null, string $status = 'ACT'): array
    {
        $q = fn (string $price) => ['product' => ['price' => 999, 'currency' => 'USD'], 'reseller' => ['price' => (float) $price, 'currency' => 'EUR']];

        return ['name' => $name, 'status' => $status, 'prices' => ['create_price' => $q($register), 'renew_price' => $q($renew), 'transfer_price' => $q($transfer ?? $renew)]];
    }

    private function invalidExtension(): PromiseInterface
    {
        return Http::response(['code' => 307, 'desc' => 'Your domain request contains an invalid extension!', 'data' => []], 500);
    }

    /** @param  array<string, mixed>  $routes */
    private function fake(array $routes): void
    {
        Http::fake([self::PROD . '/v1/auth/login' => Http::response(['data' => ['token' => 'tok']])] + $routes);
    }

    private function adapter(): OpenproviderRegistrar
    {
        /** @var OpenproviderRegistrar */
        return app(DomainRegistrarFactory::class)->for($this->op);
    }

    private function page(): Testable
    {
        return $this->livewireAs($this->staff())->test(ImportTlds::class)
            ->fillForm(['connection_id' => $this->op->id, 'margin_type' => 'percent', 'margin' => '20', 'rounding' => 'none', 'years' => 1]);
    }

    public function test_many_tlds_are_read_at_once_in_the_order_asked_and_one_not_sold_is_left_out(): void
    {
        $this->fake([
            self::PROD . '/v1/tlds/com?*' => Http::response(['code' => 0, 'data' => $this->tld('com', '11.23', '9.80')]),
            self::PROD . '/v1/tlds/edu.gr?*' => Http::response(['code' => 0, 'data' => $this->tld('edu.gr', '4.00', '4.00')]),
            self::PROD . '/v1/tlds/gov.gr?*' => $this->invalidExtension(),
            self::PROD . '/v1/tlds/net?*' => Http::response(['code' => 1, 'desc' => 'Maintenance'], 503),
        ]);

        $answers = $this->adapter()->getTldPricingMany(['.EDU.gr', 'gov.gr', 'net', 'com'], app(DomainRegistrarFactory::class)->credentialsFor($this->op));

        $this->assertSame(['edu.gr', 'net', 'com'], array_keys($answers), 'as asked; .gov.gr not sold: left out');
        $this->assertInstanceOf(TldPricing::class, $answers['com']);
        $this->assertSame(['cost' => '11.23', 'currency' => 'EUR'], $answers['com']->costs['register'], 'the reseller price, never the product one');
        $this->assertSame('Openprovider error 503: Maintenance', $answers['net'], 'one failure never stops the rest');
    }

    public function test_a_tld_it_does_not_sell_is_said_so_alone_too(): void
    {
        $this->fake([self::PROD . '/v1/tlds/gov.gr?*' => $this->invalidExtension()]);

        $this->expectException(TldNotOffered::class);
        $this->adapter()->getTldPricing('gov.gr', app(DomainRegistrarFactory::class)->credentialsFor($this->op));
    }

    public function test_an_expired_token_logs_in_again_once_for_the_tlds_read_at_once(): void
    {
        $logins = 0;
        Http::fake(function (Request $request) use (&$logins) {
            if (str_ends_with($request->url(), '/v1/auth/login')) {
                $logins++;

                return Http::response(['data' => ['token' => 'tok' . $logins]]);
            }

            return $request->hasHeader('Authorization', 'Bearer tok1')
                ? Http::response(['desc' => 'Token expired'], 401)
                : Http::response(['code' => 0, 'data' => $this->tld('com', '11.23', '9.80')]);
        });

        $answers = $this->adapter()->getTldPricingMany(['com', 'net'], app(DomainRegistrarFactory::class)->credentialsFor($this->op));

        $this->assertSame(2, $logins);
        $this->assertContainsOnlyInstancesOf(TldPricing::class, $answers);
    }

    public function test_every_tld_it_offers_is_read_in_pages_and_a_failed_page_fails_the_list(): void
    {
        $entries = array_map(fn (int $i) => $this->tld('t' . $i, '1.00', '2.00'), range(1, 1100));
        $entries[3]['status'] = 'INA'; // not sold now
        $entries[4]['prices'] = 'garbled';
        $page = fn (int $offset) => ['code' => 0, 'data' => ['total' => 1100, 'results' => array_slice($entries, $offset, 500)]];
        $this->fake([
            self::PROD . '/v1/tlds?with_price=true&limit=500&offset=0' => Http::sequence()->push($page(0))->push($page(0)),
            self::PROD . '/v1/tlds?with_price=true&limit=500&offset=500' => Http::sequence()->push($page(500))->push(['desc' => 'Busy'], 502),
            self::PROD . '/v1/tlds?with_price=true&limit=500&offset=1000' => Http::sequence()->push($page(1000))->push($page(1000)),
        ]);

        $all = $this->adapter()->listTldPricing(app(DomainRegistrarFactory::class)->credentialsFor($this->op));

        $this->assertCount(1098, $all, 'all three pages, less the one not sold and the garbled one');
        $this->assertSame(['register' => ['cost' => '1.00', 'currency' => 'EUR'], 'renew' => ['cost' => '2.00', 'currency' => 'EUR'], 'transfer' => ['cost' => '2.00', 'currency' => 'EUR']], $all['t1100']->costs);
        $this->assertArrayNotHasKey('t4', $all);
        Http::assertSentCount(4); // the login and three pages

        try {
            $this->adapter()->listTldPricing(app(DomainRegistrarFactory::class)->credentialsFor($this->op));
            $this->fail('a partial list would hide TLDs');
        } catch (RuntimeException $e) {
            $this->assertSame('Openprovider error 502: Busy', $e->getMessage());
        }
    }

    public function test_one_row_per_tld_with_its_margin_red_below_cost_orange_near_it_and_not_offered_greyed(): void
    {
        $eu = DomainTld::query()->create(['tld' => 'eu']);
        foreach (['register' => '7.50', 'renew' => '4.20', 'transfer' => '11.50'] as $operation => $price) {
            DomainTldPrice::query()->create(['domain_tld_id' => $eu->id, 'operation' => $operation, 'years' => 1, 'currency_code' => 'EUR', 'price' => $price]);
        }
        $this->fake([
            self::PROD . '/v1/tlds/eu?*' => Http::response(['code' => 0, 'data' => $this->tld('eu', '6.98', '4.60')]),
            self::PROD . '/v1/tlds/xyz?*' => Http::response(['code' => 0, 'data' => $this->tld('xyz', '1.86', '12.65')]),
            self::PROD . '/v1/tlds/gov.gr?*' => $this->invalidExtension(),
        ]);

        $page = $this->page()->fillForm(['tlds' => 'eu xyz gov.gr'])->call('fetch');
        $rows = $page->instance()->grid()['rows'];

        $this->assertSame(['eu', 'xyz', 'gov.gr'], array_keys($rows), 'MyCS\'s first');
        $this->assertSame(['now' => '7.50', 'new' => '8.38', 'cost' => '6.98', 'cost_own' => '6.98 EUR', 'margin' => '7.4', 'alert' => 'thin'], $rows['eu']['ops']['register']);
        $this->assertSame(['-8.7', 'loss'], [$rows['eu']['ops']['renew']['margin'], $rows['eu']['ops']['renew']['alert']]);
        $this->assertSame(['150.0', null], [$rows['eu']['ops']['transfer']['margin'], $rows['eu']['ops']['transfer']['alert']]);
        $this->assertSame([null, '2.23', null], [$rows['xyz']['ops']['register']['now'], $rows['xyz']['ops']['register']['new'], $rows['xyz']['ops']['register']['margin']], 'new: only what the import would write');
        $this->assertTrue($rows['gov.gr']['not_offered']);
        $this->assertFalse($rows['gov.gr']['importable']);
        $page->assertSee('Not offered by this registrar.')->assertDontSee('invalid extension')
            ->assertSee(DomainTldResource::getUrl('edit', ['record' => $eu->id]), false)
            ->assertSee('-8.7%')->assertSee('→ 8.38', false);
        $this->assertSame(1, $page->instance()->grid()['alerts']);

        $page->set('selected', ['gov.gr'])->call('import');
        $this->assertNull(DomainTld::query()->where('tld', 'gov.gr')->first(), 'not offered: never imported');
    }

    public function test_every_tld_searched_filtered_sorted_paged_and_ticked_a_page_at_a_time(): void
    {
        DomainTld::query()->create(['tld' => 'com']);
        $results = [$this->tld('com', '11.23', '9.80'), $this->tld('gr', '9.00', '9.00'), $this->tld('com.gr', '8.00', '8.00'),
            $this->tld('co.uk', '6.27', '4.90'), $this->tld('xyz', '1.86', '12.65')];
        foreach (range(1, 120) as $i) {
            $results[] = $this->tld('zz' . str_pad((string) $i, 3, '0', STR_PAD_LEFT) . 'x', '30.00', '30.00');
        }
        $this->fake([self::PROD . '/v1/tlds?with_price=true&limit=500&offset=0' => Http::response(['code' => 0, 'data' => ['total' => count($results), 'results' => $results]])]);

        $page = $this->page()->call('fetchAll');
        $grid = $page->instance()->grid();
        $this->assertSame([125, 3, 'com'], [$grid['total'], $grid['pages'], array_key_first($grid['rows'])], 'MyCS\'s first, 50 a page');

        $page->set('search', '.gr');
        $this->assertSame(['com.gr', 'gr'], array_keys($page->instance()->grid()['rows']));
        $page->set('search', '')->set('filter', 'new')->call('sortBy', 'register.cost');
        $this->assertSame(['xyz', 'co.uk', 'com.gr'], array_slice(array_keys($page->instance()->grid()['rows']), 0, 3), 'cheapest first');
        $page->call('sortBy', 'register.cost');
        $this->assertSame('zz001x', array_key_first($page->instance()->grid()['rows']), 'again: turned round, then by name');
        $page->call('sortBy', 'nonsense')->assertSet('sort', 'register.cost');

        $page->set('filter', 'all')->call('sortBy', 'tld')->set('perPage', 100)->set('pageNo', 2);
        $grid = $page->instance()->grid();
        $this->assertSame([2, 25], [$grid['pages'], count($grid['rows'])]);
        $page->call('togglePage');
        $this->assertCount(25, $page->get('selected'), 'this page only');
        $page->call('togglePage')->assertSet('selected', []);

        $page->set('pageNo', 99);
        $this->assertSame(2, $page->instance()->grid()['pages']);
        $page->assertSet('pageNo', 2);

        $page->call('fetchAll', 'geo');
        $this->assertSame(['co.uk', 'com.gr', 'gr'], array_keys($page->instance()->grid()['rows']), 'country TLDs and those under them');
    }

    public function test_popular_fetches_the_setting_and_an_import_from_the_list_writes_its_prices(): void
    {
        $this->storeSettings(['default_currency' => 'EUR', 'domain_popular_tlds' => '.io, eu']);
        $this->fake([
            self::PROD . '/v1/tlds/io?*' => Http::response(['code' => 0, 'data' => $this->tld('io', '46.85', '46.85')]),
            self::PROD . '/v1/tlds/eu?*' => Http::response(['code' => 0, 'data' => $this->tld('eu', '4.60', '4.60')]),
        ]);

        $page = $this->page()->call('fetchPopular')->assertSet('data.tlds', 'io, eu');
        $this->assertSame(['io', 'eu'], array_keys($page->instance()->grid()['rows']));

        $page->set('selected', ['io'])->call('import');
        $this->assertSame('56.22', DomainTld::query()->where('tld', 'io')->sole()->load('prices')->priceFor('register', 1, 'EUR'));
        $this->assertNull(DomainTld::query()->where('tld', 'eu')->first(), 'not ticked');
    }

    public function test_the_costs_are_kept_on_the_server_for_whoever_fetched_them_and_never_taken_from_the_browser(): void
    {
        $this->fake([self::PROD . '/v1/tlds/com?*' => Http::response(['code' => 0, 'data' => $this->tld('com', '11.23', '9.80')])]);
        $page = $this->page()->fillForm(['tlds' => 'com'])->call('fetch');
        $key = $page->get('fetchKey');
        $this->assertNotNull(Cache::get('tld-import:' . $key));

        // Another staff member's page with the same key sees nothing.
        Cache::put('tld-import:' . $key, ['staff' => 999999] + Cache::get('tld-import:' . $key), 60);
        $page->call('$refresh');
        $this->assertSame([], $page->instance()->fetched());

        Cache::flush();
        $page->call('$refresh')->assertSee('The costs fetched are no longer kept: fetch them again.');
        $page->set('selected', ['com'])->call('import');
        $this->assertSame(0, DomainTld::query()->count(), 'nothing kept: nothing imported');

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('fetchKey', 'forged');
    }

    public function test_all_needs_no_tlds_typed_and_fetch_asks_for_them(): void
    {
        $this->fake([self::PROD . '/v1/tlds?with_price=true&limit=500&offset=0' => Http::response(['code' => 0, 'data' => ['total' => 2, 'results' => [
            $this->tld('com', '11.23', '9.80'), $this->tld('ΕΛ', '10.00', '10.00'),
        ]]])]);
        DomainTld::query()->create(['tld' => 'xn--qxam']);

        $page = $this->page()->fillForm(['tlds' => ''])->call('fetch')->assertNotified(__('Type the TLDs to fetch.'));
        $this->assertSame([], $page->instance()->fetched());

        $page->call('fetchAll')->assertHasNoFormErrors();
        $rows = $page->instance()->grid()['rows'];
        $this->assertSame(['xn--qxam', 'com'], array_keys($rows), 'an IDN in one form with MyCS\'s: found, first');
        $this->assertTrue($rows['xn--qxam']['exists']);
    }

    public function test_a_list_shorter_than_its_total_fails_rather_than_hiding_tlds(): void
    {
        $this->fake([self::PROD . '/v1/tlds?with_price=true&limit=500&offset=0' => Http::response(['code' => 0, 'data' => ['total' => 3, 'results' => [
            $this->tld('com', '11.23', '9.80'),
        ]]])]);

        $this->expectExceptionMessage('1 από τα 3');
        $this->adapter()->listTldPricing(app(DomainRegistrarFactory::class)->credentialsFor($this->op));
    }
}
