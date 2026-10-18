<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Order;
use App\Models\User;
use App\Services\Domains\Ordering\DomainOrderFulfilment;
use App\Services\Domains\RegistrarNotAsked;
use App\Services\Domains\RegistrarOutcomeUnknown;
use App\Services\Domains\RegistrarRefused;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
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
        $registrant = 'c60_mtr' . base_convert((string) $domain->id, 10, 36);
        $ours = 'c60_mcs' . base_convert((string) $domain->admin_contact_id, 10, 36);
        $this->loggedIn();
        $this->freshIds($domain);
        $this->fixture('v4.3/62-domain-transfer-response.xml', ['forth.gr' => 'example2.gr']);
        $this->fixture('live/domain-info-other-registrar-contacts-response.xml'); // 533_ on the roles
        $this->contactCheck($ours, true);
        $this->fixture('v4.3/14-contact-create-response.xml');
        $this->answer(1000); // domain:update
        // The owner as the registry copied it: the same email as the registrant contact here (no warning).
        $this->answer(1000, '<contact:infData xmlns:contact="urn:ietf:params:xml:ns:contact-1.0"><contact:id>' . $registrant . '</contact:id><contact:roid>r</contact:roid>'
            . '<contact:postalInfo type="loc"><contact:name>Μαρία Παπαδοπούλου</contact:name><contact:addr><contact:city>Αθήνα</contact:city><contact:cc>GR</contact:cc></contact:addr></contact:postalInfo>'
            . '<contact:voice>+30.2100000000</contact:voice><contact:email>maria@example.test</contact:email><contact:clID>myip</contact:clID></contact:infData>');
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
        $this->assertArrayNotHasKey('registrant', $result->details, 'the same owner: no warning');
    }

    public function test_a_failure_replacing_the_roles_never_undoes_the_transfer(): void
    {
        $domain = $this->domain();
        $this->loggedIn();
        $this->freshIds($domain, 1); // the first taken: an earlier transfer's
        $this->fixture('v4.3/62-domain-transfer-response.xml', ['forth.gr' => 'example2.gr']);
        $this->answer(2400); // domain:info after it: the registry failing

        $result = (new GrEppRegistrar)->transferIn($domain, 'Code-1234', $this->credentials());

        $this->assertStringContainsString('registrantid>c60_mtr' . base_convert((string) $domain->id, 10, 36) . 'a<', $this->bodies('<domain:transfer')[0]);
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
        $this->freshIds($domain);
        $this->answer(2201); // a wrong code
        $refused = null;
        try {
            (new GrEppRegistrar)->transferIn($domain, 'Wrong-1', $this->credentials());
        } catch (RegistrarRefused $e) {
            $refused = $e;
        }
        $this->assertNotNull($refused);
        $this->assertNull($domain->fresh()->registrant_handle);

        $this->freshIds($domain);
        $this->transport->queue(new HttpResponse(502, 'Bad gateway'));
        $this->expectException(RegistrarOutcomeUnknown::class);
        (new GrEppRegistrar)->transferIn($domain, 'Code-1234', $this->credentials());
    }

    public function test_an_ordered_transfer_keeps_why_its_roles_were_not_replaced(): void
    {
        // Review 2 of #198: the sync after the transfer replaces meta.registrar; the transfer's notes are kept apart.
        Mail::fake();
        config(['settings.mail_disable' => false]); // mails sent here (off by default in tests)
        $this->user->client()->ensurePrimaryContact()->update(['email' => 'maria@example.test']); // the mail's recipient
        $order = Order::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'EUR']);
        $domain = $this->domain();
        $domain->forceFill(['order_id' => $order->id, 'meta' => ['free_order' => true]])->save();
        $domain->adminContact->forceFill(['phone' => null])->save(); // the registry needs one: not replaced
        $this->loggedIn();
        $this->freshIds($domain);
        $this->fixture('v4.3/62-domain-transfer-response.xml', ['forth.gr' => 'example2.gr']);
        $this->fixture('live/domain-info-other-registrar-contacts-response.xml'); // the roles step reads it
        $this->contactCheck('c60_mcs' . base_convert((string) $domain->admin_contact_id, 10, 36), true);
        $this->answer(2400); // the owner's read: failing (no warning, nothing else)
        $this->fixture('live/domain-info-other-registrar-contacts-response.xml'); // the result
        $this->fixture('live/domain-info-other-registrar-contacts-response.xml'); // the sync after
        foreach (['admin', 'tech', 'billing'] as $role) {
            $this->answer(2201); // the previous registrar's 533_: not ours to read (never links it, never fails the sync)
        }

        $this->assertSame(DomainOrderFulfilment::SENT, app(DomainOrderFulfilment::class)->fulfil($domain->id));

        $transfer = $domain->fresh()->meta['transfer'] ?? [];
        $this->assertSame('serverApproved', $transfer['transfer'] ?? null);
        $this->assertStringContainsString('phone', (string) ($transfer['contacts'] ?? ''));
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);
        // Done at once: «transferred», not «started, 5 to 7 days» (G5b-3).
        $mails = Mail::queued(\App\Mail\Mail::class)->map(fn (\App\Mail\Mail $mail) => $mail->templateKey)->all();
        $this->assertContains('domain_transferred', $mails);
        $this->assertNotContains('domain_transfer_started', $mails);
    }
}
