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
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\Ordering\DomainOrderFulfilment;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\RegistrarTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * Adversarial review of #193 (registry ids on each call's log, the connection's debug log of frames): no secret in
 * the file, the DB or the admin; a debug log that fails never changes a chargeable command; each log keeps only its
 * own call's transactions; a long worker never grows them.
 */
class GreppDebugLogAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Sec&r]]>t-pw1';

    private FakeTransport $transport;

    private DomainRegistrarConnection $gr;

    private User $user;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Cache::flush();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production', 'debug_log' => true]);
        $this->gr->storeCredentials(['username' => 'myip1', 'password' => self::PASSWORD, 'contact_prefix' => 'c60'], ['password']);
        $this->user = User::factory()->create();
        $this->transport = new FakeTransport;
        GrEppRegistrar::$transport = fn () => $this->transport;
        GrEppRegistrar::reset();
        $this->file = storage_path('logs/test-adv193-' . bin2hex(random_bytes(4)) . '.log');
        config(['logging.channels.registrar-debug' => ['driver' => 'single', 'path' => $this->file, 'level' => 'debug']]);
    }

    protected function tearDown(): void
    {
        GrEppRegistrar::$transport = null;
        GrEppRegistrar::reset();
        Carbon::setTestNow();
        @unlink($this->file);
        parent::tearDown();
    }

    private function credentials(bool $debug = true): DomainRegistrarCredentials
    {
        return new DomainRegistrarCredentials(['username' => 'myip1', 'password' => self::PASSWORD, 'contact_prefix' => 'c60'], sandbox: false, debug: $debug);
    }

    private function answer(int $code, string $resData = ''): void
    {
        $this->transport->queue(new HttpResponse(200, '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
            . "<result code=\"{$code}\"><msg>ok</msg></result>" . ($resData !== '' ? "<resData>{$resData}</resData>" : '')
            . '<trID><clTRID>ECHO</clTRID><svTRID>s</svTRID></trID></response></epp>'));
    }

    private function fixture(string $name, ?callable $change = null): void
    {
        $body = (string) file_get_contents(base_path("packages/gr-epp/tests/fixtures/{$name}"));
        $body = (string) preg_replace('#<clTRID>[^<]*</clTRID>#', '<clTRID>ECHO</clTRID>', $body);
        $this->transport->queue(new HttpResponse(200, $change !== null ? $change($body) : $body));
    }

    private function loggedIn(): void
    {
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=COOKIE-SECRET-1'])->fixture('04-login-response.xml');
    }

    private function domain(array $attributes = []): Domain
    {
        $contact = Contact::factory()->create(['client_id' => $this->user->client()->id, 'first_name' => 'Μαρία', 'last_name' => 'Παπαδοπούλου',
            'company_name' => null, 'email' => 'maria@example.test', 'phone' => '+30 210 0000000', 'address1' => 'Οδός 1', 'address2' => null,
            'city' => 'Αθήνα', 'state' => 'Αττική', 'postcode' => '10000', 'country' => 'GR']);
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['is_active' => true, 'registrar_connection_id' => $this->gr->id, 'grace_days' => 15, 'redemption_days' => 0]);
        $domain = Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'example', 'tld' => 'gr', 'fqdn' => 'example.gr',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->gr->id, 'status' => DomainStatus::PendingRegister, 'expires_at' => null,
            'currency_code' => 'EUR', 'years' => 2, 'registrant_contact_id' => $contact->id, 'admin_contact_id' => $contact->id,
            'tech_contact_id' => $contact->id, 'billing_contact_id' => $contact->id]);
        foreach (['ns1.myip.gr', 'ns2.myip.gr'] as $i => $host) {
            $domain->nameservers()->create(['host' => $host, 'sort' => $i]);
        }

        return $domain->fresh(['nameservers', 'registrantContact', 'adminContact', 'techContact', 'billingContact']);
    }

    /** A paid registration, its answers queued (a contact made, both hosts there, created 1001). */
    private function paidRegistration(): Domain
    {
        Mail::fake();
        $order = Order::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'EUR']);
        $domain = $this->domain(['order_id' => $order->id, 'registered_at' => null]);
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'EUR']);
        InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => '24.00', 'description' => 'example.gr',
            'reference_type' => Domain::class, 'reference_id' => $domain->id, 'kind' => InvoiceItem::KIND_REGISTER]);
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        $id = 'c60_mcs' . base_convert((string) $domain->registrant_contact_id, 10, 36);
        $this->loggedIn();
        $this->answer(1000, '<contact:chkData xmlns:contact="urn:ietf:params:xml:ns:contact-1.0"><contact:cd><contact:id avail="1">' . $id . '</contact:id></contact:cd></contact:chkData>');
        $this->fixture('v4.3/14-contact-create-response.xml');
        $this->answer(1000, '<host:chkData xmlns:host="urn:ietf:params:xml:ns:host-1.0"><host:cd><host:name avail="0">ns1.myip.gr</host:name></host:cd><host:cd><host:name avail="0">ns2.myip.gr</host:name></host:cd></host:chkData>');
        $this->fixture('v4.3/38-domain-create-response.xml');

        return $domain;
    }

    /** Everything a secret could reach: the debug file, every registrar log row (as stored), the connection's JSON. */
    private function everywhere(): string
    {
        return (file_exists($this->file) ? (string) file_get_contents($this->file) : '')
            . json_encode(DomainRegistrarLog::query()->get()->map(fn (DomainRegistrarLog $l) => $l->getAttributes())->all())
            . json_encode($this->gr->fresh()?->toArray());
    }

    public function test_a_debug_log_that_cannot_be_written_never_changes_a_registration(): void
    {
        // The channel's path a directory: every write throws.
        config(['logging.channels.registrar-debug' => ['driver' => 'single', 'path' => storage_path('logs'), 'level' => 'debug']]);
        $domain = $this->paidRegistration();

        $this->assertSame(DomainOrderFulfilment::SENT, app(DomainOrderFulfilment::class)->fulfil($domain->id), (string) ModuleAction::query()->value('last_error'));
        $this->assertSame(1, collect($this->transport->kinds())->filter(fn ($k) => $k === 'create')->count() - 1, 'domain:create sent once (one contact:create)');
        $log = DomainRegistrarLog::query()->where('action', 'register')->sole();
        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $this->assertSame(1001, collect($log->transactions)->firstWhere('command', 'domain:create')['code'] ?? null, 'the ids kept all the same');
    }

    public function test_no_secret_of_a_registration_and_a_renewal_reaches_the_file_the_db_or_the_connection(): void
    {
        $domain = $this->paidRegistration();
        $this->assertSame(DomainOrderFulfilment::SENT, app(DomainOrderFulfilment::class)->fulfil($domain->id), (string) ModuleAction::query()->value('last_error'));

        // A renewal whose domain:info answers carry the name's code (as the registry answers its sponsor).
        $withCode = fn (string $body) => str_replace('</domain:infData>', '<domain:authInfo><domain:pw><![CDATA[Info-Code-1]]></domain:pw></domain:authInfo></domain:infData>', $body);
        $active = $this->domain(['status' => DomainStatus::Active, 'expires_at' => '2029-03-01', 'fqdn' => 'example1.gr', 'sld' => 'example1']);
        $this->fixture('live/domain-info-response.xml', $withCode);
        $this->fixture('v4.3/52-domain-renew-response.xml');
        $this->fixture('live/domain-info-response.xml', $withCode);
        (new GrEppRegistrar)->renew($active, 2, $this->credentials());

        $all = $this->everywhere();
        $this->assertStringContainsString('grepp domain:renew', $all, 'the renewal is in the file');
        foreach (['Sec&r', 'Sec&amp;r', 'r]]>t', 't-pw1', 'Info-Code-1', 'COOKIE-SECRET'] as $secret) {
            $this->assertStringNotContainsString($secret, $all, $secret);
        }
    }

    public function test_each_log_keeps_only_its_own_calls_transactions(): void
    {
        $domain = $this->paidRegistration();
        $this->assertSame(DomainOrderFulfilment::SENT, app(DomainOrderFulfilment::class)->fulfil($domain->id), (string) ModuleAction::query()->value('last_error'));
        $register = DomainRegistrarLog::query()->where('action', 'register')->sole();
        $this->assertSame(['hello', 'login'], array_slice(array_column($register->transactions ?? [], 'command'), 0, 2), 'its login is its own');

        // The same session (already logged in) recalls it: its log has the delete, never the registration's frames.
        $this->fixture('v4.3/60-domain-delete-recall-domain-creation-response.xml');
        app(DomainManagementService::class)->recall($domain->fresh(), Staff::factory()->create());
        $recall = DomainRegistrarLog::query()->where('action', 'recall')->sole();
        $this->assertSame(['domain:delete'], array_column($recall->transactions ?? [], 'command'));
    }

    public function test_a_long_worker_never_grows_the_transactions_and_a_log_keeps_its_latest(): void
    {
        $transactions = app(RegistrarTransactions::class);
        $mark = $transactions->mark();
        for ($i = 0; $i < 5000; $i++) {
            $transactions->record(['command' => 'domain:check', 'clTRID' => "t-{$i}", 'svTRID' => null, 'code' => 1000, 'http' => 200]);
        }
        $all = (new \ReflectionProperty($transactions, 'all'))->getValue($transactions);
        $this->assertLessThanOrEqual(200, count($all));
        $since = $transactions->since($mark);
        $this->assertCount(50, (array) $since);
        $this->assertSame('t-4999', $since[49]['clTRID'] ?? null);
        $this->assertNull($transactions->since($transactions->mark()), 'none after the last');
    }
}
