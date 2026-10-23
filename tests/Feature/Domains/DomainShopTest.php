<?php

namespace Tests\Feature\Domains;

use App\Livewire\DomainShop\Search;
use App\Livewire\DomainShop\Whois;
use App\Models\Currency;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Services\Captcha\Altcha;
use App\Services\Domains\DomainSearch;
use App\Services\Domains\Lookup\LaravelTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Nixpal\DomainTools\Whois\SocketConnector;
use Tests\TestCase;

/**
 * D8a: the shop's domain search (one box, the exact name first, then the TLDs staff put first, each free or taken with
 * its yearly price), public whois (RDAP), the price list page and its JSON API. Rate limited per address.
 */
class DomainShopTest extends TestCase
{
    use RefreshDatabase;

    private const BOOTSTRAP = ['services' => [[['com', 'net'], ['https://rdap.example/']], [['eu'], ['https://rdap.eu.example/']]]];

    private DomainRegistrarConnection $op;

    protected function tearDown(): void
    {
        LaravelTransport::$resolve = null;
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // A lookup asks for the captcha (2026-10-23): «solved» is a solution here.
        $this->app->instance(Altcha::class, new class extends Altcha
        {
            public function verify(string $payload): bool
            {
                return $payload === 'solved';
            }
        });
        Cache::flush();
        Http::preventStrayRequests();
        // Hosts resolve without DNS here: the fake registries are public, «internal» ones are not.
        LaravelTransport::$resolve = fn (string $host) => str_contains($host, 'internal') ? ['10.0.0.5'] : ['93.184.216.34'];
        // Never a real port 43 query from a test.
        $this->app->bind(SocketConnector::class, fn () => new class implements SocketConnector
        {
            public function query($host, $query, $timeout = 8, $max = 65536)
            {
                return null;
            }
        });
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR']);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        foreach ([['com', 1, '23.33', '23.33'], ['net', 2, '26.43', '26.43'], ['org', 3, '15.58', '22.58'], ['eu', 4, '9.00', '9.00']] as [$tld, $sort, $register, $renew]) {
            $model = DomainTld::query()->create(['tld' => $tld, 'is_active' => true, 'sort' => $sort, 'registrar_connection_id' => $tld === 'eu' ? null : $this->op->id]);
            foreach (['register' => $register, 'renew' => $renew, 'transfer' => $renew] as $operation => $price) {
                DomainTldPrice::query()->create(['domain_tld_id' => $model->id, 'operation' => $operation, 'years' => 1, 'currency_code' => 'EUR', 'price' => $price]);
            }
        }
        DomainTld::query()->create(['tld' => 'io', 'is_active' => true, 'sort' => 5]); // no price: not sold
    }

    /** Openprovider says nixpal.com is taken and the rest free; RDAP knows .eu. */
    private function fake(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/auth/login') => Http::response(['data' => ['token' => 'tok']]),
                // All of a search's names in one request (review R1 of #178).
                str_contains($url, '/domains/check') => Http::response(['data' => ['results' => array_map(fn ($d) => [
                    'domain' => $d['name'] . '.' . $d['extension'],
                    'status' => $d['name'] . '.' . $d['extension'] === 'nixpal.com' ? 'active' : 'free',
                ], $request->data()['domains'])]]),
                str_contains($url, 'data.iana.org') => Http::response(self::BOOTSTRAP),
                str_contains($url, 'rdap.eu.example/domain/nixpal.eu') => Http::response([], 404),
                str_contains($url, 'rdap.example/domain/nixpal.com') => Http::response(['objectClassName' => 'domain', 'ldhName' => 'nixpal.com', 'status' => ['client transfer prohibited'],
                    'events' => [['eventAction' => 'registration', 'eventDate' => '2012-11-17T18:08:48Z'], ['eventAction' => 'expiration', 'eventDate' => '2027-11-17T18:08:48Z']],
                    'nameservers' => [['ldhName' => 'NS1.MYIP.GR'], ['ldhName' => 'NS2.MYIP.GR']],
                    'entities' => [['roles' => ['registrar'], 'vcardArray' => ['vcard', [['fn', [], 'text', 'Openprovider']]]]]]),
                default => Http::response([], 404),
            };
        });
    }

    public function test_a_name_shows_its_tld_first_then_the_others_with_prices_and_who_has_a_taken_one(): void
    {
        $this->fake();

        $results = app(DomainSearch::class)->search('https://www.Nixpal.com/page', 'EUR');

        $this->assertSame(['nixpal.com', 'nixpal.net', 'nixpal.org', 'nixpal.eu'], array_column($results, 'fqdn'), 'the typed TLD first, then by staff order; .io has no price');
        $this->assertSame([DomainSearch::TAKEN, DomainSearch::FREE, DomainSearch::FREE, DomainSearch::FREE], array_column($results, 'status'));
        $this->assertSame([null, null, '22.58', null], array_column($results, 'renew'), '«then X a year» only where the renewal differs');
        $this->assertSame('free', $results[3]['status'], '.eu without a registrar connection: RDAP says it is not registered');

        session(['currency' => 'EUR']);
        Livewire::test(Search::class)->set('query', 'nixpal')->call('search')->assertSee('nixpal.com')->assertSee(__('domains.shop.taken'))->assertSee(route('domain.whois', ['domain' => 'nixpal.com']), false);
    }

    public function test_what_is_not_a_name_is_said_and_searches_are_limited_per_address(): void
    {
        $this->fake();
        session(['currency' => 'USD']);
        Livewire::test(Search::class)->set('query', 'nixpal')->call('search')->assertSet('error', __('domains.shop.not_in_currency'));
        session(['currency' => 'EUR']);
        Livewire::test(Search::class)->set('query', '-bad-.com')->call('search')->assertSet('error', __('domains.shop.not_a_name'));

        for ($i = 0; $i < Search::LIMIT; $i++) {
            Livewire::test(Search::class)->set('query', 'nixpal')->call('search');
        }
        Livewire::test(Search::class)->set('query', 'nixpal')->call('search')->assertSet('error', __('domains.shop.too_many'))->assertSet('results', null);
    }

    public function test_whois_shows_what_the_registry_publishes_and_dot_gr_is_not_yet_supported(): void
    {
        $this->fake();

        Livewire::test(Whois::class)->set('captcha', 'solved')->set('query', 'NIXPAL.COM')->call('lookup')
            ->assertSee('Openprovider')->assertSee('ns1.myip.gr')->assertSee('2027-11-17');
        Livewire::test(Whois::class)->set('captcha', 'solved')->set('query', 'nixpal.eu')->call('lookup')->assertSee(__('domains.shop.whois_free'));
        Livewire::test(Whois::class)->set('captcha', 'solved')->set('query', 'nixpal.gr')->call('lookup')->assertSee(__('domains.shop.whois_unsupported'));
        Livewire::test(Whois::class)->set('captcha', 'solved')->set('query', 'nixpal')->call('lookup')->assertSet('error', __('domains.shop.not_a_full_name'));
    }

    public function test_the_price_list_page_and_its_json_api(): void
    {
        $this->get(route('domain.pricing'))->assertOk()->assertSee('.org')->assertDontSee('.io');

        $this->getJson(route('api.v1.domain-pricing'))->assertOk()
            ->assertJsonPath('currency', 'EUR')
            ->assertJsonPath('tlds.0.tld', 'com')
            ->assertJsonPath('tlds.2.years.1.renew', '22.58');
        $this->getJson(route('api.v1.domain-pricing', ['currency' => 'XYZ']))->assertUnprocessable();
    }

    public function test_the_pages_are_public(): void
    {
        $this->fake();
        session(['currency' => 'EUR']);
        $this->get(route('domain.search', ['domain' => 'nixpal']))->assertOk()->assertSee('nixpal.net');
        $this->get(route('domain.whois'))->assertOk();
    }
}
