<?php

namespace Tests\Feature\Domains;

use App\Livewire\DomainShop\Whois;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Services\Captcha\Altcha;
use App\Services\Domains\Lookup\DomainLookup;
use App\Services\Domains\Lookup\GrWhois;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of #197 (G6 of grepp, the public whois through the .gr registry's WHOIS API): 10,000 calls a day
 * and 2 a second on our registrar account, from an anonymous page. The failing tests are findings; the passing ones
 * are regressions kept (URL injection, XSS, credentials, cache-key normalisation through the page).
 */
class GreppWhoisAdversarialTest extends TestCase
{
    use RefreshDatabase;

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
    }

    private function connection(string $mode = 'production'): void
    {
        $gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => $mode]);
        $gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60',
            'whois_username' => 'who4', 'whois_password' => 'Whois-pw1'], ['password', 'whois_password']);
    }

    private static function found(): string
    {
        return (string) file_get_contents(base_path('packages/gr-epp/tests/fixtures/whois/domain-response.json'));
    }

    /** ADV-1: a 429 (with X-RateLimit-Retry) is answered by hammering on: every visitor's next name goes out again. */
    public function test_adv1_after_a_429_no_request_goes_out_until_the_registry_says_retry(): void
    {
        $this->connection();
        $sent = 0;
        Http::fake(function () use (&$sent) {
            $sent++;

            return Http::response('{"error":429,"message":"Too many requests"}', 429, ['X-RateLimit-Limit' => '10000', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Retry' => '3600']);
        });

        $this->assertSame('error', app(DomainLookup::class)->whois('first.gr')['status']);
        app(DomainLookup::class)->whois('second.gr');
        app(DomainLookup::class)->whois('third.gr');

        $this->assertSame(1, $sent, 'the registry said «retry in an hour»: nothing more should be sent (the account may be blocked)');
    }

    /** ADV-2: no budget of our own; the registry's X-RateLimit-Remaining is ignored, so the day's 10,000 run out. */
    public function test_adv2_when_the_registry_says_nothing_remains_no_more_requests_go_out(): void
    {
        $this->connection();
        $sent = 0;
        Http::fake(function () use (&$sent) {
            $sent++;

            return Http::response(self::found(), 200, ['X-RateLimit-Limit' => '10000', 'X-RateLimit-Remaining' => '0']);
        });

        app(DomainLookup::class)->whois('example1.gr');
        app(DomainLookup::class)->whois('another-one.gr');
        app(DomainLookup::class)->whois('yet-another.gr');

        $this->assertSame(1, $sent, 'X-RateLimit-Remaining: 0, yet each new name (any visitor, any /64) goes to the registry');
    }

    /** ADV-3: a public web request waits up to 10 s on the cross-process lock (plus 0.6 s, plus a 15 s HTTP call inside). */
    public function test_adv3_a_busy_lock_does_not_hold_the_web_request_for_ten_seconds(): void
    {
        $this->connection();
        Http::fake(fn () => Http::response(self::found(), 200));
        $held = Cache::lock('grwhois-request', 30);
        $this->assertTrue($held->get());

        $started = microtime(true);
        $status = app(DomainLookup::class)->whois('example1.gr')['status'];
        $took = microtime(true) - $started;
        $held->release();

        $this->assertSame('error', $status);
        $this->assertLessThan(3.0, $took, "a visitor's request waited {$took} s for another's call to the registry");
    }

    /** ADV-4: a 400 (a name the registry will never accept) is not cached: the same name costs a call every time. */
    public function test_adv4_a_400_for_the_same_name_is_asked_once(): void
    {
        $this->connection();
        $sent = 0;
        Http::fake(function () use (&$sent) {
            $sent++;

            return Http::response('{"error":400,"message":"Invalid domain name"}', 400);
        });

        app(DomainLookup::class)->whois('x.gr');
        app(DomainLookup::class)->whois('x.gr');
        app(DomainLookup::class)->whois('x.gr');

        $this->assertSame(1, $sent, 'a definite 400 is re-sent on every visit');
    }

    /** ADV-5: any 404 (an HTML one from a proxy, a wrong path) is «not registered», cached an hour and shown as such. */
    public function test_adv5_a_404_that_is_not_the_registrys_answer_is_not_not_registered(): void
    {
        $this->connection();
        Http::fake(fn () => Http::response('<html><body>404 Not Found</body></html>', 404, ['Content-Type' => 'text/html']));

        $this->assertNotSame('not_found', app(DomainLookup::class)->whois('google.gr')['status'],
            'a 404 without the registry\'s {"error":404} body is shown as «This domain is not registered.» and cached');
    }

    /** ADV-6: a third-level .gr (com.gr, net.gr, org.gr, edu.gr, gov.gr) is cut to «com.gr» unless staff list it as a TLD. */
    public function test_adv6_a_com_gr_name_is_looked_up_as_itself(): void
    {
        $this->connection();
        DomainTld::query()->create(['tld' => 'gr']);
        $urls = [];
        Http::fake(function (Request $request) use (&$urls) {
            $urls[] = rawurldecode($request->url());

            return Http::response(self::found(), 200);
        });

        Livewire::withQueryParams(['domain' => 'shop.example.com.gr'])->test(Whois::class)->set('captcha', 'solved')->call('lookup')->assertSet('fqdn', 'example.com.gr');
        $this->assertSame([GrWhois::PRODUCTION . '/api/v1/domain/example.com.gr'], $urls);
    }

    /** ADV-7: with the .gr connection in sandbox mode the public page shows the test registry's data as the truth. */
    public function test_adv7_the_public_page_never_shows_the_test_registry(): void
    {
        $this->connection('sandbox');
        $urls = [];
        Http::fake(function (Request $request) use (&$urls) {
            $urls[] = $request->url();

            return Http::response('{"error":404,"message":"not found"}', 404);
        });

        $page = Livewire::withQueryParams(['domain' => 'google.gr'])->test(Whois::class)->set('captcha', 'solved')->call('lookup');
        $this->assertSame([], array_filter($urls, fn ($u) => str_starts_with($u, GrWhois::SANDBOX)), 'UAT answered a public whois');
        $page->assertDontSee(__('domains.shop.whois_free'));
    }

    // Regressions (pass): kept.

    public function test_names_crafted_for_another_url_stay_one_encoded_path_segment(): void
    {
        $this->connection();
        $urls = [];
        Http::fake(function (Request $request) use (&$urls) {
            $urls[] = $request->url();

            return Http::response('{"error":404,"message":"not found"}', 404);
        });

        foreach (['a/../../admin.gr', 'a?x=1#.gr', 'a%2f..%2fb.gr', 'evil.com/x.gr', 'a#b.gr', 'a b.gr'] as $name) {
            app(DomainLookup::class)->whois($name);
        }
        foreach ($urls as $url) {
            $this->assertStringStartsWith(GrWhois::PRODUCTION . '/api/v1/domain/', $url);
            $segment = substr($url, strlen(GrWhois::PRODUCTION . '/api/v1/domain/'));
            $this->assertDoesNotMatchRegularExpression('#[/?\#\s]#', $segment, $url);
            $this->assertSame('grwhois.ics.forth.gr', parse_url($url, PHP_URL_HOST));
        }
    }

    public function test_the_registrys_json_is_escaped_and_the_credentials_never_reach_the_page(): void
    {
        $this->connection();
        $evil = '<script>alert(1)</script>';
        Http::fake(fn () => Http::response(json_encode([[
            'name' => 'example1.gr', 'status' => [$evil], 'nameservers' => ['<img src=x onerror=alert(2)>'],
            'createDate' => $evil, 'registrar' => ['name' => $evil, 'url' => 'javascript:alert(3)', 'email' => 'x@y'],
        ]]), 200));

        $html = Livewire::withQueryParams(['domain' => 'example1.gr'])->test(Whois::class)->set('captcha', 'solved')->call('lookup')->html();
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('href="javascript:', $html); // registrar_url is data in the snapshot only
        foreach (['Whois-pw1', 'who4', base64_encode('who4:Whois-pw1'), 'Secret-pw1'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
    }

    public function test_a_failure_leaks_no_credentials(): void
    {
        $this->connection();
        Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://who4:Whois-pw1@grwhois.ics.forth.gr:800'));

        $html = Livewire::withQueryParams(['domain' => 'example1.gr'])->test(Whois::class)->set('captcha', 'solved')->call('lookup')->assertSee(__('domains.shop.whois_error'))->html();
        $this->assertStringNotContainsString('Whois-pw1', $html);
    }

    public function test_the_same_name_spelled_many_ways_on_the_page_is_one_call(): void
    {
        $this->connection();
        $sent = 0;
        Http::fake(function () use (&$sent) {
            $sent++;

            return Http::response('{"error":404,"message":"not found"}', 404);
        });

        $nfd = \Normalizer::normalize('παράδειγμα.gr', \Normalizer::FORM_D);
        $page = Livewire::withQueryParams(['domain' => 'παράδειγμα.gr'])->test(Whois::class)->set('captcha', 'solved')->call('lookup');
        foreach (['ΠΑΡΆΔΕΙΓΜΑ.GR', 'xn--hxajbheg2az3al.gr', 'XN--HXAJBHEG2AZ3AL.GR.', 'www.παράδειγμα.gr', 'https://mail.παράδειγμα.gr/x?y#z', $nfd] as $spelling) {
            $page->set('query', $spelling)->call('lookup')->assertSet('fqdn', 'xn--hxajbheg2az3al.gr');
        }

        $this->assertSame(1, $sent, 'case, Unicode or punycode, NFC/NFD, a trailing dot, a subdomain: one cache key');
    }
}
