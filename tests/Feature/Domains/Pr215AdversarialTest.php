<?php

namespace Tests\Feature\Domains;

use App\Contracts\ChangesOwner;
use App\Enums\DomainStatus;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\User;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\RegistrarNotAsked;
use App\Services\Domains\RegistrarRefused;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * Adversarial review of #215 (G8-5a, GrEppRegistrar::changeOwner): a charged, never recalled change of a .gr domain's
 * holder. Tests named adv<N> fail on the PR's head (a defect); those named guard_* pass (what was tried and holds).
 */
class Pr215AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private FakeTransport $transport;

    private DomainRegistrarConnection $gr;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->user = User::factory()->create();
        $this->gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $this->gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], ['password']);
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

    private function contact(array $attributes = []): Contact
    {
        return Contact::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'first_name' => 'Μαρία', 'last_name' => 'Παπαδοπούλου',
            'company_name' => null, 'email' => 'maria@example.test', 'phone' => '+30 210 0000000', 'address1' => 'Οδός 1', 'address2' => null,
            'city' => 'Αθήνα', 'state' => 'Αττική', 'postcode' => '10000', 'country' => 'GR']);
    }

    private function domain(string $sld = 'cdnet', bool $withRegistrant = true): Domain
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['is_active' => true, 'registrar_connection_id' => $this->gr->id, 'grace_days' => 15, 'redemption_days' => 0]);

        return Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => $sld, 'tld' => 'gr', 'fqdn' => "{$sld}.gr",
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->gr->id, 'status' => DomainStatus::Active,
            'registrant_contact_id' => $withRegistrant ? $this->contact()->id : null, 'registrant_handle' => 'c60_mcs1']);
    }

    /** domain:info of ours, with these EPP and EETT statuses. */
    private function info(array $statuses = ['ok'], array $regulator = [], string $name = 'cdnet.gr', string $clID = 'myip'): string
    {
        $s = implode('', array_map(fn ($x) => "<domain:status s=\"{$x}\"/>", $statuses));
        $r = $regulator !== [] ? '<extension><extdomain:resData xmlns:extdomain="urn:ics-forth:params:xml:ns:extdomain-1.3">'
            . implode('', array_map(fn ($x) => "<extdomain:status s=\"{$x}\"/>", $regulator)) . '</extdomain:resData></extension>' : '';

        return '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>ok</msg></result>'
            . "<resData><domain:infData xmlns:domain=\"urn:ietf:params:xml:ns:domain-1.0\"><domain:name>{$name}</domain:name><domain:roid>d1-gr</domain:roid>"
            . "{$s}<domain:registrant>c60_mcs1</domain:registrant><domain:ns><domain:hostObj>ns1.myip.gr</domain:hostObj></domain:ns><domain:clID>{$clID}</domain:clID>"
            . "<domain:exDate>2028-08-16T23:59:59.9Z</domain:exDate></domain:infData></resData>{$r}<trID><clTRID>ECHO</clTRID><svTRID>s</svTRID></trID></response></epp>";
    }

    private function free(string ...$ids): string
    {
        $cd = implode('', array_map(fn ($id) => "<contact:cd><contact:id avail=\"1\">{$id}</contact:id></contact:cd>", $ids));

        return '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>ok</msg></result>'
            . "<resData><contact:chkData xmlns:contact=\"urn:ietf:params:xml:ns:contact-1.0\">{$cd}</contact:chkData></resData>"
            . '<trID><clTRID>ECHO</clTRID><svTRID>s</svTRID></trID></response></epp>';
    }

    private function answer(int $code): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
            . "<result code=\"{$code}\"><msg>msg</msg></result><trID><clTRID>ECHO</clTRID><svTRID>s-{$code}</svTRID></trID></response></epp>";
    }

    private function fixture(string $name): string
    {
        return (string) preg_replace('#<clTRID>[^<]*</clTRID>#', '<clTRID>ECHO</clTRID>', (string) file_get_contents(base_path("packages/gr-epp/tests/fixtures/v4.3/{$name}.xml")));
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

    private function credentials(): DomainRegistrarCredentials
    {
        return app(DomainRegistrarFactory::class)->credentialsFor($this->gr);
    }

    /** @return list<string> */
    private function sent(string $needle): array
    {
        return array_values(array_map(fn (array $r) => $r['body'], array_filter($this->transport->sent, fn (array $r) => str_contains($r['body'], $needle))));
    }

    private function mow(Domain $domain): string
    {
        return 'c60_mow' . base_convert((string) $domain->id, 10, 36);
    }

    /** Asks for the change with the registry's answers queued (enough for a full run, so a missing guard shows as a sent update). */
    private function attempt(Domain $domain, Contact $holder, string $kind, string $info): ?\Throwable
    {
        $this->registry($info, $this->free($this->mow($domain)), $this->fixture('14-contact-create-response'), $this->fixture('47-domain-update-owner-change-response'));
        try {
            (new GrEppRegistrar)->changeOwner($domain, $holder, $kind, $this->credentials());
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    /**
     * ADV-1 (high): a change of holder already awaiting the EETT («pendingRegulatorApproval», the guide p.11: added instead
     * of pendingUpdate to a .gov.gr or geographic name after an ownerChange) is not a block: a second ownerChange, charged
     * again and never recalled, is sent (a retry after «awaiting the EETT», another holder meanwhile).
     */
    public function test_adv1_a_change_already_awaiting_the_eett_is_never_charged_again(): void
    {
        $domain = $this->domain();
        $e = $this->attempt($domain, $this->contact(['first_name' => 'Γιώργος']), ChangesOwner::OWNER, $this->info(['ok'], ['pendingRegulatorApproval']));

        $this->assertSame([], $this->sent('extdomain:op'), 'an ownerChange was sent while another awaits the EETT');
        $this->assertSame([], $this->sent('contact:create'));
        $this->assertInstanceOf(RegistrarNotAsked::class, $e);
    }

    /** ADV-1b (high): the same with the EPP «pendingUpdate» (an update in progress at the registry). */
    public function test_adv1b_a_pending_update_is_never_charged_over(): void
    {
        $domain = $this->domain();
        $e = $this->attempt($domain, $this->contact(['first_name' => 'Γιώργος']), ChangesOwner::OWNER, $this->info(['pendingUpdate']));

        $this->assertSame([], $this->sent('extdomain:op'), 'an ownerChange was sent over a pendingUpdate');
        $this->assertInstanceOf(RegistrarNotAsked::class, $e);
    }

    /**
     * ADV-2 (high): a 2-character name (ab.gr): the EETT charges 300 € per deed, μεταβίβαση and αλλαγή επωνυμίας included
     * (grepp/faq.md «Χρεώσεις»), and register/renew/transferIn refuse it (notTwoCharacters, staff by hand). changeOwner does not.
     */
    public function test_adv2_a_two_character_name_is_never_changed_from_here(): void
    {
        $domain = $this->domain('ab');
        $e = $this->attempt($domain, $this->contact(['first_name' => 'Γιώργος']), ChangesOwner::OWNER, $this->info(name: 'ab.gr'));

        $this->assertSame([], $this->sent('extdomain:op'), 'a 300 € EETT deed was sent for a 2-character name');
        $this->assertInstanceOf(RegistrarNotAsked::class, $e);
    }

    /**
     * ADV-3 (medium): «a name change only with a contact of the same account» is skipped when the domain has no local
     * registrant contact (imported, registrant_handle only): ownerNameChange (immediate, no EETT check, never recalled)
     * then puts another account's person as the holder.
     */
    public function test_adv3_the_same_account_rule_holds_without_a_local_registrant_contact(): void
    {
        $domain = $this->domain(withRegistrant: false);
        $stranger = $this->contact(['client_id' => User::factory()->create()->client()->id, 'first_name' => 'Ξένος', 'last_name' => 'Άλλος']);
        $e = $this->attempt($domain, $stranger, ChangesOwner::NAME, $this->info());

        $this->assertSame([], $this->sent('extdomain:op'), 'a name change to another account\'s contact was sent');
        $this->assertInstanceOf(RegistrarNotAsked::class, $e);
    }

    /**
     * ADV-4 (medium): the holder asked is the domain's own registrant contact (nothing changes): a charge for nothing,
     * never recalled. Refused before anything that costs.
     */
    public function test_adv4_the_current_holder_is_never_charged_for(): void
    {
        $domain = $this->domain();
        $e = $this->attempt($domain, $domain->registrantContact, ChangesOwner::OWNER, $this->info());

        $this->assertSame([], $this->sent('extdomain:op'), 'a charged change to the holder it already has was sent');
        $this->assertInstanceOf(RegistrarNotAsked::class, $e);
    }

    /**
     * ADV-5 (low): the contact is made, then the update refused (2xxx): the new registry contact stays behind, an orphan,
     * and each retry makes another (_mowX, _mowXa, … up to 27, then «no free contact id» for that domain for good).
     * Expected: the contact just made deleted after a refusal (free; nothing references it).
     */
    public function test_adv5_a_refused_change_leaves_no_contact_behind(): void
    {
        $domain = $this->domain();
        $this->registry($this->info(), $this->free($this->mow($domain)), $this->fixture('14-contact-create-response'), $this->answer(2304),
            $this->fixture('17-contact-delete-response'));

        try {
            (new GrEppRegistrar)->changeOwner($domain, $this->contact(['first_name' => 'Γιώργος']), ChangesOwner::OWNER, $this->credentials());
            $this->fail('refused, yet no exception');
        } catch (RegistrarRefused) {
        }

        $this->assertCount(1, $this->sent('contact:create'));
        $this->assertNotSame([], $this->sent('contact:delete'), 'the contact made for a refused change is left at the registry');
    }

    /**
     * ADV-6 (medium): 1001, awaiting the EETT (who may still refuse): the result says the new contact is the registrant
     * (contactHandles), which DomainSyncService applies as the domain's registrant: the holder recorded before it is.
     */
    public function test_adv6_awaiting_the_eett_does_not_claim_the_new_registrant(): void
    {
        $domain = $this->domain();
        $this->registry($this->info(), $this->free($this->mow($domain)), $this->fixture('14-contact-create-response'),
            $this->fixture('48-domain-update-owner-change-action-pending-response'));

        $result = (new GrEppRegistrar)->changeOwner($domain, $this->contact(['first_name' => 'Γιώργος']), ChangesOwner::OWNER, $this->credentials());

        $this->assertSame('change of holder: awaiting the EETT', $result->details['owner_change']);
        $this->assertArrayNotHasKey('registrant', $result->contactHandles, 'a pending change reported as the registrant');
    }

    /** Guard (passes): another registrar's domain, or one transferred/deleted/locked: nothing made, nothing charged. */
    public function test_guard_not_ours_or_not_changeable_is_never_asked(): void
    {
        $domain = $this->domain();
        foreach ([
            'another registrar' => $this->info(clID: 'other'),
            'pendingTransfer' => $this->info(['pendingTransfer']),
            'pendingDelete (expired, in its 15 days)' => $this->info(['pendingDelete']),
            'clientUpdateProhibited' => $this->info(['clientUpdateProhibited']),
            'regulatorUpdateProhibited' => $this->info(['ok'], ['regulatorUpdateProhibited']),
            'a variant answered (another name)' => $this->info(name: 'cdnet.ελ'),
        ] as $why => $info) {
            $e = $this->attempt($domain, $this->contact(['first_name' => 'Γιώργος']), ChangesOwner::OWNER, $info);
            $this->assertSame([], $this->sent('extdomain:op'), $why);
            $this->assertSame([], $this->sent('contact:create'), $why);
            $this->assertInstanceOf(RegistrarNotAsked::class, $e, $why);
        }
    }

    /** Guard (passes): the contact:create answer lost: nothing charged yet (the update never sent), not asked. */
    public function test_guard_a_lost_contact_answer_never_sends_the_change(): void
    {
        $domain = $this->domain();
        $this->registry($this->info(), $this->free($this->mow($domain)), 'not xml');

        $this->expectException(RegistrarNotAsked::class);
        try {
            (new GrEppRegistrar)->changeOwner($domain, $this->contact(['first_name' => 'Γιώργος']), ChangesOwner::OWNER, $this->credentials());
        } finally {
            $this->assertSame([], $this->sent('extdomain:op'));
        }
    }

    /** Guard (passes): a holder without a phone: refused before a contact is made. */
    public function test_guard_a_holder_without_a_phone_is_never_made(): void
    {
        $domain = $this->domain();
        $e = $this->attempt($domain, $this->contact(['phone' => null]), ChangesOwner::OWNER, $this->info());

        $this->assertSame([], $this->sent('contact:create'));
        $this->assertInstanceOf(RegistrarNotAsked::class, $e);
    }
}
