<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\Ordering\DomainOrderFulfilment;
use App\Services\Domains\RegistrarNotAsked;
use App\Services\Domains\RegistrarRefused;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use RuntimeException;
use Tests\TestCase;

/**
 * G5a of grepp: the .gr registry's writes through the driver. A registration makes its contacts ours at the registry
 * (c60_mcs…, once per contact), creates a missing external nameserver, refuses a missing .gr one (its glue first), and
 * keeps the protocol; a renewal sends the current expiry day read fresh; staff recall a registration within 5 days.
 * Anything failing before the charge is «not asked»; a lost answer to the charge is «maybe done».
 */
class GreppWritesTest extends TestCase
{
    use RefreshDatabase;

    private FakeTransport $transport;

    private DomainRegistrarConnection $gr;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
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
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function credentials(): DomainRegistrarCredentials
    {
        return new DomainRegistrarCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], sandbox: false);
    }

    /** An answer of the registry: a result, data, extension; its clTRID echoed. */
    private function answer(int $code, string $resData = '', string $extension = '', string $msg = 'ok'): void
    {
        $this->transport->queue(new HttpResponse(200, '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
            . "<result code=\"{$code}\"><msg>{$msg}</msg></result>"
            . ($resData !== '' ? "<resData>{$resData}</resData>" : '') . ($extension !== '' ? "<extension>{$extension}</extension>" : '')
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

    private function contactCheck(string $id, bool $free): void
    {
        $this->answer(1000, '<contact:chkData xmlns:contact="urn:ietf:params:xml:ns:contact-1.0"><contact:cd><contact:id avail="' . ($free ? '1' : '0') . "\">{$id}</contact:id></contact:cd></contact:chkData>");
    }

    /** @param  array<string, bool>  $hosts  name => free (not at the registry) */
    private function hostCheck(array $hosts): void
    {
        $cds = '';
        foreach ($hosts as $host => $free) {
            $cds .= '<host:cd><host:name avail="' . ($free ? '1' : '0') . "\">{$host}</host:name></host:cd>";
        }
        $this->answer(1000, "<host:chkData xmlns:host=\"urn:ietf:params:xml:ns:host-1.0\">{$cds}</host:chkData>");
    }

    private function domain(array $nameservers = ['ns1.myip.gr', 'ns2.example.com'], array $attributes = []): Domain
    {
        $contact = Contact::factory()->create(['client_id' => $this->user->client()->id, 'first_name' => 'Μαρία', 'last_name' => 'Παπαδοπούλου',
            'company_name' => null, 'email' => 'maria@example.test', 'phone' => '+30 210 0000000', 'address1' => 'Οδός 1', 'address2' => null,
            'city' => 'Αθήνα', 'state' => 'Αττική', 'postcode' => '10000', 'country' => 'GR']);
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['is_active' => true, 'registrar_connection_id' => $this->gr->id, 'grace_days' => 15, 'redemption_days' => 0]);
        $domain = Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'example', 'tld' => 'gr', 'fqdn' => 'example.gr',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->gr->id, 'status' => DomainStatus::PendingRegister, 'expires_at' => null,
            'currency_code' => 'EUR', 'years' => 2, 'registrant_contact_id' => $contact->id, 'admin_contact_id' => $contact->id,
            'tech_contact_id' => $contact->id, 'billing_contact_id' => $contact->id]);
        foreach ($nameservers as $i => $host) {
            $domain->nameservers()->create(['host' => $host, 'sort' => $i]);
        }

        return $domain->fresh(['nameservers', 'registrantContact', 'adminContact', 'techContact', 'billingContact']);
    }

    /** @return list<string> the bodies sent, by the command they carried */
    private function sent(string $kind): array
    {
        return array_values(array_map(fn ($r) => $r['body'], array_filter($this->transport->sent,
            fn ($r) => str_contains($r['body'], "<{$kind}") || str_contains($r['body'], ":{$kind} "))));
    }

    public function test_a_registration_makes_its_contact_and_host_then_registers_and_keeps_the_protocol(): void
    {
        $domain = $this->domain();
        $id = 'c60_mcs' . base_convert((string) $domain->registrant_contact_id, 10, 36);
        $this->loggedIn();
        $this->contactCheck($id, true);
        $this->fixture('v4.3/14-contact-create-response.xml');
        $this->hostCheck(['ns1.myip.gr' => false, 'ns2.example.com' => true]);
        $this->answer(1000); // host:create ns2.example.com
        $this->fixture('v4.3/38-domain-create-response.xml');

        $result = (new GrEppRegistrar)->register($domain, 2, $this->credentials());

        $this->assertSame([DomainStatus::PendingRegister, '66155'], [$result->status, $result->details['protocol']]);
        $this->assertSame(['registrant' => $id, 'admin' => $id, 'tech' => $id, 'billing' => $id], $result->contactHandles);
        $this->assertSame($id, $domain->fresh()->registrant_handle, 'kept at once');
        $kinds = $this->transport->kinds();
        $this->assertSame(['hello', 'login', 'check', 'create', 'check', 'create', 'create'], $kinds, 'the contact checked once for its four roles');
        $contact = $this->transport->sent[3]['body'];
        $this->assertStringContainsString('<contact:postalInfo type="loc">', $contact);
        $this->assertStringNotContainsString('type="int"', $contact);
        $this->assertStringNotContainsString('disclose', $contact);
        $this->assertStringContainsString('<contact:voice>+30.2100000000</contact:voice>', $contact);
        $this->assertStringContainsString('<host:name>ns2.example.com</host:name>', $this->transport->sent[5]['body']);
        $create = $this->transport->sent[6]['body'];
        $this->assertStringContainsString('<domain:period unit="y">2</domain:period>', $create);
        $this->assertStringContainsString("<domain:registrant>{$id}</domain:registrant>", $create);
    }

    public function test_a_paid_order_is_registered_and_its_protocol_kept_for_its_recall(): void
    {
        Mail::fake();
        $order = Order::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'EUR']);
        $domain = $this->domain(['ns1.myip.gr', 'ns2.myip.gr'], ['order_id' => $order->id, 'registered_at' => null]);
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'EUR']);
        InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => '24.00', 'description' => 'example.gr',
            'reference_type' => Domain::class, 'reference_id' => $domain->id, 'kind' => InvoiceItem::KIND_REGISTER]);
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_PAID]); // its payment is not this test's
        $id = 'c60_mcs' . base_convert((string) $domain->registrant_contact_id, 10, 36);
        $this->loggedIn();
        $this->contactCheck($id, false);
        $this->hostCheck(['ns1.myip.gr' => false, 'ns2.myip.gr' => false]);
        $this->fixture('v4.3/38-domain-create-response.xml');
        // The sync after it gets no answer: retried later, the order done all the same.

        $this->assertSame(DomainOrderFulfilment::SENT, app(DomainOrderFulfilment::class)->fulfil($domain->id));

        $this->assertSame('66155', $domain->fresh()->meta['registrar']['protocol'] ?? null);
        $this->assertSame(1, collect($this->transport->kinds())->filter(fn ($k) => $k === 'create')->count(), 'registered once');
    }

    public function test_an_existing_contact_of_ours_is_used_not_made_again(): void
    {
        $domain = $this->domain(['ns1.myip.gr', 'ns2.myip.gr']);
        $id = 'c60_mcs' . base_convert((string) $domain->registrant_contact_id, 10, 36);
        $this->loggedIn();
        $this->contactCheck($id, false); // made by a call whose answer was lost
        $this->hostCheck(['ns1.myip.gr' => false, 'ns2.myip.gr' => false]);
        $this->fixture('v4.3/38-domain-create-response.xml');

        (new GrEppRegistrar)->register($domain, 2, $this->credentials());

        $this->assertSame(['hello', 'login', 'check', 'check', 'create'], $this->transport->kinds());
    }

    public function test_what_fails_before_the_charge_is_not_asked(): void
    {
        // Odd years: nothing sent at all.
        $domain = $this->domain();
        $thrown = null;
        try {
            (new GrEppRegistrar)->register($domain, 3, $this->credentials());
        } catch (RegistrarNotAsked $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown);
        $this->assertSame([], $this->transport->sent);

        // A .gr nameserver missing at the registry: its glue first; no domain:create.
        $id = 'c60_mcs' . base_convert((string) $domain->registrant_contact_id, 10, 36);
        $this->loggedIn();
        $this->contactCheck($id, false);
        $this->hostCheck(['ns1.myip.gr' => true, 'ns2.example.com' => false]);
        $thrown = null;
        try {
            (new GrEppRegistrar)->register($domain, 2, $this->credentials());
        } catch (RegistrarNotAsked $e) {
            $thrown = $e;
        }
        $this->assertStringContainsString('glue', (string) $thrown?->getMessage());
        $this->assertNotContains('create', $this->transport->kinds());
    }

    public function test_the_charge_refused_or_unanswered(): void
    {
        $domain = $this->domain(['ns1.myip.gr', 'ns2.myip.gr']);
        $id = 'c60_mcs' . base_convert((string) $domain->registrant_contact_id, 10, 36);
        $prepared = function (bool $checksContact) use ($id) {
            $this->loggedIn();
            if ($checksContact) {
                $this->contactCheck($id, false);
            }
            $this->hostCheck(['ns1.myip.gr' => false, 'ns2.myip.gr' => false]);
        };

        // Refused (taken): nothing done, the registry's words kept.
        $prepared(true);
        $this->fixture('v4.3/38.1-domain-create-response-when-domain-is-already-registered.xml');
        $refused = null;
        try {
            (new GrEppRegistrar)->register($domain, 2, $this->credentials());
        } catch (RegistrarRefused $e) {
            $refused = $e->getMessage();
        }
        $this->assertStringContainsString('2302', (string) $refused);

        // No usable answer: maybe done (never «not asked», never sent again blindly).
        GrEppRegistrar::reset();
        $this->transport = new FakeTransport;
        $prepared(false); // its handle kept by the first call: not checked again
        $this->transport->queue(new HttpResponse(502, 'Bad gateway'));
        $unknown = null;
        try {
            (new GrEppRegistrar)->register($domain, 2, $this->credentials());
        } catch (RuntimeException $e) {
            $unknown = $e;
        }
        $this->assertNotNull($unknown);
        $this->assertNotInstanceOf(RegistrarNotAsked::class, $unknown);
        $this->assertNotInstanceOf(RegistrarRefused::class, $unknown);
    }

    public function test_a_renewal_sends_the_current_expiry_read_fresh(): void
    {
        $domain = $this->domain(['ns1.myip.gr'], ['status' => DomainStatus::Active, 'expires_at' => '2029-03-01', 'fqdn' => 'example1.gr', 'sld' => 'example1']);
        $this->loggedIn();
        $this->fixture('live/domain-info-response.xml'); // expiry 2029-03-01 at the registry
        $this->fixture('v4.3/52-domain-renew-response.xml');
        $this->fixture('live/domain-info-response.xml');

        $result = (new GrEppRegistrar)->renew($domain, 2, $this->credentials());

        $renew = $this->transport->sent[3]['body'];
        $this->assertStringContainsString('<domain:curExpDate>2029-03-01</domain:curExpDate>', $renew);
        $this->assertStringContainsString('<domain:period unit="y">2</domain:period>', $renew);
        $this->assertSame(DomainStatus::Active, $result->status);

        $this->transport = new FakeTransport;
        GrEppRegistrar::reset();
        $this->expectException(RegistrarNotAsked::class);
        (new GrEppRegistrar)->renew($domain, 1, $this->credentials()); // odd: never asked
    }

    public function test_staff_recall_a_registration_within_five_days(): void
    {
        $domain = $this->domain(['ns1.myip.gr'], ['registered_at' => '2026-10-19', 'meta' => ['registrar' => ['protocol' => '66155']]]);
        $this->assertTrue(app(DomainManagementService::class)->recallable($domain));
        $this->loggedIn();
        $this->fixture('v4.3/60-domain-delete-recall-domain-creation-response.xml');

        app(DomainManagementService::class)->recall($domain, Staff::factory()->create());

        $this->assertStringContainsString('<extdomain:protocol>66155</extdomain:protocol>', $this->transport->sent[2]['body']);
        $this->assertStringContainsString('recallApplication', $this->transport->sent[2]['body']);
        $this->assertSame(DomainStatus::Cancelled, $domain->fresh()->status);
        $this->assertSame(DomainRegistrarLog::STATUS_OK, DomainRegistrarLog::query()->where('action', 'recall')->value('status'));

        // After 5 days, not any more.
        Carbon::setTestNow('2026-10-26 10:00:00');
        $this->assertFalse(app(DomainManagementService::class)->recallable($this->domain(['ns1.myip.gr'], ['sld' => 'late', 'fqdn' => 'late.gr', 'registered_at' => null, 'created_at' => '2026-10-20 09:00:00'])));
    }
}
