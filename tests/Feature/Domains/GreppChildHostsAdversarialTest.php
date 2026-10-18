<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #196 (child nameservers at the .gr registry), written without the author's context. Each test is
 * one finding (ADV-n); a failing one is open.
 */
class GreppChildHostsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private FakeTransport $transport;

    private DomainRegistrarConnection $gr;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $this->gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], ['password']);
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

    private function credentials(): DomainRegistrarCredentials
    {
        return new DomainRegistrarCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], sandbox: false);
    }

    private function answer(int $code, string $resData = ''): void
    {
        $this->transport->queue(new HttpResponse(200, '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
            . "<result code=\"{$code}\"><msg>m</msg></result>" . ($resData !== '' ? "<resData>{$resData}</resData>" : '')
            . '<trID><clTRID>ECHO</clTRID><svTRID>s</svTRID></trID></response></epp>'));
    }

    private function loggedIn(): void
    {
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml');
    }

    private function domain(): Domain
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['is_active' => true, 'registrar_connection_id' => $this->gr->id, 'grace_days' => 15, 'redemption_days' => 0]);

        return Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => 'example1', 'tld' => 'gr', 'fqdn' => 'example1.gr',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->gr->id, 'status' => DomainStatus::Active, 'expires_at' => '2029-03-01',
            'currency_code' => 'EUR', 'years' => 2]);
    }

    /** @return list<string> */
    private function bodies(string $kind): array
    {
        return array_values(array_filter(array_column($this->transport->sent, 'body'), fn (string $b) => str_contains($b, "<host:{$kind}")));
    }

    /**
     * @param  list<string>  $hosts
     * @param  list<string>  $statuses
     * @param  list<string>  $ns
     */
    private function domainInfoWith(array $hosts, array $statuses = ['ok'], array $ns = ['ns1.example1.gr', 'ns.example.com']): void
    {
        $this->answer(1000, '<domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>example1.gr</domain:name><domain:roid>r-gr</domain:roid>'
            . implode('', array_map(fn ($s) => "<domain:status s=\"{$s}\"/>", $statuses))
            . '<domain:registrant>c60_x</domain:registrant><domain:ns>' . implode('', array_map(fn ($h) => "<domain:hostObj>{$h}</domain:hostObj>", $ns)) . '</domain:ns>'
            . implode('', array_map(fn ($h) => "<domain:host>{$h}</domain:host>", $hosts))
            . '<domain:clID>myip</domain:clID><domain:crDate>2005-03-01T10:00:00.0Z</domain:crDate><domain:exDate>2029-03-01T23:59:59.0Z</domain:exDate></domain:infData>');
    }

    /** @param  list<string>  $addresses */
    private function hostInfoBy(string $name, array $addresses, string $clID, bool $linked = false): void
    {
        $this->answer(1000, '<host:infData xmlns:host="urn:ietf:params:xml:ns:host-1.0"><host:name>' . $name . '</host:name><host:roid>h-gr</host:roid><host:status s="ok"/>'
            . ($linked ? '<host:status s="linked"/>' : '')
            . implode('', array_map(fn ($ip) => '<host:addr ip="' . (str_contains($ip, ':') ? 'v6' : 'v4') . "\">{$ip}</host:addr>", $addresses))
            . "<host:clID>{$clID}</host:clID><host:crID>{$clID}</host:crID><host:crDate>2004-08-17T10:00:00.0Z</host:crDate></host:infData>");
    }

    /**
     * Whether the call failed (any exception), so a test never relies on $this->fail() inside a catch.
     *
     * @phpstan-impure
     */
    private function refused(\Closure $call): bool
    {
        try {
            $call();
        } catch (Throwable) {
            return true;
        }

        return false;
    }

    /**
     * ADV-1 (medium): glue only «public» IPs, yet multicast (224/4, ff00::/8), documentation (192.0.2/24, 198.51.100/24,
     * 203.0.113/24, 2001:db8::/32), shared/CGNAT (100.64/10), benchmarking (198.18/15) and site-local fec0::/10 pass
     * FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE: none is ever a nameserver's. Refused before anything is sent.
     */
    public function test_adv1_multicast_documentation_and_shared_addresses_are_not_glue(): void
    {
        $domain = $this->domain();
        $passed = [];
        foreach (['224.0.0.1', '239.255.255.250', 'ff02::1', 'ff0e::1', '192.0.2.1', '198.51.100.7', '203.0.113.9', '2001:db8::53',
            '100.64.0.1', '198.18.0.1', 'fec0::1'] as $ip) {
            $this->transport->sent = [];
            if (!$this->refused(fn () => (new GrEppRegistrar)->createChildHost($domain, 'ns1', [$ip], $this->credentials()))
                || $this->transport->sent !== []) {
                $passed[] = $ip;
            }
        }
        $this->assertSame([], $passed, 'taken as glue (sent to the registry): ' . implode(', ', $passed));
    }

    /**
     * ADV-2 (low): a name «under» the domain only by its suffix: an empty label (.example1.gr, ns1..example1.gr), a label
     * with a space, «*», «_», a leading or trailing «-», or longer than 63, is sent (a login, domain:info) instead of being
     * refused here as every other rule is (Rules: «nothing sent»).
     */
    public function test_adv2_a_malformed_host_name_under_the_domain_is_refused_before_anything_is_sent(): void
    {
        $domain = $this->domain();
        $passed = [];
        foreach (['.example1.gr', '..example1.gr', 'ns1..example1.gr', 'ns 1.example1.gr', '*.example1.gr', '_ns.example1.gr', '-ns1.example1.gr',
            'ns1-.example1.gr', str_repeat('a', 64) . '.example1.gr'] as $name) {
            $this->transport->sent = [];
            if (!$this->refused(fn () => (new GrEppRegistrar)->createChildHost($domain, $name, ['84.54.49.6'], $this->credentials()))
                || $this->transport->sent !== []) {
                $passed[] = $name;
            }
        }
        $this->assertSame([], $passed, 'went to the registry: ' . implode(' | ', $passed));
    }

    /**
     * ADV-3 (medium): the delete trusts host:info's «linked» only. The domain:info read just before lists this very host as
     * one of the domain's own nameservers; if the registry does not flag it «linked» (no fixture shows that it does for
     * hosts), host:delete is sent for the domain's own nameserver (its delegation broken if the registry takes it).
     */
    public function test_adv3_the_domains_own_nameserver_is_never_deleted_even_if_not_flagged_linked(): void
    {
        $domain = $this->domain();
        $this->loggedIn();
        $this->domainInfoWith(['ns1.example1.gr'], ns: ['ns1.example1.gr', 'ns.example.com']);
        $this->hostInfoBy('ns1.example1.gr', ['84.54.49.6'], 'myip', linked: false);
        $this->answer(1000); // host:delete, if sent
        $this->refused(fn () => (new GrEppRegistrar)->deleteChildHost($domain, 'ns1', $this->credentials()));

        $this->assertSame([], $this->bodies('delete'), 'host:delete sent for a nameserver of the domain itself');
    }

    /** ADV-3b (medium): the same in the list kept for the page: the domain's own nameserver shown unused, offered for deletion. */
    public function test_adv3b_the_list_marks_the_domains_own_nameserver_in_use(): void
    {
        $domain = $this->domain();
        $this->loggedIn();
        $this->domainInfoWith(['ns1.example1.gr', 'old.example1.gr'], ns: ['ns1.example1.gr', 'ns.example.com']);
        $this->hostInfoBy('ns1.example1.gr', ['84.54.49.6'], 'myip', linked: false);
        $this->hostInfoBy('old.example1.gr', ['84.54.49.7'], 'myip', linked: false);

        $hosts = app(DomainManagementService::class)->readChildHosts($domain, Staff::factory()->create());

        $this->assertTrue(collect($hosts)->firstWhere('name', 'ns1.example1.gr')['linked'], 'a nameserver of the domain shown as unused');
        $this->assertFalse(collect($hosts)->firstWhere('name', 'old.example1.gr')['linked']);
    }

    /**
     * ADV-4 (low): updateChildHost and deleteChildHost read host:info (with its clID) but never check it: a host under our
     * domain sponsored by another registrar (left behind by a transfer) gets host:update / host:delete sent. The domain's
     * clID is checked (ours()); the host's is not.
     */
    public function test_adv4_a_host_of_another_registrar_is_never_changed_or_deleted(): void
    {
        $domain = $this->domain();
        $this->loggedIn();
        $this->domainInfoWith(['ns1.example1.gr']);
        $this->hostInfoBy('ns1.example1.gr', ['84.54.49.6'], 'other');
        $this->answer(1000); // host:update, if sent
        $this->refused(fn () => (new GrEppRegistrar)->updateChildHost($domain, 'ns1', ['45.76.129.127'], $this->credentials()));

        $this->domainInfoWith(['ns2.example1.gr']);
        $this->hostInfoBy('ns2.example1.gr', ['84.54.49.6'], 'other');
        $this->answer(1000); // host:delete, if sent
        $this->refused(fn () => (new GrEppRegistrar)->deleteChildHost($domain, 'ns2', $this->credentials()));

        $this->assertSame([], $this->bodies('update'), 'host:update sent for another registrar\'s host');
        $this->assertSame([], $this->bodies('delete'), 'host:delete sent for another registrar\'s host');
    }

    /**
     * ADV-5 (low): updateDomain refuses a domain the registry will not change now (serverUpdateProhibited,
     * clientUpdateProhibited, pendingTransfer, pendingDelete: ADV-1 of #195) before anything is written; the child host
     * writes do not look at the domain's statuses: a host is made under a domain pending transfer away or deletion.
     */
    public function test_adv5_no_host_written_under_a_domain_pending_transfer_or_delete(): void
    {
        $domain = $this->domain();
        $this->loggedIn();
        foreach (['pendingTransfer', 'pendingDelete', 'serverUpdateProhibited'] as $status) {
            $this->domainInfoWith([], [$status]);
            $this->answer(1000); // host:create, if sent
            $this->refused(fn () => (new GrEppRegistrar)->createChildHost($domain, 'ns9', ['84.54.49.6'], $this->credentials()));
        }

        $this->assertSame([], $this->bodies('create'), 'host:create sent under a domain the registry will not change now');
    }

    /**
     * ADV-6 (low): create dedupes the addresses as strings: the same IPv6 written twice (2a01:4f8::1, 2A01:04F8:0::1) is
     * sent twice in host:create (update compares them by inet_pton, create does not); the registry refuses or keeps a
     * duplicate.
     */
    public function test_adv6_the_same_ipv6_written_twice_is_sent_once(): void
    {
        $domain = $this->domain();
        $this->loggedIn();
        $this->domainInfoWith([]);
        $this->answer(1000); // host:create
        (new GrEppRegistrar)->createChildHost($domain, 'ns1', ['2a01:4f8::1', '2A01:04F8:0::1'], $this->credentials());

        $this->assertSame(1, substr_count($this->bodies('create')[0], 'ip="v6"'), 'one address, sent twice');
    }

    /** Regression (passes): staff without admin.domains.manage get none of the actions, and calling one sends nothing. */
    public function test_regression_staff_without_manage_get_no_child_host_action(): void
    {
        $domain = $this->domain();
        $domain->forceFill(['meta' => ['child_hosts' => ['read_at' => now()->toIso8601String(), 'hosts' => [
            ['name' => 'old.example1.gr', 'addresses' => ['84.54.49.7'], 'linked' => false],
        ]]]])->save();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->withPermissions(['admin.domains.viewAny', 'admin.domains.view'])->create();
        $page = $this->livewireAs($staff)->test(ViewDomain::class, ['record' => $domain->id])
            ->assertActionHidden('readChildHosts')->assertActionHidden('createChildHost')
            ->assertActionHidden('updateChildHost')->assertActionHidden('deleteChildHost');
        $this->refused(fn () => $page->callAction('createChildHost', data: ['host' => 'ns7', 'addresses' => '84.54.49.6']));
        $this->refused(fn () => $page->callAction('deleteChildHost', data: ['host' => 'old.example1.gr']));

        $this->assertSame([], $this->transport->sent);
        $this->assertSame(0, DomainRegistrarLog::query()->count());
    }

    /** Regression (passes): names not under the domain (suffix tricks, punycode, a Cyrillic look-alike, control characters) are refused unsent. */
    public function test_regression_names_not_under_the_domain(): void
    {
        $domain = $this->domain();
        $passed = [];
        foreach (['ns1.example1.gr.evil.gr', 'ns1.evilexample1.gr', 'example1.gr', 'ns1.gr', 'ns1.xn--example1-.gr', 'ns1.exаmple1.gr',
            "ns1\x00.example1.gr", "ns1\n.example1.gr", 'ns1.example1.com.gr'] as $name) {
            $this->transport->sent = [];
            $this->refused(fn () => (new GrEppRegistrar)->deleteChildHost($domain, $name, $this->credentials()));
            if ($this->transport->sent !== []) {
                $passed[] = $name;
            }
        }
        $this->assertSame([], $passed);
    }
}
