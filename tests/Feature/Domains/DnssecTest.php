<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\RegistrarRefused;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\DnssecRecord;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use RuntimeException;
use Tests\TestCase;

/**
 * G8-4b: a domain's DNSSEC records at its registrar: DS records at the .gr registry (sent as the change from what it
 * has: extra ones removed, all when none is wanted, missing ones added; checked by it later), DNSKEYs at Openprovider
 * (the full set, read back). Read on request, kept on the domain for its page; each call logged.
 */
class DnssecTest extends TestCase
{
    use RefreshDatabase;

    private const DIGEST = '2BB183AF5F22588179A53B0A98631FAD1A292118F8D0CFE5F1C2C2F7C1D3A9B4';

    private const KEY = 'mdsswUyr3DPW132mOi8V9xESWE8jTo0dxCjjnopKl+GqJxpVXckHAeF+KkxLbxILfDLUT0rAK9iUzy1L53eKGQ==';

    private FakeTransport $transport;

    private User $user;

    /** @var list<array<string, mixed>> Openprovider's DNSKEYs */
    private array $opKeys = [];

    /** Openprovider answers a PUT but keeps nothing of it. */
    private bool $opIgnores = false;

    /** @var list<array<string, mixed>> what was PUT to Openprovider */
    private array $puts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Cache::flush();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        $this->transport = new FakeTransport;
        GrEppRegistrar::$transport = fn () => $this->transport;
        GrEppRegistrar::reset();
    }

    protected function tearDown(): void
    {
        GrEppRegistrar::$transport = null;
        GrEppRegistrar::reset();
        parent::tearDown();
    }

    private function grDomain(): Domain
    {
        $gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], ['password']);
        $tld = DomainTld::query()->create(['tld' => 'gr', 'is_active' => true, 'registrar_connection_id' => $gr->id, 'grace_days' => 15, 'redemption_days' => 0]);

        return Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => 'cdnet', 'tld' => 'gr', 'fqdn' => 'cdnet.gr',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $gr->id, 'status' => DomainStatus::Active]);
    }

    /** domain:info of ours, with these DS records and nameservers. */
    private function info(array $ds = [], array $nameservers = ['ns1.myip.gr']): string
    {
        $records = implode('', array_map(fn (array $d) => "<secDNS:dsData><secDNS:keyTag>{$d[0]}</secDNS:keyTag><secDNS:alg>{$d[1]}</secDNS:alg>"
            . "<secDNS:digestType>{$d[2]}</secDNS:digestType><secDNS:digest>{$d[3]}</secDNS:digest></secDNS:dsData>", $ds));
        $ns = $nameservers !== [] ? '<domain:ns>' . implode('', array_map(fn ($n) => "<domain:hostObj>{$n}</domain:hostObj>", $nameservers)) . '</domain:ns>' : '';

        return '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>ok</msg></result>'
            . '<resData><domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>cdnet.gr</domain:name><domain:roid>d1-gr</domain:roid>'
            . "<domain:status s=\"ok\"/>{$ns}<domain:clID>myip</domain:clID><domain:exDate>2028-08-16T23:59:59.9Z</domain:exDate></domain:infData></resData>"
            . ($records !== '' ? "<extension><secDNS:infData xmlns:secDNS=\"urn:ietf:params:xml:ns:secDNS-1.1\">{$records}</secDNS:infData></extension>" : '')
            . '<trID><clTRID>ECHO</clTRID><svTRID>s</svTRID></trID></response></epp>';
    }

    private function registry(string ...$answers): void
    {
        GrEppRegistrar::reset();
        $this->transport = new FakeTransport;
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml');
        foreach ($answers as $answer) {
            $this->transport->queue(new HttpResponse(200, $answer));
        }
    }

    /** @return list<string> the update bodies sent */
    private function updates(): array
    {
        return array_values(array_map(fn (array $r) => $r['body'], array_filter($this->transport->sent, fn (array $r) => str_contains($r['body'], 'secDNS:update'))));
    }

    private function staff(): Staff
    {
        return Staff::factory()->withPermissions(['admin.domains.viewAny', 'admin.domains.manage'])->create();
    }

    public function test_the_gr_registry_gets_the_change_from_what_it_has(): void
    {
        $domain = $this->grDomain();
        $service = app(DomainManagementService::class);
        $this->assertSame(DnssecRecord::DS, $service->dnssecKind($domain));
        $old = [1111, 8, 2, str_repeat('A', 64)];
        $new = DnssecRecord::ds(2371, 13, 2, strtolower(self::DIGEST));
        $pending = '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1001"><msg>pending</msg></result>'
            . '<trID><clTRID>ECHO</clTRID><svTRID>s</svTRID></trID></response></epp>';

        // The old one out, the new one in, in one update; then read again for the page.
        $this->registry($this->info([$old]), $pending, $this->info([[2371, 13, 2, self::DIGEST]]));
        $note = $service->setDnssec($domain, [$new], $this->staff());

        $this->assertCount(1, $this->updates());
        $body = $this->updates()[0];
        $this->assertLessThan(strpos($body, 'secDNS:add'), strpos($body, 'secDNS:rem'));
        $this->assertStringContainsString('<secDNS:keyTag>1111</secDNS:keyTag>', $body);
        $this->assertStringContainsString('<secDNS:digest>' . self::DIGEST . '</secDNS:digest>', $body);
        $this->assertStringContainsString('20 minutes', (string) $note);
        $this->assertSame([$new->toArray()], $domain->fresh()->meta['dnssec']['records']);
        $this->assertSame(1, DomainRegistrarLog::query()->where('action', 'dnssec_update')->where('status', 'ok')->count());

        // As it is already: nothing sent.
        $this->registry($this->info([[2371, 13, 2, self::DIGEST]]), $this->info([[2371, 13, 2, self::DIGEST]]));
        $service->setDnssec($domain->fresh(), [$new], $this->staff());
        $this->assertSame([], $this->updates());

        // None wanted: all removed at once.
        $this->registry($this->info([[2371, 13, 2, self::DIGEST], $old]), $pending, $this->info());
        $service->setDnssec($domain->fresh(), [], $this->staff());
        $this->assertStringContainsString('<secDNS:all>true</secDNS:all>', $this->updates()[0]);
        $this->assertSame([], $domain->fresh()->meta['dnssec']['records']);
    }

    public function test_what_the_gr_registry_would_refuse_is_never_sent(): void
    {
        $domain = $this->grDomain();
        $service = app(DomainManagementService::class);

        foreach ([
            'no nameservers' => [[$this->info([], [])], [DnssecRecord::ds(2371, 13, 2, self::DIGEST)]],
            'a DNSKEY' => [[$this->info()], [DnssecRecord::dnskey(257, 13, self::KEY)]],
            'an algorithm it does not take' => [[$this->info()], [DnssecRecord::ds(2371, 15, 2, self::DIGEST)]],
        ] as $why => [$answers, $records]) {
            $this->registry(...array_merge($answers, [$this->info()]));
            try {
                $service->setDnssec($domain->fresh(), $records, $this->staff());
                $this->fail("sent: {$why}");
            } catch (\Throwable) {
                $this->assertSame([], $this->updates(), $why);
            }
        }
    }

    private function opDomain(): Domain
    {
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $domain = Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'registrar_connection_id' => $op->id, 'registrar_domain_id' => '42', 'status' => DomainStatus::Active]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if ($request->method() === 'PUT') {
                $this->puts[] = $request->data();
                $this->opKeys = $this->opIgnores ? [] : ($request->data()['dnssec_keys'] ?? []);

                return Http::response(['code' => 0, 'data' => ['id' => 42]]);
            }

            return Http::response(['code' => 0, 'data' => ['id' => 42, 'domain' => ['name' => 'example', 'extension' => 'com'], 'status' => 'ACT',
                'dnssec' => $this->opKeys === [] ? 'unsigned' : 'signedDelegation', 'dnssec_keys' => $this->opKeys]]);
        });

        return $domain;
    }

    public function test_openprovider_gets_the_full_set_of_dnskeys_and_is_read_back(): void
    {
        $domain = $this->opDomain();
        $service = app(DomainManagementService::class);
        $this->assertSame(DnssecRecord::DNSKEY, $service->dnssecKind($domain));
        $key = DnssecRecord::dnskey(257, 13, chunk_split(self::KEY, 20, ' '));

        $service->setDnssec($domain, [$key], $this->staff());

        $this->assertSame([['flags' => 257, 'alg' => 13, 'protocol' => 3, 'pub_key' => self::KEY]], $this->puts[0]['dnssec_keys']);
        $this->assertSame([$key->toArray()], $domain->fresh()->meta['dnssec']['records']);

        $service->setDnssec($domain->fresh(), [], $this->staff());
        $this->assertSame([], $this->puts[1]['dnssec_keys']);
        $this->assertSame([], $domain->fresh()->meta['dnssec']['records']);

        // A DS is not what it takes: refused before anything is sent.
        $this->expectException(RegistrarRefused::class);
        $service->setDnssec($domain->fresh(), [DnssecRecord::ds(2371, 13, 2, self::DIGEST)], $this->staff());
    }

    public function test_openprovider_keeping_other_keys_is_said(): void
    {
        $domain = $this->opDomain();
        $this->opIgnores = true;

        try {
            app(DomainManagementService::class)->setDnssec($domain, [DnssecRecord::dnskey(257, 13, self::KEY)], $this->staff());
            $this->fail('not what it has');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('now has 0 DNSKEY', $e->getMessage());
        }
        $this->assertSame([], $domain->fresh()->meta['dnssec']['records'], 'read again: what it has');
    }

    public function test_staff_read_and_change_it_on_the_page(): void
    {
        $domain = $this->grDomain();
        $this->registry($this->info([[2371, 13, 2, self::DIGEST]]));

        $page = $this->livewireAs($this->staff())->test(ViewDomain::class, ['record' => $domain->getRouteKey()])
            ->assertActionHidden('editDnssec')
            ->callAction('readDnssec')->assertNotified();
        $this->assertSame('DS 2371 13 2 ' . self::DIGEST, DnssecRecord::fromArray($domain->fresh()->meta['dnssec']['records'][0])->label());

        $this->livewireAs($this->staff())->test(ViewDomain::class, ['record' => $domain->getRouteKey()])
            ->assertSee('DS 2371 13 2 ' . self::DIGEST)->assertActionVisible('editDnssec');

        $this->livewireAs(Staff::factory()->withPermissions(['admin.domains.viewAny'])->create())
            ->test(ViewDomain::class, ['record' => $domain->getRouteKey()])->assertActionHidden('readDnssec')->assertActionHidden('editDnssec');
    }
}
