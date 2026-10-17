<?php

namespace Tests\Feature\Domains;

use App\Livewire\DomainShop\Search;
use App\Livewire\DomainShop\Whois;
use App\Models\Currency;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
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
 * Adversarial review of PR #178 (D8a: public domain search, whois, price list and its JSON API), as an anonymous
 * visitor. Failing tests prove a problem; passing ones document an attack that is defended.
 */
class DomainShopAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const BOOTSTRAP = ['services' => [[['com', 'net'], ['https://rdap.example/']], [['eu'], ['https://rdap.eu.example/']]]];

    private DomainRegistrarConnection $op;

    /** @var list<array{0:string,1:string}> port 43 queries made (host, query) */
    public array $socketQueries = [];

    /** @var array<string, string|null> host => answer for the fake port 43 */
    public array $socketAnswers = [];

    protected function tearDown(): void
    {
        LaravelTransport::$resolve = null;
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        // Hosts resolve without DNS here: the fake registries are public, «internal» ones are not.
        LaravelTransport::$resolve = fn (string $host) => str_contains($host, 'internal') ? ['10.0.0.5'] : ['93.184.216.34'];
        $test = $this;
        // Never a real port 43 query from a test: recorded, answered from $socketAnswers.
        $this->app->bind(SocketConnector::class, fn () => new class($test) implements SocketConnector
        {
            public function __construct(private $test) {}

            public function query($host, $query, $timeout = 8, $max = 65536)
            {
                $this->test->socketQueries[] = [$host, $query];

                return $this->test->socketAnswers[$host] ?? null;
            }
        });
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        config(['settings.default_currency' => 'EUR', 'settings.trusted_proxies' => []]);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        foreach ([['com', 1, '23.33', '23.33'], ['net', 2, '26.43', '26.43'], ['org', 3, '15.58', '22.58'], ['eu', 4, '9.00', '9.00']] as [$tld, $sort, $register, $renew]) {
            $model = DomainTld::query()->create(['tld' => $tld, 'is_active' => true, 'sort' => $sort, 'registrar_connection_id' => $tld === 'eu' ? null : $this->op->id]);
            foreach (['register' => $register, 'renew' => $renew, 'transfer' => $renew] as $operation => $price) {
                DomainTldPrice::query()->create(['domain_tld_id' => $model->id, 'operation' => $operation, 'years' => 1, 'currency_code' => 'EUR', 'price' => $price]);
            }
        }
        DomainTld::query()->create(['tld' => 'io', 'is_active' => true, 'sort' => 5]); // no price: not sold
        $inactive = DomainTld::query()->create(['tld' => 'biz', 'is_active' => false, 'sort' => 6]);
        DomainTldPrice::query()->create(['domain_tld_id' => $inactive->id, 'operation' => 'register', 'years' => 1, 'currency_code' => 'EUR', 'price' => '1.00']);
    }

    /**
     * Openprovider: nixpal.com taken, the rest free (or $registrar decides); RDAP knows .com and .eu.
     *
     * @param  (callable(string): mixed)|null  $registrar  fqdn => Http response, or null for the default
     * @param  (callable(string): mixed)|null  $rdap  url => Http response, or null for the default
     */
    private function fake(?callable $registrar = null, ?callable $rdap = null): void
    {
        Http::fake(function (Request $request) use ($registrar, $rdap) {
            $url = $request->url();
            if (str_contains($url, '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if (str_contains($url, '/domains/check')) {
                $fqdn = ($request->data()['domains'][0]['name'] ?? '') . '.' . ($request->data()['domains'][0]['extension'] ?? '');
                if ($registrar !== null && ($response = $registrar($fqdn)) !== null) {
                    return $response;
                }

                // Every name asked, as the real bulk check answers (review R1 of #178: one request per search).
                return Http::response(['data' => ['results' => array_map(fn ($d) => ['domain' => $d['name'] . '.' . $d['extension'],
                    'status' => $d['name'] . '.' . $d['extension'] === 'nixpal.com' ? 'active' : 'free'], $request->data()['domains'])]]);
            }
            if (str_contains($url, 'data.iana.org')) {
                return Http::response(self::BOOTSTRAP);
            }
            if ($rdap !== null && ($response = $rdap($url)) !== null) {
                return $response;
            }
            if (str_contains($url, 'rdap.example/domain/nixpal.com')) {
                return Http::response($this->record());
            }

            return Http::response([], 404);
        });
    }

    private function record(array $extra = []): array
    {
        return array_replace(['objectClassName' => 'domain', 'ldhName' => 'nixpal.com', 'status' => ['client transfer prohibited'],
            'events' => [['eventAction' => 'registration', 'eventDate' => '2012-11-17T18:08:48Z'], ['eventAction' => 'expiration', 'eventDate' => '2027-11-17T18:08:48Z']],
            'nameservers' => [['ldhName' => 'NS1.MYIP.GR']],
            'entities' => [['roles' => ['registrar'], 'vcardArray' => ['vcard', [['fn', [], 'text', 'Openprovider']]]]]], $extra);
    }

    private function checkCalls(): int
    {
        return count(Http::recorded(fn (Request $r) => str_contains($r->url(), '/domains/check')));
    }

    // ---------------------------------------------------------------- rate limits

    /** ADV-1: an IPv6 visitor has a /64 (2^64 addresses); the limit per full address does not limit them at all. */
    public function test_adv1_ipv6_addresses_of_one_slash64_share_the_search_limit(): void
    {
        $this->fake();
        for ($i = 1; $i <= Search::LIMIT; $i++) {
            $this->withSession(['currency' => 'EUR'])->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2::' . dechex($i)])->get('/domain-search?domain=nixpal' . $i)->assertOk();
        }
        $this->withSession(['currency' => 'EUR'])->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2::ffff'])->get('/domain-search?domain=nixpalx')
            ->assertSee(__('domains.shop.too_many'));
    }

    /** ADV-2: with trusted proxies «*» (a setting staff can pick) X-Forwarded-For names any address: the limit is bypassed. */
    public function test_adv2_x_forwarded_for_does_not_bypass_the_limit_when_trusted_proxies_is_star(): void
    {
        $this->fake();
        config(['settings.trusted_proxies' => ['*']]);
        for ($i = 1; $i <= Search::LIMIT; $i++) {
            $this->withSession(['currency' => 'EUR'])->withHeaders(['X-Forwarded-For' => '198.51.100.' . $i])->get('/domain-search?domain=nixpal' . $i)->assertOk();
        }
        $this->withSession(['currency' => 'EUR'])->withHeaders(['X-Forwarded-For' => '203.0.113.9'])->get('/domain-search?domain=nixpalx')
            ->assertSee(__('domains.shop.too_many'));
    }

    /** Defended: without trusted proxies, X-Forwarded-For is ignored. */
    public function test_x_forwarded_for_is_ignored_without_trusted_proxies(): void
    {
        $this->fake();
        for ($i = 1; $i <= Search::LIMIT; $i++) {
            $this->withSession(['currency' => 'EUR'])->withHeaders(['X-Forwarded-For' => '198.51.100.' . $i])->get('/domain-search?domain=nixpal' . $i)->assertOk();
        }
        $this->withSession(['currency' => 'EUR'])->withHeaders(['X-Forwarded-For' => '203.0.113.9'])->get('/domain-search?domain=nixpalx')->assertSee(__('domains.shop.too_many'));
    }

    /** Defended: the GET page (?domain=), Livewire calls, repeated calls on one component and the whois share/keep limits. */
    public function test_get_page_and_livewire_calls_share_one_limit_and_repeated_calls_count(): void
    {
        $this->fake();
        for ($i = 0; $i < 10; $i++) {
            $this->get('/domain-search?domain=a' . $i)->assertOk();
        }
        $component = Livewire::test(Search::class);
        for ($i = 0; $i < Search::LIMIT - 10; $i++) {
            $component->set('query', 'b' . $i)->call('search');
        }
        $component->set('query', 'c')->call('search')->assertSet('error', __('domains.shop.too_many'))->assertSet('results', null);
        $this->get('/domain-search?domain=d')->assertSee(__('domains.shop.too_many'));

        $whois = Livewire::test(Whois::class);
        for ($i = 0; $i < Whois::LIMIT; $i++) {
            $whois->set('query', "n{$i}.com")->call('lookup');
        }
        $whois->set('query', 'nixpal.com')->call('lookup')->assertSet('error', __('domains.shop.too_many'))->assertSet('answer', null);
    }

    /** Defended: case, scheme, www, path and trailing-dot variants are one cache entry: one registrar check each name. */
    public function test_variants_of_one_name_do_not_bust_the_availability_cache(): void
    {
        $this->fake();
        $search = app(DomainSearch::class);
        $search->search('nixpal.com', 'EUR');
        $before = $this->checkCalls();
        foreach (['NIXPAL.COM', 'https://www.Nixpal.com/x?y=1', 'nixpal.com.', ' nixpal .com', 'http://nixpal.com:8080'] as $variant) {
            $search->search($variant, 'EUR');
        }
        $this->assertSame($before, $this->checkCalls());
    }

    /**
     * ADV-3: one search is up to 7 registrar calls (one per TLD), so the 30/10′ limit is ~210 Openprovider checks per
     * address, with no overall cap. The check API takes many names in one request.
     */
    public function test_adv3_one_search_is_one_registrar_call(): void
    {
        $this->fake();
        app(DomainSearch::class)->search('fresh-name', 'EUR');
        $this->assertLessThanOrEqual(1, $this->checkCalls(), 'com, net and org were checked one request each');
    }

    // ---------------------------------------------------------------- answers that are wrong / poisoned

    /** ADV-4: the registrar cannot answer (outage, rate limit on us): RDAP «not found» shows a premium/reserved name as free at the standard price. */
    public function test_adv4_a_registrar_failure_does_not_offer_the_name_as_free_at_the_standard_price(): void
    {
        $this->fake(registrar: fn (string $fqdn) => $fqdn === 'cars.com' ? Http::response(['desc' => 'Too many requests'], 429) : null,
            rdap: fn (string $url) => str_contains($url, 'domain/cars.com') ? Http::response([], 404) : null);

        $row = collect(app(DomainSearch::class)->search('cars.com', 'EUR'))->firstWhere('fqdn', 'cars.com');

        $this->assertNotSame(DomainSearch::FREE, $row['status'], 'the TLD has a registrar that could not say whether it is premium: unknown, not «free, 23.33»');
    }

    /** Defended: a premium answer from the registrar is never priced. */
    public function test_premium_from_the_registrar_is_not_priced(): void
    {
        $this->fake(registrar: fn (string $fqdn) => $fqdn === 'cars.com' ? Http::response(['data' => ['results' => [['status' => 'free', 'is_premium' => true]]]]) : null);
        session(['currency' => 'EUR']);
        Livewire::test(Search::class)->set('query', 'cars.com')->call('search')
            ->assertSee(__('domains.shop.premium'))->assertDontSee(__('domains.shop.per_year', ['price' => '23,33 EUR']));
    }

    /** ADV-5: a TLD without IDN (allow_idn false, the default) is still offered with an IDN name, free and priced. */
    public function test_adv5_an_idn_name_is_not_offered_for_a_tld_that_does_not_allow_idn(): void
    {
        $this->fake();
        $results = app(DomainSearch::class)->search('καφές.com', 'EUR');

        // No TLD here takes IDN: not a name we search (null), nothing offered either way.
        $offered = collect($results ?? [])->where('status', DomainSearch::FREE)->pluck('fqdn')->all();
        $this->assertSame([], $offered, 'xn--… names offered as free for TLDs with allow_idn = false');
    }

    /** ADV-6: whois of a sub-domain asks the registry for it, gets «not found», and says the name is not registered. */
    public function test_adv6_whois_of_a_sub_domain_does_not_say_it_is_unregistered(): void
    {
        $this->fake();
        Livewire::test(Whois::class)->set('query', 'mail.nixpal.com')->call('lookup')
            ->assertDontSee(__('domains.shop.whois_free'));
    }

    /** ADV-7: a transient failure (RDAP 503, port 43 / IANA unreachable) is cached for an hour as the answer. */
    public function test_adv7_a_failed_whois_is_not_cached_as_the_answer_for_an_hour(): void
    {
        $down = true;
        $this->fake(rdap: function (string $url) use (&$down) {
            return $down && str_contains($url, 'rdap.example/domain/nixpal.com') ? Http::response('busy', 503) : null;
        });
        Livewire::test(Whois::class)->set('query', 'nixpal.com')->call('lookup')
            ->assertDontSee('Openprovider');

        $down = false;
        $this->travel(5)->minutes();
        Livewire::test(Whois::class)->set('query', 'nixpal.com')->call('lookup')->assertSee('Openprovider');
    }

    /** ADV-8: a .ελ name (punycode xn--qxam) escapes the «not supported» list and goes out to IANA/registries. */
    public function test_adv8_dot_el_in_greek_is_unsupported_without_any_query(): void
    {
        $this->fake();
        Livewire::test(Whois::class)->set('query', 'παράδειγμα.ελ')->call('lookup')
            ->assertSet('answer.status', 'unsupported');
        $this->assertSame([], $this->socketQueries, 'port 43 queried for a TLD listed as unsupported');
        $this->assertCount(0, Http::recorded(fn (Request $r) => str_contains($r->url(), 'xn--qxam')));
    }

    /** Defended: availability is per name, not per visitor or currency; the price list cache is per currency. */
    public function test_cached_answers_are_not_shown_in_another_currency(): void
    {
        $com = DomainTld::query()->where('tld', 'com')->first();
        DomainTldPrice::query()->create(['domain_tld_id' => $com->id, 'operation' => 'register', 'years' => 1, 'currency_code' => 'USD', 'price' => '30.00']);
        $this->withSession(['currency' => 'EUR'])->get('/domain-pricing')->assertSee('.org<!--', false);
        $this->withSession(['currency' => 'USD'])->get('/domain-pricing')->assertSee('.com<!--', false)->assertDontSee('.org<!--', false)->assertDontSee('23,33');

        $this->getJson('/api/v1/domain-pricing?currency=usd')->assertOk()->assertJsonPath('currency', 'USD')
            ->assertJsonCount(1, 'tlds')->assertJsonPath('tlds.0.years.1.register', '30.00')->assertJsonPath('tlds.0.years.1.renew', null);
        $json = $this->getJson('/api/v1/domain-pricing')->assertOk()->json();
        $this->assertSame(['com', 'net', 'org', 'eu'], array_column($json['tlds'], 'tld'), 'inactive .biz and unpriced .io left out');
    }

    // ---------------------------------------------------------------- input handling

    /** ADV-9: ?currency[]=EUR on the JSON API is a 500 (array to string), not a 422. */
    public function test_adv9_json_api_currency_as_an_array_is_a_422(): void
    {
        $this->getJson('/api/v1/domain-pricing?currency[]=EUR')->assertUnprocessable();
    }

    /** Defended: ?domain[]=x on the search and whois pages is not a 500. */
    public function test_domain_as_an_array_in_the_url_is_not_a_500(): void
    {
        $this->fake();
        $this->get('/domain-search?domain[]=nixpal')->assertOk();
        $this->get('/whois?domain[]=nixpal.com')->assertOk();
    }

    /** Defended: very long input, control characters, CRLF (port 43 injection), IDN tricks. */
    public function test_long_and_odd_input_is_bounded_and_never_reaches_a_server(): void
    {
        $this->fake();
        Livewire::test(Search::class)->set('query', str_repeat('a', 10000))->call('search')->assertSet('error', __('domains.shop.not_a_name'));
        Livewire::test(Search::class)->set('query', str_repeat('ab.', 2000) . 'com')->call('search');
        Livewire::test(Whois::class)->set('query', str_repeat('a', 10000) . '.com')->call('lookup')->assertSet('error', __('domains.shop.not_a_full_name'));
        Livewire::test(Whois::class)->set('query', "nixpal.com\r\nhelp")->call('lookup');
        Livewire::test(Whois::class)->set('query', "nixpal.com\x00.evil")->call('lookup');
        Livewire::test(Whois::class)->set('query', 'ex ample.com')->call('lookup')->assertSet('error', __('domains.shop.not_a_full_name'));
        foreach ($this->socketQueries as [$host, $query]) {
            $this->assertDoesNotMatchRegularExpression('/[\r\n\x00 ]/', $query);
        }
        foreach (Http::recorded() as [$request]) {
            $this->assertDoesNotMatchRegularExpression('/[\r\n\x00]/', urldecode($request->url()));
        }
    }

    // ---------------------------------------------------------------- XSS, leaks, SSRF

    /** Defended: registry text, RDAP fields and the visitor's query are escaped. */
    public function test_registry_answers_and_the_query_are_escaped(): void
    {
        $this->fake(rdap: fn (string $url) => str_contains($url, 'domain/nixpal.com') ? Http::response($this->record([
            'status' => ['<img src=x onerror=alert(1)>'],
            'nameservers' => [['ldhName' => '"><script>alert(2)</script>']],
            'entities' => [['roles' => ['registrar'], 'vcardArray' => ['vcard', [['fn', [], 'text', '<script>alert(3)</script>']]]]],
        ])) : null);
        Livewire::test(Whois::class)->set('query', 'nixpal.com')->call('lookup')
            ->assertDontSee('<script>alert', false)->assertDontSee('<img src=x', false)->assertSee('&lt;script&gt;alert(3)', false);

        // Port 43 text (a TLD without RDAP).
        $this->socketAnswers = ['whois.iana.org' => "whois: whois.nic.xyz\n", 'whois.nic.xyz' => "Domain: a.xyz\nRegistrar: <script>alert(4)</script>\nRegistrant Email: owner@a.xyz\nRegistrant Phone: +30.2100000000\n"];
        Livewire::test(Whois::class)->set('query', 'a.xyz')->call('lookup')
            ->assertDontSee('<script>alert(4)', false)->assertDontSee('owner@a.xyz')->assertDontSee('2100000000');

        Livewire::test(Search::class)->set('query', '<script>alert(5)</script>')->call('search')->assertDontSee('<script>alert(5)', false);
        $this->get('/domain-search?domain=' . urlencode('"><script>alert(6)</script>'))->assertOk()->assertDontSee('<script>alert(6)', false);
        $this->get('/whois?domain=' . urlencode('"><script>alert(7)</script>.com'))->assertOk()->assertDontSee('<script>alert(7)', false);
    }

    /** Defended: a registrar failure is not shown (its message, credentials, the exception). */
    public function test_registrar_errors_and_credentials_do_not_reach_the_visitor(): void
    {
        $this->fake(registrar: fn () => Http::response(['desc' => 'Invalid login for u/Op-Secret-Pa55 at 10.0.0.5'], 500));
        session(['currency' => 'EUR']);
        $html = Livewire::test(Search::class)->set('query', 'nixpal')->call('search')->html();
        foreach (['Op-Secret-Pa55', '10.0.0.5', 'Invalid login', 'RuntimeException', 'Openprovider'] as $leak) {
            $this->assertStringNotContainsString($leak, $html);
        }
        $this->assertStringNotContainsString('Op-Secret-Pa55', $this->get('/domain-search?domain=nixpal')->getContent());
    }

    /** ADV-10: RDAP «related» links are followed to any https host, internal ones included (SSRF from a registry/registrar answer). */
    public function test_adv10_rdap_related_link_to_an_internal_host_is_not_fetched(): void
    {
        $this->fake(rdap: fn (string $url) => str_contains($url, 'rdap.example/domain/thin.com') ? Http::response($this->record([
            'ldhName' => 'thin.com', 'entities' => [],
            'links' => [['rel' => 'related', 'type' => 'application/rdap+json', 'href' => 'https://169.254.169.254/latest/meta-data/']],
        ])) : null);
        Livewire::test(Whois::class)->set('query', 'thin.com')->call('lookup');

        $this->assertCount(0, Http::recorded(fn (Request $r) => str_contains($r->url(), '169.254.169.254')), 'link-local metadata address fetched');
    }

    /** Defended: the RDAP bootstrap's non-https servers are not used, and port 43 hosts come from IANA only. */
    public function test_rdap_servers_are_https_only_and_port43_host_is_not_from_the_visitor(): void
    {
        Http::fake([
            'data.iana.org/*' => Http::response(['services' => [[['xyz'], ['http://127.0.0.1:8080/']]]]),
            '*' => Http::response([], 404),
        ]);
        $this->socketAnswers = ['whois.iana.org' => "whois: whois.nic.xyz\n", 'whois.nic.xyz' => "Domain: a.xyz\n"];
        Livewire::test(Whois::class)->set('query', 'a.xyz')->call('lookup');
        Livewire::test(Whois::class)->set('query', 'a.xyz:43@127.0.0.1')->call('lookup');

        $this->assertCount(0, Http::recorded(fn (Request $r) => str_contains($r->url(), '127.0.0.1')));
        $this->assertSame(['whois.iana.org', 'whois.nic.xyz'], array_values(array_unique(array_column($this->socketQueries, 0))));
    }
}
