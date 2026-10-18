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
use App\Services\Domains\RegistrarNotAsked;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * Child nameservers (glue) at the .gr registry: hosts are objects of their own there. Read on request (domain:info's
 * hosts, host:info each: the forgotten ones too), made under the domain with public IPs, their IPs set as a full set,
 * deleted only when no domain uses them; never another registrar's domain's; logged.
 */
class GreppChildHostsTest extends TestCase
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

    /** @param  list<string>  $hosts */
    private function domainInfo(array $hosts, string $clID = 'myip'): void
    {
        $this->answer(1000, '<domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>example1.gr</domain:name><domain:roid>r-gr</domain:roid>'
            . '<domain:status s="ok"/><domain:registrant>c60_x</domain:registrant><domain:ns><domain:hostObj>ns1.example1.gr</domain:hostObj><domain:hostObj>ns.example.com</domain:hostObj></domain:ns>'
            . implode('', array_map(fn ($h) => "<domain:host>{$h}</domain:host>", $hosts))
            . "<domain:clID>{$clID}</domain:clID><domain:crDate>2005-03-01T10:00:00.0Z</domain:crDate><domain:exDate>2029-03-01T23:59:59.0Z</domain:exDate></domain:infData>");
    }

    /** @param  list<string>  $addresses */
    private function hostInfo(string $name, array $addresses, bool $linked): void
    {
        $this->answer(1000, '<host:infData xmlns:host="urn:ietf:params:xml:ns:host-1.0"><host:name>' . $name . '</host:name><host:roid>h-gr</host:roid><host:status s="ok"/>'
            . ($linked ? '<host:status s="linked"/>' : '')
            . implode('', array_map(fn ($ip) => '<host:addr ip="' . (str_contains($ip, ':') ? 'v6' : 'v4') . "\">{$ip}</host:addr>", $addresses))
            . '<host:clID>myip</host:clID><host:crID>myip</host:crID><host:crDate>2004-08-17T10:00:00.0Z</host:crDate></host:infData>');
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

    public function test_staff_read_them_all_with_their_ips_and_whether_used(): void
    {
        $domain = $this->domain();
        $this->loggedIn();
        $this->domainInfo(['ns1.example1.gr', 'old.example1.gr']);
        $this->hostInfo('ns1.example1.gr', ['84.54.49.6', '2a01:4f8::1'], true);
        $this->hostInfo('old.example1.gr', ['192.0.2.10'], false);

        $hosts = app(DomainManagementService::class)->readChildHosts($domain, Staff::factory()->create());

        $this->assertSame([
            ['name' => 'ns1.example1.gr', 'addresses' => ['84.54.49.6', '2a01:4f8::1'], 'linked' => true, 'read' => true],
            ['name' => 'old.example1.gr', 'addresses' => ['192.0.2.10'], 'linked' => false, 'read' => true],
        ], $hosts);
        $this->assertSame($hosts, $domain->fresh()->meta['child_hosts']['hosts']);
        $this->assertSame(DomainRegistrarLog::STATUS_OK, DomainRegistrarLog::query()->where('action', 'child_hosts')->value('status'));
    }

    public function test_one_made_under_the_domain_with_public_ips_only(): void
    {
        $domain = $this->domain();
        $registrar = new GrEppRegistrar;
        foreach ([['ns1.other.gr', ['84.54.49.6']], ['ns1', ['10.0.0.1']], ['ns1', ['not-an-ip']], ['ns1', []]] as [$host, $ips]) {
            $thrown = null;
            try {
                $registrar->createChildHost($domain, $host, $ips, $this->credentials());
            } catch (RegistrarNotAsked $e) {
                $thrown = $e;
            }
            $this->assertNotNull($thrown, "{$host}: not asked");
        }
        $this->assertSame([], $this->transport->sent, 'refused before anything is sent, not even a login');

        $this->loggedIn();
        $this->domainInfo([]);
        $this->answer(1000); // host:create
        $registrar->createChildHost($domain, 'NS1', ['84.54.49.6', '2a01:4f8::1'], $this->credentials());
        $create = $this->bodies('create')[0];
        $this->assertStringContainsString('<host:name>ns1.example1.gr</host:name>', $create);
        $this->assertStringContainsString('<host:addr ip="v4">84.54.49.6</host:addr>', $create);
        $this->assertStringContainsString('<host:addr ip="v6">2a01:4f8::1</host:addr>', $create);
    }

    public function test_its_ips_set_as_a_full_set_and_only_an_unused_one_deleted(): void
    {
        $domain = $this->domain();
        $registrar = new GrEppRegistrar;
        $this->loggedIn();
        $this->domainInfo(['ns1.example1.gr']);
        $this->hostInfo('ns1.example1.gr', ['84.54.49.6', '2A01:04F8:0::1'], true);
        $this->answer(1000); // host:update
        $registrar->updateChildHost($domain, 'ns1.example1.gr', ['2a01:4f8::1', '45.76.129.127'], $this->credentials());
        $update = $this->bodies('update')[0];
        $this->assertStringContainsString('<host:add><host:addr ip="v4">45.76.129.127</host:addr></host:add>', $update);
        $this->assertStringContainsString('<host:rem><host:addr ip="v4">84.54.49.6</host:addr></host:rem>', $update);
        $this->assertStringNotContainsString('2a01', strtolower($update), 'the same IPv6, written otherwise: kept');

        $this->domainInfo(['ns1.example1.gr']);
        $this->hostInfo('ns1.example1.gr', ['45.76.129.127'], false);
        $registrar->updateChildHost($domain, 'ns1', ['45.76.129.127'], $this->credentials());
        $this->assertCount(1, $this->bodies('update'), 'the same set: nothing sent');

        $this->domainInfo(['ns1.example1.gr']);
        $this->hostInfo('ns1.example1.gr', ['45.76.129.127'], true);
        $thrown = null;
        try {
            $registrar->deleteChildHost($domain, 'ns1', $this->credentials());
        } catch (RegistrarNotAsked $e) {
            $thrown = $e;
        }
        $this->assertStringContainsString('nameserver of a domain', (string) $thrown?->getMessage());

        $this->domainInfo(['old.example1.gr']);
        $this->hostInfo('old.example1.gr', ['192.0.2.10'], false);
        $this->answer(1000); // host:delete
        $registrar->deleteChildHost($domain, 'old', $this->credentials());
        $this->assertStringContainsString('<host:name>old.example1.gr</host:name>', $this->bodies('delete')[0]);
        $this->assertCount(1, $this->bodies('delete'));
    }

    public function test_never_another_registrars_domains_and_the_admin_offers_it(): void
    {
        $domain = $this->domain();
        $this->loggedIn();
        $this->domainInfo([], 'other');
        $thrown = null;
        try {
            (new GrEppRegistrar)->createChildHost($domain, 'ns1', ['84.54.49.6'], $this->credentials());
        } catch (RegistrarNotAsked $e) {
            $thrown = $e;
        }
        $this->assertStringContainsString('another registrar', (string) $thrown?->getMessage());
        $this->assertSame([], $this->bodies('create'));

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->withPermissions(['admin.domains.viewAny', 'admin.domains.view', 'admin.domains.manage'])->create();
        $domain->forceFill(['meta' => ['child_hosts' => ['read_at' => now()->toIso8601String(), 'hosts' => [
            ['name' => 'ns1.example1.gr', 'addresses' => ['84.54.49.6'], 'linked' => true],
            ['name' => 'old.example1.gr', 'addresses' => ['192.0.2.10'], 'linked' => false],
        ]]]])->save();
        $this->livewireAs($staff)->test(ViewDomain::class, ['record' => $domain->id])
            ->assertActionVisible('readChildHosts')->assertActionVisible('createChildHost')
            ->assertActionVisible('updateChildHost')->assertActionVisible('deleteChildHost')
            ->assertSee('old.example1.gr');
    }

    public function test_review_fixes_of_196(): void
    {
        $domain = $this->domain();
        $registrar = new GrEppRegistrar;

        // 1: one IPv6 in two spellings is sent once, in one spelling.
        $this->loggedIn();
        $this->domainInfo([]);
        $this->answer(1000);
        $registrar->createChildHost($domain, 'ns1', ['2a01:4f8::1', '2A01:04F8:0::1'], $this->credentials());
        $this->assertSame(1, substr_count($this->bodies('create')[0], '<host:addr'));
        $this->assertStringContainsString('<host:addr ip="v6">2a01:4f8::1</host:addr>', $this->bodies('create')[0]);

        // 2 and 4: every host listed; past 30, or one whose read is refused, listed unread (never offered for deletion).
        $names = array_map(fn ($i) => "h{$i}.example1.gr", range(1, 31));
        $this->domainInfo($names);
        $this->hostInfo('h1.example1.gr', ['84.54.49.6'], false);
        $this->answer(2201); // h2: not ours to read
        foreach (array_slice($names, 2, 28) as $name) {
            $this->hostInfo($name, ['84.54.49.6'], false);
        }
        $hosts = $registrar->childHosts($domain, $this->credentials());
        $this->assertCount(31, $hosts);
        $this->assertSame([true, false, false], [$hosts[0]->read, $hosts[1]->read, $hosts[30]->read]);
        $this->assertTrue($hosts[30]->linked, 'unread: taken as in use');

        // 3: a change whose list cannot be read again: the page says the list is out of date.
        $domain->forceFill(['meta' => ['child_hosts' => ['read_at' => '2026-10-18T10:00:00+00:00', 'hosts' => []]]])->save();
        $this->domainInfo([]);
        $this->answer(1000); // host:create
        $this->transport->queue(new HttpResponse(200, 'not xml')); // the read again
        app(DomainManagementService::class)->createChildHost($domain, 'ns2', ['45.76.129.127'], Staff::factory()->create());
        $this->assertTrue($domain->fresh()->meta['child_hosts']['stale'] ?? false);
    }
}
