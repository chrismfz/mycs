<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\User;
use App\Services\Domains\RegistrarNotAsked;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\RegistrarTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * Adversarial review of #198 (G5b-2, .gr transfers to us), written without the author's context. Each ADV test fails
 * on the PR as it stands; the REG tests are regressions that pass and stay.
 */
class GreppG5b2AdversarialTest extends TestCase
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

    private function credentials(bool $debug = false): DomainRegistrarCredentials
    {
        return new DomainRegistrarCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], sandbox: false, debug: $debug);
    }

    private function answer(int $code, string $resData = ''): void
    {
        $this->transport->queue(new HttpResponse(200, '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
            . "<result code=\"{$code}\"><msg>m</msg></result>" . ($resData !== '' ? "<resData>{$resData}</resData>" : '')
            . '<trID><clTRID>ECHO</clTRID><svTRID>s</svTRID></trID></response></epp>'));
    }

    private function fixture(string $name, array $replace = []): void
    {
        $body = strtr((string) file_get_contents(base_path("packages/gr-epp/tests/fixtures/{$name}")), $replace);
        $this->transport->queue(new HttpResponse(200, (string) preg_replace('#<clTRID>[^<]*</clTRID>#', '<clTRID>ECHO</clTRID>', $body)));
    }

    private function loggedIn(): void
    {
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml');
    }

    /** The check of a transfer's fresh registrant ids («c60_mtr<id>», «…a», «…b»): the first $taken taken. */
    private function freshIds(Domain $domain, int $taken = 0): void
    {
        $base = 'c60_mtr' . base_convert((string) $domain->id, 10, 36);
        $cds = '';
        foreach (['', 'a', 'b'] as $i => $suffix) {
            $cds .= '<contact:cd><contact:id avail="' . ($i < $taken ? '0' : '1') . "\">{$base}{$suffix}</contact:id></contact:cd>";
        }
        $this->answer(1000, "<contact:chkData xmlns:contact=\"urn:ietf:params:xml:ns:contact-1.0\">{$cds}</contact:chkData>");
    }

    private function contactCheck(string $id, bool $free): void
    {
        $this->answer(1000, '<contact:chkData xmlns:contact="urn:ietf:params:xml:ns:contact-1.0"><contact:cd><contact:id avail="' . ($free ? '1' : '0') . "\">{$id}</contact:id></contact:cd></contact:chkData>");
    }

    /** A transfer the registry has taken but not completed (1001, pending): the other registrar's to approve or reject. */
    private function pendingTransfer(): void
    {
        $this->transport->queue(new HttpResponse(200, '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
            . '<result code="1001"><msg>pending</msg></result><resData><domain:trnData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
            . '<domain:name>example2.gr</domain:name><domain:trStatus>pending</domain:trStatus><domain:reID>myip</domain:reID>'
            . '<domain:reDate>2026-10-18T10:00:00.0Z</domain:reDate><domain:acID>other</domain:acID><domain:acDate>2026-10-23T10:00:00.0Z</domain:acDate>'
            . '</domain:trnData></resData><trID><clTRID>ECHO</clTRID><svTRID>s</svTRID></trID></response></epp>'));
    }

    private function contact(array $attributes = []): Contact
    {
        return Contact::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'first_name' => 'Μαρία', 'last_name' => 'Παπαδοπούλου',
            'company_name' => null, 'email' => 'maria@example.test', 'phone' => '+30 210 0000000', 'address1' => 'Οδός 1', 'address2' => null,
            'city' => 'Αθήνα', 'state' => 'Αττική', 'postcode' => '10000', 'country' => 'GR']);
    }

    private function domain(): Domain
    {
        $contact = $this->contact();
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['is_active' => true, 'registrar_connection_id' => $this->gr->id, 'grace_days' => 15, 'redemption_days' => 0]);

        return Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => 'example2', 'tld' => 'gr', 'fqdn' => 'example2.gr',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->gr->id, 'status' => DomainStatus::PendingTransfer, 'expires_at' => null,
            'currency_code' => 'EUR', 'years' => 1, 'epp_code' => 'Code-1234', 'registrant_contact_id' => $contact->id, 'admin_contact_id' => $contact->id,
            'tech_contact_id' => $contact->id, 'billing_contact_id' => $contact->id])->fresh(['registrantContact', 'adminContact', 'techContact', 'billingContact']);
    }

    /** @return list<string> */
    private function bodies(string $element): array
    {
        return array_values(array_filter(array_column($this->transport->sent, 'body'), fn (string $b) => str_contains($b, $element)));
    }

    /**
     * ADV-1 (medium): a transfer answered «pending» (1001, trStatus pending), the name still the other registrar's at the
     * registry: its roles are «replaced» all the same. Contacts are made at the registry, admin/tech/billing handles
     * written here (c60_… while the registry has 533_…), and a domain:update sent for a name that is not ours. The roles
     * are to be replaced only once the name is ours (trStatus serverApproved/clientApproved, clID ours, not pendingTransfer).
     */
    public function test_adv1_a_transfer_still_pending_never_replaces_the_roles(): void
    {
        $domain = $this->domain();
        $ours = 'c60_mcs' . base_convert((string) $domain->admin_contact_id, 10, 36);
        $this->loggedIn();
        $this->freshIds($domain);
        $this->pendingTransfer();
        // The registry as it is: still the other registrar's, pendingTransfer.
        $this->fixture('live/domain-info-other-registrar-contacts-response.xml',
            ['<domain:clID>myip</domain:clID>' => '<domain:clID>other</domain:clID>', '<domain:status s="ok"/>' => '<domain:status s="pendingTransfer"/>']);
        // What the PR goes on to send (queued so the flow runs as it would live).
        $this->contactCheck($ours, true);
        $this->fixture('v4.3/14-contact-create-response.xml');
        $this->answer(2201); // domain:update of a name not ours
        $this->fixture('live/domain-info-other-registrar-contacts-response.xml',
            ['<domain:clID>myip</domain:clID>' => '<domain:clID>other</domain:clID>', '<domain:status s="ok"/>' => '<domain:status s="pendingTransfer"/>']);

        (new GrEppRegistrar)->transferIn($domain, 'Code-1234', $this->credentials());

        $this->assertSame([], $this->bodies('<contact:create'), 'no contact made at the registry for a name not ours yet');
        $this->assertSame([], $this->bodies('<domain:update'), 'no update of a name still the other registrar\'s');
        $this->assertNull($domain->fresh()->admin_handle, 'no handle here the registry does not have');
    }

    /**
     * ADV-2 (low): a transfer answered «pending» whose read after it fails is returned as Active («ok»): the answer said
     * it is not done. Status and rawStatus must follow trStatus (Active only when approved).
     */
    public function test_adv2_a_pending_transfer_not_read_back_is_never_active(): void
    {
        $domain = $this->domain();
        $this->loggedIn();
        $this->freshIds($domain);
        $this->pendingTransfer();
        $this->answer(2400); // domain:info for the roles: failing
        $this->answer(2400); // domain:info for the result: failing

        $result = (new GrEppRegistrar)->transferIn($domain, 'Code-1234', $this->credentials());

        $this->assertNotSame(DomainStatus::Active, $result->status, 'pending at the registry, not active');
        $this->assertNotSame('ok', $result->rawStatus);
    }

    /**
     * ADV-3 (medium): the fresh registrant id «c60_mcst<domain id, base 36>» lives in the same namespace as the contacts'
     * «c60_mcs<contact id, base 36>»: the contact whose id in base 36 is «t» + the domain's id gets the very same registry
     * id. After the transfer that registry contact holds the transferred owner's data: the contact can then never be used
     * at the registry (ensureContacts finds another name there and refuses), or, the same name and email, is bound to
     * the owner's copy. The registrant's id must be outside the contacts' namespace (e.g. a hyphen: c60_mt-<id>).
     */
    public function test_adv3_the_fresh_registrant_id_is_never_a_contacts_id(): void
    {
        $domain = $this->domain();
        $registrant = 'c60_mtr' . base_convert((string) $domain->id, 10, 36);
        $clashing = $this->contact(['first_name' => 'Άλλος', 'last_name' => 'Πελάτης', 'email' => 'other@example.test']);
        $clashing->forceFill(['id' => (int) base_convert('t' . base_convert((string) $domain->id, 10, 36), 36, 10)])->save();
        $this->loggedIn();
        $this->freshIds($domain);
        $this->fixture('v4.3/62-domain-transfer-response.xml', ['forth.gr' => 'example2.gr']);
        $this->answer(2400); // after it: not read (the roles kept; irrelevant here)
        $this->answer(2400);

        (new GrEppRegistrar)->transferIn($domain, 'Code-1234', $this->credentials());

        $used = (string) $domain->fresh()->registrant_handle;
        $contactsId = 'c60_mcs' . base_convert((string) $clashing->id, 10, 36);
        $this->assertNotSame(strtolower($contactsId), strtolower($used), "the transfer's registrant {$used} is contact #{$clashing->id}'s registry id");
    }

    /**
     * ADV-4 (low): a contact:check whose answer does not name the id asked is taken as «taken» and the next suffix tried,
     * up to 27 checks; ensureContacts refuses such an answer at once (review 5 of #192). Not an answer: not asked, at once.
     */
    public function test_adv4_a_check_answering_another_id_is_not_taken_as_taken(): void
    {
        $domain = $this->domain();
        $this->loggedIn();
        for ($i = 0; $i < 27; $i++) {
            $this->contactCheck('c60_somethingelse', true);
        }

        try {
            (new GrEppRegistrar)->transferIn($domain, 'Code-1234', $this->credentials());
        } catch (RegistrarNotAsked) {
        }

        $this->assertSame([], $this->bodies('<domain:transfer'));
        $this->assertLessThanOrEqual(1, count($this->bodies('<contact:check')), 'one unanswered check is enough to stop');
    }

    /** REG-1: the code is never in the debug log, the transactions or the result's details. */
    public function test_reg1_the_code_never_in_the_debug_log_transactions_or_details(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message . json_encode($e->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });
        $domain = $this->domain();
        $mark = app(RegistrarTransactions::class)->mark();
        $this->loggedIn();
        $this->freshIds($domain);
        $this->fixture('v4.3/62-domain-transfer-response.xml', ['forth.gr' => 'example2.gr']);
        $this->answer(2400);
        $this->answer(2400);

        $result = (new GrEppRegistrar)->transferIn($domain, 'Zq-Secret-77', $this->credentials(debug: true));

        $this->assertStringContainsString('Zq-Secret-77', $this->bodies('<domain:transfer')[0], 'sent');
        $this->assertNotSame([], $logged, 'the debug log written');
        foreach ($logged as $line) {
            $this->assertStringNotContainsString('Zq-Secret-77', $line);
        }
        $this->assertStringNotContainsString('Zq-Secret-77', (string) json_encode(app(RegistrarTransactions::class)->since($mark)));
        $this->assertStringNotContainsString('Zq-Secret-77', (string) json_encode($result->details));
    }

    /** REG-2: a role already ours at the registry is neither added again nor removed; the others' removed, none left empty. */
    public function test_reg2_a_role_already_ours_is_kept_and_never_left_empty(): void
    {
        $domain = $this->domain();
        $ours = 'c60_mcs' . base_convert((string) $domain->admin_contact_id, 10, 36);
        $domain->forceFill(['admin_handle' => $ours, 'tech_handle' => $ours, 'billing_handle' => $ours])->save();
        $this->loggedIn();
        $this->freshIds($domain);
        $this->fixture('v4.3/62-domain-transfer-response.xml', ['forth.gr' => 'example2.gr']);
        $this->fixture('live/domain-info-other-registrar-contacts-response.xml', ['<domain:contact type="admin">533_Example002</domain:contact>' => '<domain:contact type="admin">' . strtoupper($ours) . '</domain:contact><domain:contact type="admin">533_Example002</domain:contact>']);
        $this->answer(1000);
        $this->answer(2400);

        $result = (new GrEppRegistrar)->transferIn($domain, 'Code-1234', $this->credentials());

        $this->assertSame('replaced', $result->details['contacts']);
        $update = $this->bodies('<domain:update')[0];
        $this->assertStringNotContainsString(strtoupper($ours), $update, 'ours never removed');
        $this->assertSame(1, substr_count($update, '<domain:contact type="admin">'), 'only the other registrar\'s removed, none added');
        $this->assertSame(2, substr_count($update, '<domain:contact type="tech">'), 'tech: ours added, theirs removed');
    }
}
