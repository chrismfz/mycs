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
use App\Models\ModuleAction;
use App\Models\Order;
use App\Models\User;
use App\Services\Domains\DomainNotFoundAtRegistrar;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\Ordering\DomainOrderFulfilment;
use App\Services\Domains\Registrars\GrEppRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * Adversarial review of #205 (G5b-3 of grepp): a sent registration the registry «no longer has» told to staff as a
 * Pending Module Action (refund or register again). Each test names the finding (ADV-n) it shows.
 */
class Pr205AdversarialTest extends TestCase
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

    /** A paid, sent, still pending registration of an order (as DomainOrderFulfilment leaves it). */
    private function sentRegistration(): Domain
    {
        $client = $this->user->client();
        $order = Order::query()->create(['client_id' => $client->id, 'currency_code' => 'EUR']);
        $contact = Contact::factory()->create(['client_id' => $client->id, 'first_name' => 'Μαρία', 'last_name' => 'Παπαδοπούλου',
            'company_name' => null, 'email' => 'maria@example.test', 'phone' => '+30 210 0000000', 'address1' => 'Οδός 1', 'address2' => null,
            'city' => 'Αθήνα', 'state' => 'Αττική', 'postcode' => '10000', 'country' => 'GR']);
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['is_active' => true, 'registrar_connection_id' => $this->gr->id, 'grace_days' => 15, 'redemption_days' => 0]);
        $domain = Domain::factory()->create(['client_id' => $client->id, 'sld' => 'example', 'tld' => 'gr', 'fqdn' => 'example.gr',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->gr->id, 'status' => DomainStatus::PendingRegister, 'expires_at' => null,
            'currency_code' => 'EUR', 'years' => 1, 'registrant_contact_id' => $contact->id, 'admin_contact_id' => $contact->id,
            'tech_contact_id' => $contact->id, 'billing_contact_id' => $contact->id, 'order_id' => $order->id,
            'meta' => ['sent_at' => '2026-10-19T10:00:00+00:00']]);
        foreach (['ns1.myip.gr', 'ns2.myip.gr'] as $i => $host) {
            $domain->nameservers()->create(['host' => $host, 'sort' => $i]);
        }
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'currency_code' => 'EUR']);
        $line = InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => '24.00', 'description' => 'example.gr',
            'reference_type' => Domain::class, 'reference_id' => $domain->id, 'kind' => InvoiceItem::KIND_REGISTER, 'applied_at' => now()]);
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'registrar_connection_id' => $this->gr->id, 'action' => 'register',
            'status' => DomainRegistrarLog::STATUS_OK, 'invoice_id' => $invoice->id, 'request' => ['years' => 1, 'line' => $line->id]]);

        return $domain->fresh();
    }

    /** One sync of the domain, the registry answering domain:info with $answer (a fresh EPP session each time). */
    private function syncAnswering(Domain $domain, callable $answer): void
    {
        GrEppRegistrar::reset();
        $this->transport = new FakeTransport;
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml');
        $answer($this->transport);
        try {
            app(DomainSyncService::class)->sync($domain->fresh());
        } catch (DomainNotFoundAtRegistrar) {
        }
    }

    private function noSuchName(): callable
    {
        return fn (FakeTransport $t) => $t->result(2303, 'Το αντικείμενο δεν υπάρχει');
    }

    private function openRegister(Domain $domain): ?ModuleAction
    {
        return ModuleAction::query()->open()->where('domain_id', $domain->id)->where('action', ModuleAction::DOMAIN_REGISTER)->first();
    }

    /**
     * ADV-1 (high). The .gr driver throws DomainNotFoundAtRegistrar with elsewhere:false also when the registry DOES give
     * the record (only to its sponsor: ours) and the connection's «Registrar id» is wrong (ADV-1 of #203). registrationGone
     * takes elsewhere === false as «the registry no longer has it» and tells staff to refund or register again: a live,
     * paid registration refunded on a setting.
     */
    public function test_adv1_a_wrong_registrar_id_is_not_a_registration_gone(): void
    {
        $domain = $this->sentRegistration();

        $this->syncAnswering($domain, fn (FakeTransport $t) => $t->queue(new HttpResponse(200, '<?xml version="1.0" encoding="UTF-8"?>'
            . '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>ok</msg></result><resData>'
            . '<domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>example.gr</domain:name><domain:roid>r-gr</domain:roid>'
            . '<domain:status s="pendingCreate"/><domain:ns><domain:hostObj>ns1.myip.gr</domain:hostObj><domain:hostObj>ns2.myip.gr</domain:hostObj></domain:ns>'
            . '<domain:clID>myipgr</domain:clID><domain:crDate>2026-10-19T10:00:00.0Z</domain:crDate></domain:infData>'
            . '</resData><trID><clTRID>ECHO</clTRID><svTRID>s</svTRID></trID></response></epp>')));

        $this->assertStringContainsString('Registrar id', (string) $domain->fresh()->sync_error, 'precondition: the clID mismatch was the error');
        $action = $this->openRegister($domain);
        $this->assertTrue($action === null || !str_contains((string) $action->last_error, 'no longer has'),
            'the registry has it (it gave its record): staff must not be told to refund it. Said: ' . $action?->last_error);
    }

    /**
     * ADV-2 (high). Staff close the action («Close (stop it)»: they refunded or cancel the order separately) or mark it
     * «Done by hand». The domain stays PendingRegister with sent_at (an API connection), the next sync (every batch,
     * pending ones first) answers 2303 again, and a NEW open action appears: it can never be closed for good.
     */
    public function test_adv2_an_action_staff_closed_is_not_opened_again_by_the_next_sync(): void
    {
        $domain = $this->sentRegistration();
        $this->syncAnswering($domain, $this->noSuchName());
        $action = $this->openRegister($domain);
        $this->assertNotNull($action, 'precondition: told to staff');

        $action->closeUnrenewed(null);
        $this->assertNull($this->openRegister($domain));

        Carbon::setTestNow('2026-10-20 10:10:00');
        $this->syncAnswering($domain, $this->noSuchName());

        $this->assertNull($this->openRegister($domain), 'closed by staff: not opened again every 10 minutes');
    }

    /**
     * ADV-2 (high), the «Done by hand» path: staff registered it again by hand. Author's answer: a registry that still has
     * no such name after it is news (done by hand but not there), told once more; then no longer sent, not again.
     */
    public function test_adv2b_an_action_resolved_by_hand_is_not_opened_again_by_the_next_sync(): void
    {
        $domain = $this->sentRegistration();
        $this->syncAnswering($domain, $this->noSuchName());
        $this->openRegister($domain)->markResolved(null);
        $this->assertNull($this->openRegister($domain));

        Carbon::setTestNow('2026-10-20 10:10:00');
        $this->syncAnswering($domain, $this->noSuchName());
        Carbon::setTestNow('2026-10-20 10:20:00');
        $this->syncAnswering($domain, $this->noSuchName());

        $this->assertSame(2, ModuleAction::query()->where('domain_id', $domain->id)->where('action', ModuleAction::DOMAIN_REGISTER)->count(),
            'told once more (done by hand, yet not there), not one more per sync');
    }

    /**
     * ADV-3 (medium). The action tells staff «refund or register it again», but its Retry (and «Not done there», then
     * Retry, as the Close modal advises) calls DomainOrderFulfilment::fulfil, which refuses anything with meta.sent_at:
     * «Nothing to send: already sent». «Register again» cannot be done from the action it is offered on.
     */
    public function test_adv3_register_again_from_the_action_is_possible(): void
    {
        $domain = $this->sentRegistration();
        $this->syncAnswering($domain, $this->noSuchName());
        $action = $this->openRegister($domain);
        $this->assertNotNull($action, 'precondition: told to staff');
        $action->markNotDone(null);

        GrEppRegistrar::reset();
        $this->transport = new FakeTransport; // nothing queued: whatever it asks fails, which is fine here

        $this->assertNotSame(DomainOrderFulfilment::DONE, app(DomainOrderFulfilment::class)->fulfil($domain->id),
            'Retry of a registration the registry refused must try to register it again, not say «already sent»');
    }

    /**
     * ADV-4 (low). While open, each sync (every 10 minutes) counts one more «attempt» and moves last_attempt_at: after a
     * day the action reads «144 attempts» of a registration sent once (charged once): misleading for a money decision.
     */
    public function test_adv4_the_same_refusal_read_again_is_not_another_attempt(): void
    {
        $domain = $this->sentRegistration();
        $this->syncAnswering($domain, $this->noSuchName());
        Carbon::setTestNow('2026-10-20 10:10:00');
        $this->syncAnswering($domain, $this->noSuchName());
        Carbon::setTestNow('2026-10-20 10:20:00');
        $this->syncAnswering($domain, $this->noSuchName());

        $this->assertSame(1, (int) $this->openRegister($domain)?->attempts, 'sent once: one attempt, however often it is read');
    }

    /**
     * ADV-5 (medium). notOurs acts only for a production connection (a sandbox registry does not know our real names);
     * registrationGone does not check. A connection switched to sandbox (testing) answers 2303 for every real pending
     * registration, and each becomes «refund or register again».
     */
    public function test_adv5_a_sandbox_connections_no_such_name_is_not_a_registration_gone(): void
    {
        $domain = $this->sentRegistration();
        $this->gr->forceFill(['mode' => 'sandbox'])->save();

        $this->syncAnswering($domain, $this->noSuchName());

        $this->assertNull($this->openRegister($domain), 'a sandbox registry not having a real name says nothing about it');
    }

    /** Regression guard (passes): a 2303 for a registration never sent from here (imported, no sent_at) is not told. */
    public function test_guard_unsent_or_active_is_not_told(): void
    {
        $domain = $this->sentRegistration();
        $domain->forceFill(['status' => DomainStatus::Active])->save();
        $this->syncAnswering($domain, $this->noSuchName());
        $this->assertNull($this->openRegister($domain));
    }
}
