<?php

namespace Tests\Feature\Domains;

use App\Livewire\DomainShop\Whois;
use App\Models\DomainRegistrarConnection;
use App\Services\Domains\Lookup\DomainLookup;
use App\Services\Domains\Lookup\GrWhois;
use App\Services\Domains\Registrars\GrEppRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * G6 of grepp: «who has it?» for .gr and .ελ through the registry's WHOIS API (no RDAP, no port 43 there): with the .gr
 * connection's WHOIS account, the name in Unicode, Basic auth, its answer as RDAP's; a 429 or a failure never cached.
 */
class GreppWhoisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function connection(string $mode = 'production', bool $whois = true): void
    {
        $gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => $mode]);
        $gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60']
            + ($whois ? ['whois_username' => 'who4', 'whois_password' => 'Whois-pw1'] : []), ['password', 'whois_password']);
    }

    public function test_a_gr_name_is_read_from_the_registrys_whois_api(): void
    {
        $this->connection();
        $sent = [];
        Http::fake(function (Request $request) use (&$sent) {
            $sent[] = $request;

            return Http::response((string) file_get_contents(base_path('packages/gr-epp/tests/fixtures/whois/domain-response.json')), 200);
        });

        $answer = app(DomainLookup::class)->whois('example1.gr');

        $this->assertSame('found', $answer['status']);
        $this->assertSame(['example1.gr', 'MYIP NETWORKS', '2029-03-01', ['ns1.example.com', 'ns2.example.com', 'ns3.example.com']],
            [$answer['record']->domain, $answer['record']->registrar, $answer['record']->expires, $answer['record']->nameservers]);
        $this->assertSame(GrWhois::PRODUCTION . '/api/v1/domain/example1.gr', $sent[0]->url());
        $this->assertSame('Basic ' . base64_encode('who4:Whois-pw1'), $sent[0]->header('Authorization')[0]);
        $this->assertSame('en', $sent[0]->header('Accept-Language')[0]);

        app(DomainLookup::class)->whois('example1.gr');
        $this->assertCount(1, $sent, 'cached an hour');
    }

    public function test_punycode_goes_as_unicode_and_what_it_answers(): void
    {
        $this->connection('sandbox');
        $urls = [];
        Http::fake(function (Request $request) use (&$urls) {
            $urls[] = rawurldecode($request->url());

            return str_contains($request->url(), 'limited') ? Http::response('', 429) : Http::response('{"error":404,"message":"not found"}', 404);
        });

        $this->assertSame('not_listed', app(DomainLookup::class)->whois('xn--hxajbheg2az3al.gr')['status']);
        $this->assertSame(GrWhois::SANDBOX . '/api/v1/domain/παράδειγμα.gr', $urls[0]);
        $this->assertSame('not_listed', app(DomainLookup::class)->whois('xn--hxajbheg2az3al.xn--qxam')['status'], '.ελ too');
        $this->assertStringEndsWith('/παράδειγμα.ελ', $urls[1]);

        $this->assertSame('error', app(DomainLookup::class)->whois('limited.gr')['status']);
        app(DomainLookup::class)->whois('limited.gr');
        app(DomainLookup::class)->whois('other.gr');
        $this->assertCount(3, $urls, 'a 429 is never cached, and pauses it a minute (review 4 of #197)');
    }

    public function test_without_its_account_gr_is_not_supported_and_the_page_shows_a_gr_name(): void
    {
        $this->connection(whois: false);
        $this->assertSame('unsupported', app(DomainLookup::class)->whois('example1.gr')['status']);

        DomainRegistrarConnection::query()->delete();
        $this->connection();
        Http::fake(fn () => Http::response((string) file_get_contents(base_path('packages/gr-epp/tests/fixtures/whois/domain-response.json')), 200));
        Livewire::withQueryParams(['domain' => 'example1.gr'])->test(Whois::class)
            ->assertSee('MYIP NETWORKS')->assertSee('ns1.example.com');
    }

    public function test_review_fixes_of_197(): void
    {
        // 1 and 2: the live connection first, never one that is «off».
        $this->connection('sandbox');
        $this->connection('production');
        $off = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'Off', 'is_active' => true, 'mode' => 'off']);
        $off->storeCredentials(['whois_username' => 'x', 'whois_password' => 'y'], ['whois_password']);
        $this->assertSame('production', GrWhois::connection()?->mode);
        DomainRegistrarConnection::query()->where('mode', '!=', 'off')->delete();
        $this->assertNull(GrWhois::connection());
        $this->assertSame('unsupported', app(DomainLookup::class)->whois('example1.gr')['status']);

        // 3: a missing nameserver under another .com.gr names that domain, not com.gr.
        $this->assertSame('example.com.gr', (fn (string $h) => self::registeredName($h))->call(new GrEppRegistrar, 'ns1.example.com.gr'));
        $this->assertSame('example.gr', (fn (string $h) => self::registeredName($h))->call(new GrEppRegistrar, 'ns1.example.gr'));
    }
}
