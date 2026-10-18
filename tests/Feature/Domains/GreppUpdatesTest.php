<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Enums\DomainStatus;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\RegistrarNotAsked;
use App\Services\Domains\RegistrarOutcomeUnknown;
use App\Services\Domains\RegistrarRefused;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\DomainChanges;
use App\Support\Domains\DomainRegistrarCredentials;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * G5b-1 of grepp: a .gr domain's nameservers and the contacts of its admin, tech and billing roles changed at the
 * registry (domain:update), read fresh first: only what differs is sent, never an empty update; external hosts made, an
 * internal one needs its glue; the registry's others on those roles (another registrar's 533_) replaced by ours; never
 * the registrant (an owner change, charged); no transfer lock or WHOIS privacy.
 */
class GreppUpdatesTest extends TestCase
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

    private function fixture(string $name): void
    {
        $body = (string) file_get_contents(base_path("packages/gr-epp/tests/fixtures/{$name}"));
        $this->transport->queue(new HttpResponse(200, (string) preg_replace('#<clTRID>[^<]*</clTRID>#', '<clTRID>ECHO</clTRID>', $body)));
    }

    private function loggedIn(): void
    {
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml');
    }

    /** @param  array<string, bool>  $hosts  name => free */
    private function hostCheck(array $hosts): void
    {
        $cds = '';
        foreach ($hosts as $host => $free) {
            $cds .= '<host:cd><host:name avail="' . ($free ? '1' : '0') . "\">{$host}</host:name></host:cd>";
        }
        $this->answer(1000, "<host:chkData xmlns:host=\"urn:ietf:params:xml:ns:host-1.0\">{$cds}</host:chkData>");
    }

    private function contactCheck(string $id, bool $free): void
    {
        $this->answer(1000, '<contact:chkData xmlns:contact="urn:ietf:params:xml:ns:contact-1.0"><contact:cd><contact:id avail="' . ($free ? '1' : '0') . "\">{$id}</contact:id></contact:cd></contact:chkData>");
    }

    /** example1.gr (ns1-3.myip.gr, no roles) or example2.gr (533_ on admin, tech, billing), with a contact of the account. */
    private function domain(string $sld = 'example1'): Domain
    {
        $contact = Contact::factory()->create(['client_id' => $this->user->client()->id, 'first_name' => 'Μαρία', 'last_name' => 'Παπαδοπούλου',
            'company_name' => null, 'email' => 'maria@example.test', 'phone' => '+30 210 0000000', 'address1' => 'Οδός 1', 'address2' => null,
            'city' => 'Αθήνα', 'state' => 'Αττική', 'postcode' => '10000', 'country' => 'GR']);
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['is_active' => true, 'registrar_connection_id' => $this->gr->id, 'grace_days' => 15, 'redemption_days' => 0]);
        $domain = Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => $sld, 'tld' => 'gr', 'fqdn' => "{$sld}.gr",
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->gr->id, 'status' => DomainStatus::Active, 'expires_at' => '2029-03-01',
            'currency_code' => 'EUR', 'years' => 2, 'registrant_contact_id' => $contact->id, 'registrant_handle' => 'c60_Example00' . ($sld === 'example1' ? '1' : '2'),
            'admin_contact_id' => $contact->id, 'tech_contact_id' => $contact->id, 'billing_contact_id' => $contact->id]);
        foreach (['ns1.myip.gr', 'ns2.myip.gr', 'ns3.myip.gr'] as $i => $host) {
            $domain->nameservers()->create(['host' => $host, 'sort' => $i]);
        }

        return $domain->fresh(['nameservers', 'registrantContact', 'adminContact', 'techContact', 'billingContact']);
    }

    private function update(): string
    {
        return (string) collect($this->transport->sent)->pluck('body')->first(fn (string $b) => str_contains($b, '<domain:update'));
    }

    public function test_nameservers_only_what_differs_an_external_host_made(): void
    {
        $domain = $this->domain();
        $this->loggedIn();
        $this->fixture('live/domain-info-response.xml'); // ns1, ns2, ns3.myip.gr
        $this->hostCheck(['ns1.myip.gr' => false, 'ns.example.com' => true]);
        $this->answer(1000); // host:create ns.example.com
        $this->answer(1000); // domain:update
        $this->fixture('live/domain-info-response.xml');

        $result = (new GrEppRegistrar)->updateDomain($domain, new DomainChanges(nameservers: ['NS1.myip.gr', 'ns.example.com']), $this->credentials());

        $this->assertSame(['hello', 'login', 'info', 'check', 'create', 'update', 'info'], $this->transport->kinds());
        $update = $this->update();
        $this->assertMatchesRegularExpression('#<domain:add><domain:ns><domain:hostObj>ns.example.com</domain:hostObj></domain:ns></domain:add>#', $update);
        $this->assertMatchesRegularExpression('#<domain:rem><domain:ns><domain:hostObj>ns2.myip.gr</domain:hostObj><domain:hostObj>ns3.myip.gr</domain:hostObj></domain:ns></domain:rem>#', $update);
        $this->assertStringNotContainsString('ns1.myip.gr', $update, 'kept: not sent');
        $this->assertStringNotContainsString('registrant', $update);
        $this->assertSame(DomainStatus::Active, $result->status);
    }

    public function test_the_same_set_sends_nothing_and_an_internal_host_missing_is_not_asked(): void
    {
        $domain = $this->domain();
        $this->loggedIn();
        $this->fixture('live/domain-info-response.xml');
        $this->hostCheck(['ns3.myip.gr' => false, 'ns2.myip.gr' => false, 'ns1.myip.gr' => false]);
        (new GrEppRegistrar)->updateDomain($domain, new DomainChanges(nameservers: ['ns3.myip.gr', 'ns2.myip.gr', 'ns1.myip.gr']), $this->credentials());
        $this->assertSame('', $this->update(), 'the same set, in another order: no update');

        $this->fixture('live/domain-info-response.xml');
        $this->hostCheck(['ns1.myip.gr' => false, 'ns9.example1.gr' => true]);
        $thrown = null;
        try {
            (new GrEppRegistrar)->updateDomain($domain, new DomainChanges(nameservers: ['ns1.myip.gr', 'ns9.example1.gr']), $this->credentials());
        } catch (RegistrarNotAsked $e) {
            $thrown = $e;
        }
        $this->assertStringContainsString('glue', (string) $thrown?->getMessage());
        $this->assertSame('', $this->update());
    }

    public function test_another_registrars_roles_replaced_by_ours_never_the_registrant(): void
    {
        $domain = $this->domain('example2');
        $id = 'c60_mcs' . base_convert((string) $domain->admin_contact_id, 10, 36);
        $this->loggedIn();
        $this->fixture('live/domain-info-other-registrar-contacts-response.xml'); // 533_Example002 on admin, tech, billing
        $this->contactCheck($id, true);
        $this->fixture('v4.3/14-contact-create-response.xml');
        $this->answer(1000); // domain:update
        $this->fixture('live/domain-info-other-registrar-contacts-response.xml');

        (new GrEppRegistrar)->updateDomain($domain, new DomainChanges(applyContacts: true), $this->credentials());

        $this->assertSame(['hello', 'login', 'info', 'check', 'create', 'update', 'info'], $this->transport->kinds(), 'one contact for the three roles');
        $update = $this->update();
        foreach (['admin', 'tech', 'billing'] as $role) {
            $this->assertStringContainsString("<domain:contact type=\"{$role}\">{$id}</domain:contact>", $update);
            $this->assertStringContainsString("<domain:contact type=\"{$role}\">533_Example002</domain:contact>", $update);
        }
        $this->assertStringNotContainsString('registrant', $update);
        $this->assertStringNotContainsString('<domain:chg>', $update);
        $this->assertSame([$id, $id, $id], [$domain->fresh()->admin_handle, $domain->fresh()->tech_handle, $domain->fresh()->billing_handle]);
        $this->assertSame('c60_Example002', $domain->fresh()->registrant_handle, 'the registrant untouched');
    }

    public function test_refused_and_what_the_registry_has_not(): void
    {
        $domain = $this->domain();
        foreach ([new DomainChanges(transferLock: true), new DomainChanges(whoisPrivacy: true), new DomainChanges] as $changes) {
            $thrown = null;
            try {
                (new GrEppRegistrar)->updateDomain($domain, $changes, $this->credentials());
            } catch (RegistrarNotAsked $e) {
                $thrown = $e;
            }
            $this->assertNotNull($thrown);
        }
        $this->assertSame([], $this->transport->sent);

        $this->loggedIn();
        $this->fixture('live/domain-info-response.xml');
        $this->hostCheck(['ns1.myip.gr' => false, 'ns.example.com' => false]);
        $this->answer(2201); // not ours to change
        $this->expectException(RegistrarRefused::class);
        (new GrEppRegistrar)->updateDomain($domain, new DomainChanges(nameservers: ['ns1.myip.gr', 'ns.example.com']), $this->credentials());
    }

    public function test_staff_change_the_nameservers_from_the_service_logged_with_its_ids(): void
    {
        $domain = $this->domain();
        $this->loggedIn();
        $this->fixture('live/domain-info-response.xml');
        $this->hostCheck(['ns1.myip.gr' => false, 'ns2.myip.gr' => false]);
        $this->answer(1000);
        $this->fixture('live/domain-info-response.xml');

        app(DomainManagementService::class)->setNameservers($domain, ['ns1.myip.gr', 'ns2.myip.gr'], Staff::factory()->create());

        $log = DomainRegistrarLog::query()->where('domain_id', $domain->id)->where('action', 'nameservers')->sole();
        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $this->assertSame(1000, collect($log->transactions)->firstWhere('command', 'domain:update')['code'] ?? null);
        $this->assertStringContainsString('<domain:rem><domain:ns><domain:hostObj>ns3.myip.gr</domain:hostObj>', $this->update());
    }

    public function test_review_fixes_of_195(): void
    {
        $staff = Staff::factory()->create();

        // 1: an update whose answer is lost may have landed: nothing undone here, the registry read again.
        $domain = $this->domain('example2');
        $other = Contact::factory()->create(['client_id' => $this->user->client()->id, 'first_name' => 'Νίκος', 'last_name' => 'Ν', 'email' => 'n@example.test',
            'phone' => '+30 210 1111111', 'address1' => 'Οδός 2', 'city' => 'Αθήνα', 'postcode' => '10001', 'country' => 'GR']);
        $id = 'c60_mcs' . base_convert((string) $other->id, 10, 36);
        $this->loggedIn();
        $this->fixture('live/domain-info-other-registrar-contacts-response.xml');
        $this->contactCheck($id, true);
        $this->fixture('v4.3/14-contact-create-response.xml');
        $this->transport->queue(new HttpResponse(200, 'not xml')); // domain:update: no usable answer
        $this->fixture('live/domain-info-other-registrar-contacts-response.xml'); // the sync after
        $thrown = null;
        try {
            app(DomainManagementService::class)->setContacts($domain, ['admin' => $other->id, 'tech' => $other->id, 'billing' => $other->id], $staff);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        $this->assertInstanceOf(RegistrarOutcomeUnknown::class, $thrown);
        $this->assertSame($other->id, $domain->fresh()->admin_contact_id, 'not undone: it may be done there');
        $this->assertSame('info', $this->transport->kinds()[count($this->transport->kinds()) - 1], 'read again');

        // 3: a role cleared here, while the registry has one: not asked, nothing sent.
        GrEppRegistrar::reset();
        $this->transport = new FakeTransport;
        $cleared = $domain->fresh();
        $cleared->forceFill(['billing_contact_id' => null])->save();
        $this->loggedIn();
        $this->fixture('live/domain-info-other-registrar-contacts-response.xml');
        $thrown = null;
        try {
            (new GrEppRegistrar)->updateDomain($cleared->fresh(['adminContact', 'techContact', 'billingContact']), new DomainChanges(applyContacts: true), $this->credentials());
        } catch (RegistrarNotAsked $e) {
            $thrown = $e;
        }
        $this->assertStringContainsString('billing', (string) $thrown?->getMessage());
        $this->assertSame('', $this->update());

        // 2 and 5: the registry's hosts with a trailing dot and in capitals are the same hosts; done but not read back,
        // the nameservers sent are kept, its status as it was.
        GrEppRegistrar::reset();
        $this->transport = new FakeTransport;
        $plain = $this->domain();
        $this->loggedIn();
        $info = (string) file_get_contents(base_path('packages/gr-epp/tests/fixtures/live/domain-info-response.xml'));
        $info = str_replace(['<domain:hostObj>ns1.myip.gr</domain:hostObj>', '<domain:hostObj>ns2.myip.gr</domain:hostObj>'],
            ['<domain:hostObj>NS1.MYIP.GR.</domain:hostObj>', '<domain:hostObj>ns2.myip.gr.</domain:hostObj>'], $info);
        $this->transport->queue(new HttpResponse(200, (string) preg_replace('#<clTRID>[^<]*</clTRID>#', '<clTRID>ECHO</clTRID>', $info)));
        $this->hostCheck(['ns1.myip.gr' => false, 'ns2.myip.gr' => false]);
        $this->answer(1000); // domain:update
        $this->transport->queue(new HttpResponse(200, 'not xml')); // the read back
        app(DomainManagementService::class)->setNameservers($plain, ['ns1.myip.gr', 'ns2.myip.gr'], $staff);
        $update = $this->update();
        $this->assertStringContainsString('<domain:rem><domain:ns><domain:hostObj>ns3.myip.gr</domain:hostObj></domain:ns></domain:rem>', $update);
        $this->assertStringNotContainsString('<domain:add>', $update, 'NS1.MYIP.GR. is ns1.myip.gr');
        $this->assertSame(['ns1.myip.gr', 'ns2.myip.gr'], $plain->fresh()->nameservers()->orderBy('sort')->pluck('host')->all());
        $this->assertSame(DomainStatus::Active, $plain->fresh()->status);

        // 4: no transfer lock or privacy offered for .gr.
        $this->assertFalse(app(DomainManagementService::class)->registrarSupports($plain, 'supportsTransferLock'));
        $this->assertFalse(app(DomainManagementService::class)->registrarSupports($plain, 'supportsPrivacy'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = Staff::factory()->withPermissions(['admin.domains.viewAny', 'admin.domains.view', 'admin.domains.manage'])->create();
        $this->livewireAs($admin)->test(ViewDomain::class, ['record' => $plain->id])
            ->assertActionHidden('lock')->assertActionHidden('privacy')->assertActionVisible('nameservers');
    }
}
