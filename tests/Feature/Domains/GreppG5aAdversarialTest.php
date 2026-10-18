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
use App\Support\Domains\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use MyCS\GrEpp\Config;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #192 (G5a of grepp: .gr registration, renewal, recall, c60_ contacts), written without the
 * author's context. Failing tests are findings; passing ones are regressions kept for what turned out fine.
 * Exceptions are captured and asserted outside any catch (a fail() inside catch(RuntimeException) is swallowed).
 */
class GreppG5aAdversarialTest extends TestCase
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

    /** contact:info of one of ours at the registry: this contact's name (as made by an earlier call). */
    private function contactInfo(string $id, string $name = 'Μαρία Παπαδοπούλου'): void
    {
        $this->answer(1000, '<contact:infData xmlns:contact="urn:ietf:params:xml:ns:contact-1.0"><contact:id>' . $id . '</contact:id><contact:roid>r1-gr</contact:roid>'
            . '<contact:postalInfo type="loc"><contact:name>' . $name . '</contact:name><contact:addr><contact:street>Οδός 1</contact:street><contact:city>Αθήνα</contact:city><contact:cc>GR</contact:cc></contact:addr></contact:postalInfo>'
            . '<contact:voice>+30.2100000000</contact:voice><contact:email>maria@example.test</contact:email><contact:clID>myip</contact:clID></contact:infData>');
    }

    private function contactCheck(string $id, bool $free): void
    {
        $this->answer(1000, '<contact:chkData xmlns:contact="urn:ietf:params:xml:ns:contact-1.0"><contact:cd><contact:id avail="' . ($free ? '1' : '0') . "\">{$id}</contact:id></contact:cd></contact:chkData>");
    }

    /** @param  array<string, bool>  $hosts */
    private function hostCheck(array $hosts): void
    {
        $cds = '';
        foreach ($hosts as $host => $free) {
            $cds .= '<host:cd><host:name avail="' . ($free ? '1' : '0') . "\">{$host}</host:name></host:cd>";
        }
        $this->answer(1000, "<host:chkData xmlns:host=\"urn:ietf:params:xml:ns:host-1.0\">{$cds}</host:chkData>");
    }

    private function domain(array $nameservers = ['ns1.myip.gr', 'ns2.myip.gr'], array $attributes = []): Domain
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

    /** A paid registration order for a fresh domain. */
    private function paidOrder(array $attributes = []): Domain
    {
        $order = Order::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'EUR']);
        $domain = $this->domain(['ns1.myip.gr', 'ns2.myip.gr'], $attributes + ['order_id' => $order->id, 'registered_at' => null]);
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'EUR']);
        InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => '24.00', 'description' => $domain->fqdn,
            'reference_type' => Domain::class, 'reference_id' => $domain->id, 'kind' => InvoiceItem::KIND_REGISTER]);
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_PAID]);

        return $domain;
    }

    private function idOf(Domain $domain): string
    {
        return 'c60_mcs' . base_convert((string) $domain->registrant_contact_id, 10, 36);
    }

    // ---------------------------------------------------------------- findings

    /**
     * ADV-1 (high). A contact id «c60_mcs<base36 contact id>» that already exists at the registry is used as the new
     * domain's registrant without reading it. The id is derived from a local auto-increment id only, so whoever made
     * c60_mcs1 first owns every later domain of «contact 1»: a test/dev instance on the production account (live tests
     * with the myip.gr creds are allowed), a re-import or DB reset at cut-over, or simply a contact edited after its
     * registry copy was made (the registry keeps the old name/address; no contact:update is ever sent). Expected: the
     * existing registry contact is read (contact:info) and compared before it becomes a registrant.
     */
    public function test_adv1_an_existing_registry_contact_is_read_before_it_becomes_the_registrant(): void
    {
        $domain = $this->domain();
        $this->loggedIn();
        $this->contactCheck($this->idOf($domain), false); // exists already at the registry
        $this->fixture('v4.3/12-contact-info-response.xml'); // what it holds: somebody else entirely
        $this->hostCheck(['ns1.myip.gr' => false, 'ns2.myip.gr' => false]);
        $this->fixture('v4.3/38-domain-create-response.xml');

        $thrown = null;
        try {
            (new GrEppRegistrar)->register($domain, 2, $this->credentials());
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $kinds = $this->transport->kinds();
        $this->assertContains('info', $kinds, 'the existing registry contact was never read: used blindly as the registrant ('
            . implode(',', $kinds) . ($thrown ? '; ' . $thrown->getMessage() : '') . ')');
        $creates = array_filter($this->transport->sent, fn ($r) => str_contains($r['body'], '<domain:create'));
        $this->assertSame([], $creates, 'registered with a registry contact whose details are not this contact\'s');
    }

    /**
     * ADV-2 (low). A .gr connection without a contact prefix fails in prepare() with DomainRegistrarNotConfigured, which
     * is neither InvalidArgumentException nor GrEppException: it escapes as a plain RuntimeException, so the fulfilment
     * leaves its register log «pending» (maybe charged) although nothing was sent. The order then waits for staff to
     * «check the registrar» for a charge that never happened (D-17 state wrong in the safe direction).
     */
    public function test_adv2_a_missing_contact_prefix_is_not_asked_not_maybe_charged(): void
    {
        Mail::fake();
        $this->gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => ''], ['password']); // stored credentials merge: blank it
        $domain = $this->paidOrder();

        app(DomainOrderFulfilment::class)->fulfil($domain->id);

        $this->assertSame([], $this->transport->sent, 'nothing reached the registry');
        $this->assertSame(DomainRegistrarLog::STATUS_FAILED, DomainRegistrarLog::query()->where('domain_id', $domain->id)->where('action', 'register')->value('status'),
            'nothing was sent, yet the registration is left «pending» (maybe charged)');
    }

    /**
     * ADV-3 (low). The account lock busy (another command for 60 s) is a RuntimeException, i.e. «maybe done» for the
     * fulfilment and the renewal service, though nothing was sent. Expected: RegistrarNotAsked. (Waits the 60 s block.)
     */
    public function test_adv3_a_busy_account_lock_is_not_asked(): void
    {
        Carbon::setTestNow(); // Lock::block measures its wait with now(): a frozen clock never times out
        $config = new Config('myip1', 'Secret-pw1');
        $held = Cache::lock('grepp-epp:' . sha1($config->endpoint . '|' . $config->clientId), 600);
        $this->assertTrue($held->get());
        $domain = $this->domain();

        $thrown = null;
        try {
            (new GrEppRegistrar)->register($domain, 2, $this->credentials());
        } catch (Throwable $e) {
            $thrown = $e;
        } finally {
            $held->release();
        }

        $this->assertSame([], $this->transport->sent);
        $this->assertInstanceOf(RegistrarNotAsked::class, $thrown, 'a lock never taken sent nothing: not an unconfirmed charge');
    }

    /**
     * ADV-4 (low). Phone::epp keeps a «+» only at the very start: «(+30) 210 1234567», a common way to write it, loses
     * its + and becomes a local number under +30: «+30.302101234567», a wrong number sent to the registry.
     */
    public function test_adv4_a_phone_written_with_a_bracketed_country_code(): void
    {
        $this->assertSame('+30.2101234567', Phone::epp('(+30) 210 1234567'));
    }

    /**
     * ADV-5 (low). recallable() falls back to created_at when registered_at is unknown: a domain imported or adopted
     * today (no order, never registered by us) shows «Recall the registration» for 5 days, and recall() then reads a
     * protocol from the registry and sends recallApplication for it. A recall is an irreversible deletion; it should be
     * offered only for a registration this instance made (an OK «register» log / meta.sent_at).
     */
    public function test_adv5_an_imported_domain_is_not_recallable(): void
    {
        $domain = $this->domain(['ns1.myip.gr'], ['status' => DomainStatus::Active, 'registered_at' => null, 'order_id' => null,
            'expires_at' => '2028-01-01', 'meta' => ['imported_from' => 'whmcs']]);

        $this->assertFalse(app(DomainManagementService::class)->recallable($domain), 'never registered here, yet recallable');
    }

    // ---------------------------------------------------------------- regressions (fine)

    /** Another registrar's handle (533_…) is never sent: ours is made for the contact. */
    public function test_another_registrars_handle_is_never_sent(): void
    {
        $domain = $this->domain();
        $domain->forceFill(['registrant_handle' => '533_abc', 'admin_handle' => '533_abc', 'tech_handle' => '533_abc', 'billing_handle' => '533_abc'])->saveQuietly();
        $domain = $domain->fresh(['nameservers', 'registrantContact', 'adminContact', 'techContact', 'billingContact']);
        $this->loggedIn();
        $this->contactCheck($this->idOf($domain), true);
        $this->fixture('v4.3/14-contact-create-response.xml');
        $this->hostCheck(['ns1.myip.gr' => false, 'ns2.myip.gr' => false]);
        $this->fixture('v4.3/38-domain-create-response.xml');

        (new GrEppRegistrar)->register($domain, 2, $this->credentials());

        $create = (string) collect($this->transport->sent)->last()['body'];
        $this->assertStringNotContainsString('533_', $create);
        $this->assertStringContainsString("<domain:registrant>{$this->idOf($domain)}</domain:registrant>", $create);
        $this->assertSame(1, collect($this->transport->kinds())->filter(fn ($k) => $k === 'create')->count() - 1, 'one contact made for four roles');
    }

    /** A contact of another account is never made at the registry nor registered: not asked. */
    public function test_a_contact_of_another_account_is_not_asked(): void
    {
        $domain = $this->domain();
        $other = Contact::factory()->create(['client_id' => User::factory()->create()->client()->id, 'phone' => '+30 210 1111111']);
        Domain::query()->whereKey($domain->id)->update(['admin_contact_id' => $other->id]);
        $domain = $domain->fresh(['nameservers', 'registrantContact', 'adminContact', 'techContact', 'billingContact']);
        $this->loggedIn();
        $this->contactCheck($this->idOf($domain), false);
        $this->contactInfo($this->idOf($domain));

        $thrown = null;
        try {
            (new GrEppRegistrar)->register($domain, 2, $this->credentials());
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(RegistrarNotAsked::class, $thrown);
        $this->assertNotContains('create', $this->transport->kinds());
    }

    /** A registration whose answer is lost stays pending; the next fulfilment never sends domain:create again. */
    public function test_a_lost_registration_answer_is_never_sent_again(): void
    {
        Mail::fake();
        $domain = $this->paidOrder();
        $this->loggedIn();
        $this->contactCheck($this->idOf($domain), false);
        $this->contactInfo($this->idOf($domain));
        $this->hostCheck(['ns1.myip.gr' => false, 'ns2.myip.gr' => false]);
        $this->transport->queue(new HttpResponse(502, 'Bad gateway'));

        $this->assertSame(DomainOrderFulfilment::STAFF, app(DomainOrderFulfilment::class)->fulfil($domain->id));
        $this->assertSame(DomainRegistrarLog::STATUS_PENDING, DomainRegistrarLog::query()->where('domain_id', $domain->id)->where('action', 'register')->value('status'));

        $before = count($this->transport->sent);
        $this->assertSame(DomainOrderFulfilment::STAFF, app(DomainOrderFulfilment::class)->fulfil($domain->id));
        $this->assertCount($before, $this->transport->sent, 'nothing sent again');
    }

    /** A renewal whose answer is lost is «maybe done» (never not-asked, which a caller could retry with a fresh expiry). */
    public function test_a_lost_renewal_answer_is_maybe_done(): void
    {
        $domain = $this->domain(['ns1.myip.gr'], ['status' => DomainStatus::Active, 'expires_at' => '2029-03-01', 'fqdn' => 'example1.gr', 'sld' => 'example1']);
        $this->loggedIn();
        $this->fixture('live/domain-info-response.xml');
        $this->transport->queue(new HttpResponse(200, 'not xml at all'));

        $thrown = null;
        try {
            (new GrEppRegistrar)->renew($domain, 2, $this->credentials());
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown);
        $this->assertNotInstanceOf(RegistrarNotAsked::class, $thrown);
        $this->assertNotInstanceOf(RegistrarRefused::class, $thrown);
    }

    /** An ordered domain never sent to the registry cannot be recalled (it would read and recall the name's protocol). */
    public function test_an_unsent_order_is_not_recalled(): void
    {
        $domain = $this->paidOrder();

        $thrown = null;
        try {
            app(DomainManagementService::class)->recall($domain, Staff::factory()->create());
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown);
        $this->assertSame([], $this->transport->sent);
        $this->assertSame(DomainStatus::PendingRegister, $domain->fresh()->status);
    }

    /** Recall past 5 days, or of a domain at a registrar without recall, is not offered. */
    public function test_recall_is_not_offered_late_or_elsewhere(): void
    {
        $late = $this->domain(['ns1.myip.gr'], ['status' => DomainStatus::Active, 'registered_at' => '2026-10-14', 'meta' => ['registrar' => ['protocol' => '1']]]);
        $this->assertFalse(app(DomainManagementService::class)->recallable($late));

        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $elsewhere = $this->domain(['ns1.myip.gr'], ['sld' => 'other', 'fqdn' => 'other.gr', 'status' => DomainStatus::Active,
            'registered_at' => '2026-10-19', 'registrar_connection_id' => $op->id]);
        $this->assertFalse(app(DomainManagementService::class)->recallable($elsewhere));
    }
}
