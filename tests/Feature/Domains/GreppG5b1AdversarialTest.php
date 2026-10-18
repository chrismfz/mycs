<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\DomainChanges;
use App\Support\Domains\DomainRegistrarCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #195 (G5b-1: .gr nameservers and admin/tech/billing contacts through updateDomain), written
 * without the author's context. Each test is one finding (ADV-n); see the PR comment for the table.
 */
class GreppG5b1AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private FakeTransport $transport;

    private DomainRegistrarConnection $gr;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 12:00:00');
        Cache::flush();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $this->gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], ['password']);
        $this->user = User::factory()->create();
        $this->fresh();
    }

    protected function tearDown(): void
    {
        GrEppRegistrar::$transport = null;
        GrEppRegistrar::reset();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fresh(): void
    {
        GrEppRegistrar::reset();
        $this->transport = new FakeTransport;
        GrEppRegistrar::$transport = fn () => $this->transport;
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

    /** @param  array<string, string>  $replace  regex => replacement on the fixture */
    private function fixture(string $name, array $replace = []): void
    {
        $body = (string) file_get_contents(base_path("packages/gr-epp/tests/fixtures/{$name}"));
        $body = (string) preg_replace('#<clTRID>[^<]*</clTRID>#', '<clTRID>ECHO</clTRID>', $body);
        foreach ($replace as $pattern => $with) {
            $body = (string) preg_replace($pattern, $with, $body);
        }
        $this->transport->queue(new HttpResponse(200, $body));
    }

    private function noAnswer(): void
    {
        $this->transport->queue(new HttpResponse(0, '', [], 'Operation timed out', false));
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

    private function contact(string $first = 'Μαρία', string $last = 'Παπαδοπούλου', string $email = 'maria@example.test'): Contact
    {
        return Contact::factory()->create(['client_id' => $this->user->client()->id, 'first_name' => $first, 'last_name' => $last,
            'company_name' => null, 'email' => $email, 'phone' => '+30 210 0000000', 'address1' => 'Οδός 1', 'address2' => null,
            'city' => 'Αθήνα', 'state' => 'Αττική', 'postcode' => '10000', 'country' => 'GR']);
    }

    /** @param  array<string, mixed>  $extra */
    private function domain(string $sld = 'example1', array $extra = []): Domain
    {
        $contact = $this->contact();
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['is_active' => true, 'registrar_connection_id' => $this->gr->id, 'grace_days' => 15, 'redemption_days' => 0]);
        $domain = Domain::factory()->create($extra + ['client_id' => $this->user->client()->id, 'sld' => $sld, 'tld' => 'gr', 'fqdn' => "{$sld}.gr",
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

    /**
     * ADV-1: the fresh domain:info is read but never checked. A domain of another registrar (clID), or one the registry
     * will not let change (serverUpdateProhibited, clientUpdateProhibited, pendingTransfer, pendingDelete), still gets
     * its hosts and contacts made at the registry under our account, then an update the registry refuses.
     */
    public function test_adv1_a_domain_not_ours_or_not_updatable_gets_nothing_made_and_no_update(): void
    {
        $cases = [
            'another registrar' => ['#<domain:clID>myip</domain:clID>#' => '<domain:clID>other</domain:clID>'],
            'serverUpdateProhibited' => ['#<domain:status s="ok"/>#' => '<domain:status s="serverUpdateProhibited"/>'],
            'clientUpdateProhibited' => ['#<domain:status s="ok"/>#' => '<domain:status s="clientUpdateProhibited"/>'],
            'pendingTransfer' => ['#<domain:status s="ok"/>#' => '<domain:status s="pendingTransfer"/>'],
            'pendingDelete' => ['#<domain:status s="ok"/>#' => '<domain:status s="pendingDelete"/>'],
        ];
        $made = [];
        $domains = ['nameservers' => $this->domain('example1'), 'contacts' => $this->domain('example2')];
        foreach ($cases as $case => $replace) {
            foreach (['nameservers', 'contacts'] as $what) {
                $this->fresh();
                Domain::query()->whereKey($domains[$what]->id)->update(['admin_handle' => null, 'tech_handle' => null, 'billing_handle' => null]);
                $domain = $domains[$what]->fresh(['nameservers', 'adminContact', 'techContact', 'billingContact']);
                $this->loggedIn();
                if ($what === 'nameservers') {
                    $this->fixture('live/domain-info-response.xml', $replace);
                    $this->hostCheck(['ns1.myip.gr' => false, 'ns.example.com' => true]);
                    $this->answer(1000); // host:create
                    $changes = new DomainChanges(nameservers: ['ns1.myip.gr', 'ns.example.com']);
                } else {
                    $this->fixture('live/domain-info-other-registrar-contacts-response.xml', $replace);
                    $this->contactCheck('c60_mcs' . base_convert((string) $domain->admin_contact_id, 10, 36), true);
                    $this->fixture('v4.3/14-contact-create-response.xml');
                    $changes = new DomainChanges(applyContacts: true);
                }
                $this->answer(2304); // domain:update: status prohibits (or 2201: not ours)
                try {
                    (new GrEppRegistrar)->updateDomain($domain, $changes, $this->credentials());
                } catch (Throwable) {
                    // refused either way: what matters is what was sent before
                }
                $kinds = $this->transport->kinds();
                if (in_array('create', $kinds, true) || in_array('update', $kinds, true)) {
                    $made[] = "{$case} / {$what}: " . implode(',', $kinds);
                }
            }
        }
        $this->assertSame([], $made, 'hosts or contacts made, and an update sent, for a domain the registry says is not ours or not updatable');
    }

    /** ADV-2: an empty nameserver set at the registrar removes every nameserver of the domain (the service's 2-13 is its only guard). */
    public function test_adv2_an_empty_nameserver_set_never_leaves_the_domain_without_nameservers(): void
    {
        $domain = $this->domain();
        $this->loggedIn();
        $this->fixture('live/domain-info-response.xml');
        $this->answer(1000); // domain:update
        $this->fixture('live/domain-info-response.xml');

        try {
            (new GrEppRegistrar)->updateDomain($domain, new DomainChanges(nameservers: []), $this->credentials());
        } catch (Throwable) {
        }

        $this->assertSame('', $this->update(), 'an update removing ns1-3 and adding none: the domain left without nameservers');
    }

    /**
     * ADV-3: the registry's nameservers are only lower-cased (DomainInfo), ours are made Unicode (Rules::nameservers). An
     * IDN nameserver the registry answers in punycode never matches: keeping the same set is not «nothing to send» but an
     * add and a remove of the same host (refused as not asked), so no change of an IDN-nameserved domain gets through.
     */
    public function test_adv3_an_idn_nameserver_kept_in_punycode_is_the_same_host(): void
    {
        $domain = $this->domain();
        $this->loggedIn();
        $this->fixture('live/domain-info-response.xml', ['#<domain:hostObj>ns3.myip.gr</domain:hostObj>#' => '<domain:hostObj>ns1.xn--hxajbheg2az3al.gr</domain:hostObj>']);
        $this->hostCheck(['ns1.myip.gr' => false, 'ns2.myip.gr' => false, 'ns1.παράδειγμα.gr' => false]);

        $thrown = null;
        try {
            (new GrEppRegistrar)->updateDomain($domain, new DomainChanges(nameservers: ['ns1.myip.gr', 'ns2.myip.gr', 'ns1.xn--hxajbheg2az3al.gr']), $this->credentials());
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertNull($thrown, 'the same set (an IDN host in punycode at the registry): ' . $thrown?->getMessage());
        $this->assertSame('', $this->update());
    }

    /**
     * ADV-4: a contacts change whose domain:update has no answer (OutcomeUnknown: maybe done) is undone here as if refused
     * (DomainManagementService::write undoes on any Throwable but ConnectionException): MyCS shows the old tech contact,
     * the registry may have the new one. Nothing re-reads it either.
     */
    public function test_adv4_a_contacts_change_with_no_answer_is_not_undone_as_if_refused(): void
    {
        $domain = $this->domain();
        $other = $this->contact('Γιώργος', 'Νικολάου', 'giorgos@example.test');
        $this->loggedIn();
        $this->fixture('live/domain-info-response.xml');
        $this->contactCheck('c60_mcs' . base_convert((string) $domain->admin_contact_id, 10, 36), true);
        $this->fixture('v4.3/14-contact-create-response.xml');
        $this->contactCheck('c60_mcs' . base_convert((string) $other->id, 10, 36), true);
        $this->fixture('v4.3/14-contact-create-response.xml');
        $this->noAnswer(); // domain:update: sent, no answer
        $this->fixture('live/domain-info-response.xml'); // a re-read, if any
        $this->fixture('live/domain-info-response.xml');

        $thrown = null;
        try {
            app(DomainManagementService::class)->setContacts($domain, ['tech' => $other->id], Staff::factory()->create());
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown);
        $this->assertStringContainsString('<domain:update', $this->update(), 'the update was sent');
        $this->assertSame($other->id, $domain->fresh()->tech_contact_id, 'maybe done at the registry: undone here as if refused');
    }

    /**
     * ADV-5: the registry's truth after an update unreadable → a made-up «active». A domain past its expiry (grace at the
     * registry, no pendingDelete yet) whose nameservers change becomes Active here, though the info just read said grace.
     */
    public function test_adv5_an_unreadable_truth_after_the_update_never_makes_an_expired_domain_active(): void
    {
        $domain = $this->domain('example1', ['status' => DomainStatus::Grace, 'expires_at' => '2026-10-10']);
        $this->loggedIn();
        $this->fixture('live/domain-info-response.xml', ['#<domain:exDate>[^<]*</domain:exDate>#' => '<domain:exDate>2026-10-10T23:59:59.9Z</domain:exDate>']);
        $this->hostCheck(['ns1.myip.gr' => false, 'ns2.myip.gr' => false]);
        $this->answer(1000); // domain:update
        $this->noAnswer(); // the read after

        $result = (new GrEppRegistrar)->updateDomain($domain, new DomainChanges(nameservers: ['ns1.myip.gr', 'ns2.myip.gr']), $this->credentials());

        $this->assertNotSame(DomainStatus::Active, $result->status, 'the info read before the update said grace');
    }

    /**
     * ADV-6: a change of one role (tech) rewrites the others too. An admin/billing linked by staff to the registry's
     * handle (533_, after a transfer) is replaced by a new c60_ contact made from ours, though only tech was asked.
     */
    public function test_adv6_changing_one_role_leaves_the_other_linked_roles_at_the_registry_as_they_are(): void
    {
        $domain = $this->domain('example2');
        $domain->forceFill(['admin_handle' => '533_Example002', 'billing_handle' => '533_Example002', 'tech_handle' => '533_Example002'])->save();
        $other = $this->contact('Γιώργος', 'Νικολάου', 'giorgos@example.test');
        $mine = 'c60_mcs' . base_convert((string) $domain->admin_contact_id, 10, 36);
        $theirs = 'c60_mcs' . base_convert((string) $other->id, 10, 36);
        $this->loggedIn();
        $this->fixture('live/domain-info-other-registrar-contacts-response.xml');
        // Only the role asked for is checked and made (the fix: admin and billing, linked to 533_, are not touched).
        $this->contactCheck($theirs, true);
        $this->fixture('v4.3/14-contact-create-response.xml');
        $this->answer(1000); // domain:update
        $this->fixture('live/domain-info-other-registrar-contacts-response.xml');
        $this->fixture('live/domain-info-other-registrar-contacts-response.xml');

        app(DomainManagementService::class)->setContacts($domain->fresh(), ['tech' => $other->id], Staff::factory()->create());

        $update = $this->update();
        $this->assertStringContainsString("<domain:contact type=\"tech\">{$theirs}</domain:contact>", $update);
        $this->assertStringNotContainsString('type="admin"', $update, 'admin was not asked to change');
        $this->assertStringNotContainsString('type="billing"', $update, 'billing was not asked to change');
    }

    /**
     * ADV-7: the contact id is the local contact's id; a registry contact of that id with the same name (another
     * instance's, a reset database's) is taken as ours though its email and phone are someone else's: the domain's
     * admin/tech/billing become that person. The name alone is the check (ADV-1 of #192).
     */
    public function test_adv7_a_registry_contact_with_our_id_and_name_but_another_email_is_not_used(): void
    {
        $domain = $this->domain();
        $id = 'c60_mcs' . base_convert((string) $domain->admin_contact_id, 10, 36);
        $this->loggedIn();
        $this->fixture('live/domain-info-response.xml');
        $this->contactCheck($id, false); // there already
        $this->fixture('live/contact-info-response.xml', [
            '#<contact:id>[^<]*</contact:id>#' => "<contact:id>{$id}</contact:id>",
            '#<contact:name>[^<]*</contact:name>#' => '<contact:name>Μαρία Παπαδοπούλου</contact:name>',
            '#<contact:email>[^<]*</contact:email>#' => '<contact:email>someone-else@example.test</contact:email>',
        ]);
        $this->answer(1000); // domain:update
        $this->fixture('live/domain-info-response.xml');

        try {
            (new GrEppRegistrar)->updateDomain($domain, new DomainChanges(applyContacts: true), $this->credentials());
        } catch (Throwable) {
        }

        $this->assertStringNotContainsString($id, $this->update(), "another person's registry contact made admin/tech/billing");
    }

    /** Regression (passes): the registrant is never in an update, even when the registry's registrant is not ours. */
    public function test_regression_the_registrant_never_changes(): void
    {
        $domain = $this->domain();
        $domain->forceFill(['registrant_handle' => null])->save();
        $this->loggedIn();
        $this->fixture('live/domain-info-response.xml', ['#<domain:registrant>[^<]*</domain:registrant>#' => '<domain:registrant>533_Owner</domain:registrant>']);
        $id = 'c60_mcs' . base_convert((string) $domain->admin_contact_id, 10, 36);
        $this->contactCheck($id, true);
        $this->fixture('v4.3/14-contact-create-response.xml');
        $this->answer(1000);
        $this->fixture('live/domain-info-response.xml');

        (new GrEppRegistrar)->updateDomain($domain->fresh(['registrantContact', 'adminContact', 'techContact', 'billingContact']), new DomainChanges(applyContacts: true), $this->credentials());

        $update = $this->update();
        $this->assertStringNotContainsString('registrant', $update);
        $this->assertStringNotContainsString('<domain:chg', $update);
        $this->assertStringNotContainsString('533_Owner', $update);
        $this->assertNull($domain->fresh()->registrant_handle);
    }

    /** Regression (passes): a contact of another account on a role is never sent. */
    public function test_regression_another_accounts_contact_is_never_sent(): void
    {
        $domain = $this->domain();
        $stranger = Contact::factory()->create(['client_id' => User::factory()->create()->client()->id, 'phone' => '+30 210 0000000']);
        $domain->forceFill(['tech_contact_id' => $stranger->id, 'tech_handle' => null])->save();
        $this->loggedIn();
        $this->fixture('live/domain-info-response.xml');
        $this->contactCheck('c60_mcs' . base_convert((string) $domain->admin_contact_id, 10, 36), true);
        $this->fixture('v4.3/14-contact-create-response.xml');

        $thrown = null;
        try {
            (new GrEppRegistrar)->updateDomain($domain->fresh(['adminContact', 'techContact', 'billingContact']), new DomainChanges(applyContacts: true), $this->credentials());
        } catch (Throwable $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown);
        $this->assertSame('', $this->update());
        $this->assertStringNotContainsString('c60_mcs' . base_convert((string) $stranger->id, 10, 36), implode('', array_column($this->transport->sent, 'body')));

        // And through the service: another account's contact refused before anything is sent.
        $this->fresh();
        $this->expectException(\InvalidArgumentException::class);
        app(DomainManagementService::class)->setContacts($this->domain('example2'), ['admin' => $stranger->id], Staff::factory()->create());
    }
}
