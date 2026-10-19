<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\DnssecRecord;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * Adversarial review of PR #210 (G8-4b, DNSSEC at the registrar). Each test is a defect found: it fails on the PR as
 * pushed and passes once fixed. A wrong DS/DNSKEY breaks the domain for validating resolvers; a lost one unsigns it.
 */
class Pr210AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const DIGEST = '2BB183AF5F22588179A53B0A98631FAD1A292118F8D0CFE5F1C2C2F7C1D3A9B4';

    private const DIGEST_B = 'C0FFEE00C0FFEE00C0FFEE00C0FFEE00C0FFEE00C0FFEE00C0FFEE00C0FFEE00';

    private const KEY = 'mdsswUyr3DPW132mOi8V9xESWE8jTo0dxCjjnopKl+GqJxpVXckHAeF+KkxLbxILfDLUT0rAK9iUzy1L53eKGQ==';

    private FakeTransport $transport;

    private User $user;

    /** @var list<array<string, mixed>> */
    private array $opKeys = [];

    /** @var list<array<string, mixed>> */
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

    private function info(array $ds = []): string
    {
        $records = implode('', array_map(fn (array $d) => "<secDNS:dsData><secDNS:keyTag>{$d[0]}</secDNS:keyTag><secDNS:alg>{$d[1]}</secDNS:alg>"
            . "<secDNS:digestType>{$d[2]}</secDNS:digestType><secDNS:digest>{$d[3]}</secDNS:digest></secDNS:dsData>", $ds));

        return '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>ok</msg></result>'
            . '<resData><domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>cdnet.gr</domain:name><domain:roid>d1-gr</domain:roid>'
            . '<domain:status s="ok"/><domain:ns><domain:hostObj>ns1.myip.gr</domain:hostObj></domain:ns><domain:clID>myip</domain:clID>'
            . '<domain:exDate>2028-08-16T23:59:59.9Z</domain:exDate></domain:infData></resData>'
            . ($records !== '' ? "<extension><secDNS:infData xmlns:secDNS=\"urn:ietf:params:xml:ns:secDNS-1.1\">{$records}</secDNS:infData></extension>" : '')
            . '<trID><clTRID>ECHO</clTRID><svTRID>s</svTRID></trID></response></epp>';
    }

    private function pending(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1001"><msg>pending</msg></result>'
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

    /** @return list<string> */
    private function updates(): array
    {
        return array_values(array_map(fn (array $r) => $r['body'], array_filter($this->transport->sent, fn (array $r) => str_contains($r['body'], 'secDNS:update'))));
    }

    private function staff(): Staff
    {
        return Staff::factory()->withPermissions(['admin.domains.viewAny', 'admin.domains.manage'])->create();
    }

    private function page()
    {
        return fn (Domain $domain) => $this->livewireAs($this->staff())->test(ViewDomain::class, ['record' => $domain->getRouteKey()]);
    }

    /**
     * ADV-1 (high). The form is filled from meta.dnssec, read whenever (an hour, a month ago), and sent as «the full set»:
     * the registrar's current set is diffed against it and whatever is extra removed. A DS added since the read (by
     * another staff member at the same time, at the registry's panel, by the customer's DNS host) is removed by a submit
     * that never showed it, even an unchanged one: the domain loses its new key (unsigned or broken mid-rollover).
     */
    public function test_adv1_an_unchanged_edit_never_removes_a_ds_added_since_the_read(): void
    {
        $domain = $this->grDomain();
        $a = [2371, 13, 2, self::DIGEST];
        $b = [4444, 13, 2, self::DIGEST_B];

        $this->registry($this->info([$a]));
        app(DomainManagementService::class)->readDnssec($domain, $this->staff());

        // Since the read, B is added at the registry; staff open «Change DNSSEC» (filled with A only) and submit it.
        $this->registry($this->info([$a, $b]), $this->pending(), $this->info([$a]));
        $undo = Repeater::fake();
        ($this->page())($domain->fresh())->callAction('editDnssec');
        $undo();

        foreach ($this->updates() as $body) {
            $this->assertStringNotContainsString('<secDNS:keyTag>4444</secDNS:keyTag>', $body, 'a DS the form never showed was removed');
            $this->assertStringNotContainsString('<secDNS:all>', $body);
        }
    }

    /**
     * ADV-2 (medium). The page's algorithm list (8, 10, 13, 14, 15, 16, 5, 7) is not the .gr registry's (3, 5, 6, 7, 8, 10,
     * 12, 13, 14): a DS of algorithm 3, 6 or 12 that the registry has (and takes) cannot be kept through the form: an
     * unchanged edit is refused by the form, so the only way to change anything else is to drop it (unsigning/breaking).
     */
    public function test_adv2_a_ds_of_an_algorithm_the_registry_takes_survives_an_unchanged_edit(): void
    {
        $domain = $this->grDomain();
        $legacy = [3000, 6, 2, self::DIGEST];

        $this->registry($this->info([$legacy]));
        app(DomainManagementService::class)->readDnssec($domain, $this->staff());

        $this->registry($this->info([$legacy]), $this->info([$legacy]));
        $undo = Repeater::fake();
        ($this->page())($domain->fresh())->callAction('editDnssec')->assertHasNoFormErrors()
            ->assertNotified(__('DNSSEC sent to the registrar.'));
        $undo();
        $this->assertSame([], $this->updates(), 'as it is already: nothing sent');
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
                $this->opKeys = $request->data()['dnssec_keys'] ?? [];

                return Http::response(['code' => 0, 'data' => ['id' => 42]]);
            }

            return Http::response(['code' => 0, 'data' => ['id' => 42, 'domain' => ['name' => 'example', 'extension' => 'com'], 'status' => 'ACT',
                'dnssec' => $this->opKeys === [] ? 'unsigned' : 'signedDelegation', 'dnssec_keys' => $this->opKeys]]);
        });

        return $domain;
    }

    /**
     * ADV-3 (medium). meta.dnssec does not say which registrar (connection) it was read from. After the domain moves to
     * another connection (a .gr domain moved to Openprovider, or a TLD's connection changed), the page still shows the old
     * registrar's DS records as «what the registrar has» and offers «Change DNSSEC» filled with them, in the other kind's
     * form: the DS rows cannot be submitted as DNSKEYs, staff delete them as stale, and the submit (none) unsigns the
     * Openprovider domain's real DNSKEYs, which were never read or shown.
     */
    public function test_adv3_records_read_from_another_registrar_are_not_offered_for_edit(): void
    {
        $domain = $this->opDomain();
        $this->opKeys = [['flags' => 257, 'alg' => 13, 'protocol' => 3, 'pub_key' => self::KEY]];
        $domain->forceFill(['meta' => ['dnssec' => ['read_at' => now()->toIso8601String(),
            'records' => [DnssecRecord::ds(2371, 13, 2, self::DIGEST)->toArray()]]]])->save();

        ($this->page())($domain->fresh())->assertActionHidden('editDnssec');
    }

    /**
     * ADV-4 (low). A DNSKEY's protocol is always 3 (RFC 4034 §2.1.2) and its flags 256 or 257 here; the form's protocol
     * is a free integer and OpenproviderRegistrar::setDnssec checks neither: a protocol 4 key is PUT as the full set
     * (replacing the good keys) instead of being refused before anything is sent.
     */
    public function test_adv4_openprovider_a_dnskey_of_another_protocol_is_refused_before_the_put(): void
    {
        $domain = $this->opDomain();

        try {
            app(DomainManagementService::class)->setDnssec($domain, [DnssecRecord::dnskey(257, 13, self::KEY, 4)], $this->staff());
        } catch (\Throwable) {
            // refused: what is wanted
        }
        $this->assertSame([], $this->puts, 'a protocol 4 DNSKEY was sent to Openprovider');
    }
}
