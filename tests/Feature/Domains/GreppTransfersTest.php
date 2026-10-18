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
use App\Services\Domains\RegistrarOutcomeUnknown;
use App\Services\Domains\RegistrarRefused;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * G5b-2 of grepp: a .gr name moved to us with its code. The registrant into a contact id of ours not yet there (the
 * registry copies it: its name never changes); approved at once, nameservers kept; then admin, tech and billing (the
 * previous registrar's 533_) replaced by ours, a failure there never undoing the transfer. Before the transfer: not
 * asked; its answer lost: maybe done.
 */
class GreppTransfersTest extends TestCase
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

    private function fixture(string $name, array $replace = []): void
    {
        $body = (string) file_get_contents(base_path("packages/gr-epp/tests/fixtures/{$name}"));
        $body = strtr($body, $replace);
        $this->transport->queue(new HttpResponse(200, (string) preg_replace('#<clTRID>[^<]*</clTRID>#', '<clTRID>ECHO</clTRID>', $body)));
    }

    private function loggedIn(): void
    {
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml');
    }

    private function contactCheck(string $id, bool $free): void
    {
        $this->answer(1000, '<contact:chkData xmlns:contact="urn:ietf:params:xml:ns:contact-1.0"><contact:cd><contact:id avail="' . ($free ? '1' : '0') . "\">{$id}</contact:id></contact:cd></contact:chkData>");
    }

    private function domain(): Domain
    {
        $contact = Contact::factory()->create(['client_id' => $this->user->client()->id, 'first_name' => 'Μαρία', 'last_name' => 'Παπαδοπούλου',
            'company_name' => null, 'email' => 'maria@example.test', 'phone' => '+30 210 0000000', 'address1' => 'Οδός 1', 'address2' => null,
            'city' => 'Αθήνα', 'state' => 'Αττική', 'postcode' => '10000', 'country' => 'GR']);
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

    public function test_a_name_moved_to_us_its_registrant_copied_and_its_roles_ours(): void
    {
        $domain = $this->domain();
        $registrant = 'c60_mcst' . base_convert((string) $domain->id, 10, 36);
        $ours = 'c60_mcs' . base_convert((string) $domain->admin_contact_id, 10, 36);
        $this->loggedIn();
        $this->contactCheck($registrant, true);
        $this->fixture('v4.3/62-domain-transfer-response.xml', ['forth.gr' => 'example2.gr']);
        $this->fixture('live/domain-info-other-registrar-contacts-response.xml'); // 533_ on the roles
        $this->contactCheck($ours, true);
        $this->fixture('v4.3/14-contact-create-response.xml');
        $this->answer(1000); // domain:update
        $this->fixture('live/domain-info-other-registrar-contacts-response.xml');

        $result = (new GrEppRegistrar)->transferIn($domain, 'Code-1234', $this->credentials());

        $transfer = $this->bodies('<domain:transfer')[0];
        $this->assertStringContainsString('op="request"', $transfer);
        $this->assertStringContainsString("<extdomain:registrantid>{$registrant}</extdomain:registrantid>", $transfer);
        $update = $this->bodies('<domain:update')[0];
        foreach (['admin', 'tech', 'billing'] as $role) {
            $this->assertStringContainsString("<domain:contact type=\"{$role}\">{$ours}</domain:contact>", $update);
            $this->assertStringContainsString("<domain:contact type=\"{$role}\">533_Example002</domain:contact>", $update);
        }
        $this->assertStringNotContainsString('<domain:ns>', $update, 'its nameservers kept');
        $this->assertSame([DomainStatus::Active, '2028-03-01', 'replaced', 'serverApproved'],
            [$result->status, $result->expiresAt, $result->details['contacts'], $result->details['transfer']]);
        $this->assertSame($registrant, $domain->fresh()->registrant_handle);
    }

    public function test_a_failure_replacing_the_roles_never_undoes_the_transfer(): void
    {
        $domain = $this->domain();
        $this->loggedIn();
        $this->contactCheck('c60_mcst' . base_convert((string) $domain->id, 10, 36), false); // an earlier transfer's
        $this->contactCheck('c60_mcst' . base_convert((string) $domain->id, 10, 36) . 'a', true);
        $this->fixture('v4.3/62-domain-transfer-response.xml', ['forth.gr' => 'example2.gr']);
        $this->answer(2400); // domain:info after it: the registry failing

        $result = (new GrEppRegistrar)->transferIn($domain, 'Code-1234', $this->credentials());

        $this->assertStringContainsString('registrantid>c60_mcst' . base_convert((string) $domain->id, 10, 36) . 'a<', $this->bodies('<domain:transfer')[0]);
        $this->assertSame(DomainStatus::Active, $result->status);
        $this->assertStringStartsWith('not replaced', (string) $result->details['contacts']);
    }

    public function test_before_it_not_asked_refused_or_its_answer_lost(): void
    {
        $domain = $this->domain();
        $thrown = null;
        try {
            (new GrEppRegistrar)->transferIn($domain, ' ', $this->credentials());
        } catch (RegistrarNotAsked $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown);
        $this->assertSame([], $this->transport->sent);

        $this->loggedIn();
        $this->contactCheck('c60_mcst' . base_convert((string) $domain->id, 10, 36), true);
        $this->answer(2201); // a wrong code
        $refused = null;
        try {
            (new GrEppRegistrar)->transferIn($domain, 'Wrong-1', $this->credentials());
        } catch (RegistrarRefused $e) {
            $refused = $e;
        }
        $this->assertNotNull($refused);
        $this->assertNull($domain->fresh()->registrant_handle);

        $this->contactCheck('c60_mcst' . base_convert((string) $domain->id, 10, 36), true);
        $this->transport->queue(new HttpResponse(502, 'Bad gateway'));
        $this->expectException(RegistrarOutcomeUnknown::class);
        (new GrEppRegistrar)->transferIn($domain, 'Code-1234', $this->credentials());
    }
}
