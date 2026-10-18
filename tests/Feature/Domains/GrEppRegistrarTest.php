<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Services\Domains\DomainNotFoundAtRegistrar;
use App\Services\Domains\DomainRegistrarNotConfigured;
use App\Services\Domains\DomainRegistrarRegistry;
use App\Services\Domains\RegistrarRefused;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\DomainChanges;
use App\Support\Domains\DomainRegistrarCredentials;
use Illuminate\Support\Carbon;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * G3: the .gr registry's driver, reads only (docs/domains/grepp.md). Against the registry's own answers (the guide's
 * examples, through the package's fake transport): ping, availability (restricted is not free), a domain's truth
 * (the expiry day as written, UTC; pendingCreate, pendingDelete and the 15 days after expiry), contacts; a domain of
 * another registrar is not ours; writes refused before anything is sent until G5.
 */
class GrEppRegistrarTest extends TestCase
{
    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new FakeTransport;
        GrEppRegistrar::$transport = fn () => $this->transport;
        GrEppRegistrar::reset();
    }

    protected function tearDown(): void
    {
        GrEppRegistrar::$transport = null;
        GrEppRegistrar::reset();
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The guide's example 36 is managed by «registry»: that is our user here. */
    private function credentials(string $username = 'registry'): DomainRegistrarCredentials
    {
        return new DomainRegistrarCredentials(['username' => $username, 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], sandbox: false);
    }

    private function loggedIn(): FakeTransport
    {
        return $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml');
    }

    /** The registry's answer, its clTRID echoing ours (the fake transport fills «ECHO»; Session checks it). */
    private function answer(string $fixture, array $replace = []): void
    {
        $body = strtr((string) file_get_contents(base_path("packages/gr-epp/tests/fixtures/v4.3/{$fixture}.xml")), $replace);
        $body = (string) preg_replace('#<clTRID>[^<]*</clTRID>#', '<clTRID>ECHO</clTRID>', $body);
        $this->transport->queue(new HttpResponse(200, $body));
    }

    public function test_it_is_a_registrar_with_its_connection_fields(): void
    {
        $this->assertInstanceOf(GrEppRegistrar::class, app(DomainRegistrarRegistry::class)->for('grepp'));
        $this->assertSame(['username', 'password', 'registrar_id', 'contact_prefix', 'whois_username', 'whois_password'], array_keys(config('domains.registrar_fields.grepp')));
        $this->assertTrue(config('domains.registrar_fields.grepp.password.secret') && config('domains.registrar_fields.grepp.whois_password.secret'));
        $this->assertFalse((new GrEppRegistrar)->capabilities()->supportsTransfer, 'reads only until G5');
    }

    public function test_ping_is_a_login_and_never_throws(): void
    {
        $this->loggedIn();
        $this->assertTrue((new GrEppRegistrar)->ping($this->credentials()));
        $this->assertSame('https://regepp.ics.forth.gr:700/epp/proxy', $this->transport->sent[0]['url']);

        GrEppRegistrar::reset();
        $this->transport = new FakeTransport;
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->result(2200, 'Λάθος ταυτοποίηση χρήστη');
        $this->assertFalse((new GrEppRegistrar)->ping($this->credentials()));

        $this->assertFalse((new GrEppRegistrar)->ping(new DomainRegistrarCredentials([])), 'no credentials: false, not a throw');
    }

    public function test_availability_free_taken_and_restricted(): void
    {
        $this->transport = $this->transportWith(fn () => $this->answer('32-domain-check-response'));
        $this->assertTrue((new GrEppRegistrar)->checkAvailability('forth.gr', $this->credentials())->available);

        GrEppRegistrar::reset();
        $this->transport = $this->transportWith(fn () => $this->answer('34-domain-check-gov.gr-response'));
        $restricted = (new GrEppRegistrar)->checkAvailability('Forth.gr', $this->credentials());
        $this->assertFalse($restricted->available);
        $this->assertStringStartsWith('restricted:', (string) $restricted->reason);
    }

    public function test_a_domains_truth_with_the_registrys_day(): void
    {
        Carbon::setTestNow('2013-01-10 10:00:00');
        $this->transport = $this->transportWith(fn () => $this->answer('36-domain-info-response'));
        $result = (new GrEppRegistrar)->syncDomain(new Domain(['fqdn' => 'forth.gr']), $this->credentials());

        $this->assertSame(['2013-03-20', '2011-03-21', DomainStatus::Active, 'dcaa15928758c4a15afcedd7420542c24-gr'],
            [$result->expiresAt, $result->registeredAt, $result->status, $result->registrarDomainId]);
        $this->assertSame(['ns14.forth.gr', 'ns15.forth.gr', 'ns.example.com'], $result->nameservers);
        $this->assertSame(['registrant' => 'reg_gsa', 'admin' => 'reg_gsa', 'tech' => 'reg_gsa', 'billing' => 'reg_gsa'], $result->contactHandles);
        $this->assertStringContainsString('regulatorHold', (string) $result->details['regulator_statuses']);
        $this->assertSame('63161', $result->details['protocol']);
        $this->assertStringContainsString('failed', (string) $result->details['ds_check']);
        $this->assertArrayNotHasKey('authInfo', $result->details);
    }

    public function test_after_expiry_the_registrys_15_days_then_expired(): void
    {
        // Past its day (UTC): out of the zone, renewable for 15 days.
        Carbon::setTestNow('2013-03-25 10:00:00');
        $this->transport = $this->transportWith(fn () => $this->answer('36-domain-info-response'));
        $grace = (new GrEppRegistrar)->syncDomain(new Domain(['fqdn' => 'forth.gr']), $this->credentials());
        $this->assertSame([DomainStatus::Grace, '2013-04-04'], [$grace->status, $grace->graceEndsOn]);

        GrEppRegistrar::reset();
        Carbon::setTestNow('2013-04-10 10:00:00');
        $this->transport = $this->transportWith(fn () => $this->answer('36-domain-info-response'));
        $this->assertSame(DomainStatus::Expired, (new GrEppRegistrar)->syncDomain(new Domain(['fqdn' => 'forth.gr']), $this->credentials())->status);

        // pendingDelete says so itself; pendingCreate is a registration not assigned yet.
        GrEppRegistrar::reset();
        Carbon::setTestNow('2013-03-21 10:00:00');
        $this->transport = $this->transportWith(fn () => $this->answer('36-domain-info-response', ['<domain:registrant>' => '<domain:status s="pendingDelete"/><domain:registrant>']));
        $this->assertSame(DomainStatus::Grace, (new GrEppRegistrar)->syncDomain(new Domain(['fqdn' => 'forth.gr']), $this->credentials())->status);

        GrEppRegistrar::reset();
        $this->transport = $this->transportWith(fn () => $this->answer('36-domain-info-response', ['<domain:registrant>' => '<domain:status s="pendingCreate"/><domain:registrant>']));
        $this->assertSame(DomainStatus::PendingRegister, (new GrEppRegistrar)->syncDomain(new Domain(['fqdn' => 'forth.gr']), $this->credentials())->status);
    }

    public function test_another_registrars_or_no_such_domain_is_not_ours(): void
    {
        $this->transport = $this->transportWith(fn () => $this->answer('36-domain-info-response'));
        try {
            (new GrEppRegistrar)->syncDomain(new Domain(['fqdn' => 'forth.gr']), $this->credentials('myip1'));
            $this->fail('another registrar\'s');
        } catch (DomainNotFoundAtRegistrar $e) {
            $this->assertStringContainsString('another registrar', $e->getMessage());
        }

        GrEppRegistrar::reset();
        $this->transport = $this->transportWith(fn () => $this->transport->result(2303, 'Το αντικείμενο δεν υπάρχει'));
        $this->expectException(DomainNotFoundAtRegistrar::class);
        (new GrEppRegistrar)->syncDomain(new Domain(['fqdn' => 'nosuch.gr']), $this->credentials());
    }

    public function test_contacts_are_read_and_unknown_ones_are_null(): void
    {
        $this->transport = $this->transportWith(fn () => $this->answer('12-contact-info-response'));
        $contact = (new GrEppRegistrar)->getContact('reg_contact001', $this->credentials());
        $this->assertSame(['reg_contact001', 'Μητρώο Ονομάτων Internet με κατάληξη .gr.', 'ICS/FORTH', 'gr-tech@ics.forth.gr', 'Ν.Πλαστήρα 100', 'Βασιλικά Βουτών', 'Ηράκλειο', '70013', 'GR'],
            [$contact?->handle, $contact?->name, $contact?->org, $contact?->email, $contact?->address1, $contact?->address2, $contact?->city, $contact?->postcode, $contact?->country]);

        GrEppRegistrar::reset();
        $this->transport = $this->transportWith(fn () => $this->transport->result(2303));
        $this->assertNull((new GrEppRegistrar)->getContact('c60_nosuch', $this->credentials()));
    }

    public function test_review_fixes(): void
    {
        // 1: past its 15 days the registry deletes the name: «no such name» is its end, not «not ours».
        Carbon::setTestNow('2026-12-01 10:00:00');
        $this->transport = $this->transportWith(fn () => $this->transport->result(2303, 'Το αντικείμενο δεν υπάρχει'));
        $gone = (new GrEppRegistrar)->syncDomain(new Domain(['fqdn' => 'old.gr', 'expires_at' => '2026-11-01', 'status' => DomainStatus::Grace]), $this->credentials());
        $this->assertSame([DomainStatus::Deleted, true], [$gone->status, $gone->deadRecord]);

        // 2: awaiting the regulator is a registration not assigned yet; 10: only a domain pending here moves.
        GrEppRegistrar::reset();
        Carbon::setTestNow('2013-01-10 10:00:00');
        $regulator = ['<extdomain:status s="regulatorHold"/>' => '<extdomain:status s="pendingRegulatorApproval"/>'];
        $this->transport = $this->transportWith(fn () => $this->answer('36-domain-info-response', $regulator));
        $this->assertSame(DomainStatus::PendingRegister,
            (new GrEppRegistrar)->syncDomain(new Domain(['fqdn' => 'forth.gr', 'status' => DomainStatus::PendingRegister]), $this->credentials())->status);
        GrEppRegistrar::reset();
        $this->transport = $this->transportWith(fn () => $this->answer('36-domain-info-response', $regulator));
        $this->assertNull((new GrEppRegistrar)->syncDomain(new Domain(['fqdn' => 'forth.gr', 'status' => DomainStatus::Active]), $this->credentials())->status,
            'an imported active one keeps its status');

        // 3: the shop's names in one check, by the names asked (punycode asked, Unicode answered).
        GrEppRegistrar::reset();
        $this->transport = $this->transportWith(fn () => $this->answer('32-domain-check-response'));
        $results = (new GrEppRegistrar)->checkAvailabilityMany(['FORTH.gr', 'forth.gov.gr'], $this->credentials());
        $this->assertSame([true, false], [$results['FORTH.gr']->available, $results['forth.gov.gr']->available]);
        $this->assertSame(['hello', 'login', 'check'], $this->transport->kinds(), 'one check for both');

        // 5: ping on its own session, ended after; 8: bad credentials are «not configured».
        GrEppRegistrar::reset();
        $this->transport = $this->transportWith(fn () => $this->transport->fixture('08-logout-response.xml'));
        $this->assertTrue((new GrEppRegistrar)->ping($this->credentials()));
        $this->assertSame(['hello', 'login', 'logout'], $this->transport->kinds());
        $this->expectException(DomainRegistrarNotConfigured::class);
        (new GrEppRegistrar)->syncDomain(new Domain(['fqdn' => 'a.gr']), $this->credentials('ab'));
    }

    public function test_a_live_domain_of_ours_is_ours(): void
    {
        // Live (2026-10-18): domain:clID is the registrar («myip»), the EPP user «myip1».
        Carbon::setTestNow('2026-10-18 10:00:00');
        $sync = function (array $credentials) {
            GrEppRegistrar::reset();
            $this->transport = $this->transportWith(function () {
                $body = (string) file_get_contents(base_path('packages/gr-epp/tests/fixtures/live/domain-info-response.xml'));
                $this->transport->queue(new HttpResponse(200, (string) preg_replace('#<clTRID>[^<]*</clTRID>#', '<clTRID>ECHO</clTRID>', $body)));
            });

            return (new GrEppRegistrar)->syncDomain(new Domain(['fqdn' => 'example1.gr']), new DomainRegistrarCredentials($credentials + ['password' => 'Secret-pw1'], sandbox: false));
        };
        $result = $sync(['username' => 'myip1']);
        $this->assertSame([DomainStatus::Active, '2029-03-01'], [$result->status, $result->expiresAt]);
        $this->assertSame(DomainStatus::Active, $sync(['username' => 'MYIP1'])->status, 'case');
        $this->assertSame(DomainStatus::Active, $sync(['username' => 'myip1', 'registrar_id' => '  '])->status, 'blank: the fallback');
        $this->assertSame(DomainStatus::Active, $sync(['username' => 'epp-user', 'registrar_id' => 'MYIP'])->status, 'set: used');
        $this->expectException(DomainNotFoundAtRegistrar::class);
        $sync(['username' => 'myip1', 'registrar_id' => 'other']); // set and not the clID: not ours
    }

    public function test_writes_are_refused_before_anything_is_sent(): void
    {
        $domain = new Domain(['fqdn' => 'cdnet.gr']);
        foreach ([
            fn (GrEppRegistrar $r) => $r->renew($domain, 2, $this->credentials()),
            fn (GrEppRegistrar $r) => $r->register($domain, 2, $this->credentials()),
            fn (GrEppRegistrar $r) => $r->transferIn($domain, 'code', $this->credentials()),
            fn (GrEppRegistrar $r) => $r->updateDomain($domain, new DomainChanges, $this->credentials()),
            fn (GrEppRegistrar $r) => $r->restore($domain, $this->credentials()),
            fn (GrEppRegistrar $r) => $r->getEppCode($domain, $this->credentials()),
        ] as $write) {
            try {
                $write(new GrEppRegistrar);
                $this->fail('refused');
            } catch (RegistrarRefused) {
                $this->addToAssertionCount(1); // nothing done: never an unconfirmed charge (D-17)
            }
        }
        $this->assertSame([], $this->transport->sent);
    }

    /** A fresh fake transport: hello, login, then what $then queues (answers echo «ECHO» as clTRID). */
    private function transportWith(\Closure $then): FakeTransport
    {
        $this->transport = new FakeTransport;
        GrEppRegistrar::$transport = fn () => $this->transport;
        $this->loggedIn();
        $then();

        return $this->transport;
    }
}
