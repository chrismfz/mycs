<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Enums\DomainStatus;
use App\Livewire\Cart;
use App\Livewire\DomainShop\Search;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\User;
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Nixpal\DomainTools\Whois\SocketConnector;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Sweep 5, lens g-domains (adversarial verifier): failing tests for what #752 and #756 left.
 */
class Sweep5GDomainsTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 10:00:00');
        Queue::fake();
        MailFacade::fake();
        Cache::flush();
        Http::preventStrayRequests();
        LaravelTransport::$resolve = fn (string $host) => ['93.184.216.34'];
        $this->app->bind(SocketConnector::class, fn () => new class implements SocketConnector
        {
            public function query($host, $query, $timeout = 8, $max = 65536)
            {
                return null;
            }
        });
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR', 'settings.domain_billing_enabled' => true,
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        session(['currency' => 'EUR']);
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $com = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'sort' => 1, 'registrar_connection_id' => $op->id, 'epp_code_required' => true]);
        foreach ([['register', 1, '23.33'], ['renew', 1, '23.33'], ['transfer', 1, '20.00']] as [$operation, $years, $price]) {
            DomainTldPrice::query()->create(['domain_tld_id' => $com->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
        }
        $this->alice = $this->customer('alice');
        $this->bob = $this->customer('bob');
        Http::fake(fn (Request $request) => $this->registrar($request));
    }

    protected function tearDown(): void
    {
        LaravelTransport::$resolve = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(string $name): User
    {
        $user = User::factory()->create();
        $user->client()->ensurePrimaryContact()->update(['first_name' => ucfirst($name), 'last_name' => 'P', 'email' => "{$name}@example.test",
            'phone' => '+30.2100000000', 'address1' => 'Odos 1', 'city' => 'Athina', 'postcode' => '10000', 'country' => 'GR']);

        return $user;
    }

    /** Openprovider: every name free; nothing is ever sent in these tests (the jobs are queued, not run). */
    private function registrar(Request $request)
    {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/auth/login') => Http::response(['data' => ['token' => 'tok']]),
            str_contains($url, '/domains/check') => Http::response(['data' => ['results' => array_map(fn ($d) => [
                'domain' => $d['name'] . '.' . $d['extension'], 'status' => 'free'], $request->data()['domains'])]]),
            default => Http::response([], 404),
        };
    }

    private function order(User $user, string $fqdn): Domain
    {
        Once::flush();
        $this->actingAs($user);
        session([CurrentClient::SESSION_KEY => $user->client()->id]);
        Livewire::test(Search::class)->call('add', $fqdn)->assertRedirect(route('cart'));
        $ulid = (string) \App\Models\Cart::query()->where('client_id', $user->client()->id)->latest('id')->value('ulid');
        Livewire::withCookie('cart', $ulid)->actingAs($user)->test(Cart::class)->call('checkout')->assertRedirect();

        return Domain::query()->where('fqdn', $fqdn)->where('client_id', $user->client()->id)->latest('id')->firstOrFail();
    }

    private function invoiceOf(Domain $domain): Invoice
    {
        return InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->sole()->invoice;
    }

    private function pay(Domain $domain): void
    {
        config(['settings.domain_nameserver_1' => 'ns1.nixpal.com', 'settings.domain_nameserver_2' => 'ns2.nixpal.com']);
        $invoice = $this->invoiceOf($domain);
        (new RecordPaymentService)->handle($invoice, null, (string) $invoice->total, transactionId: 'tx-' . $domain->id);
    }

    /** $client's expired row of $fqdn past its recovery window (expired 2026-09-20, 30 days): it holds nothing. */
    private function expired(Client $client, string $fqdn, array $set = []): Domain
    {
        return Domain::factory()->create(['client_id' => $client->id, 'sld' => explode('.', $fqdn)[0], 'tld' => 'com', 'fqdn' => $fqdn,
            'status' => DomainStatus::Expired, 'expires_at' => '2026-09-20'] + $set);
    }

    /**
     * G-DOMAINS-1: one cart invoice of Bob's bills a hosting line (20.00) and his order of the name (23.33), 25.00 of credit
     * on it in part. Alice pays first; closeOne(Bob) has CancelOpenInvoices remove only the domain's line, and the credit
     * now covers the rest, so it pays the invoice (in the same transaction). closeOne's «paid while it was being closed»
     * re-read (#752) then sees that invoice PAID and leaves Bob's order pending, with no line and no money for the domain,
     * telling staff to refund or Cancel Order (which would cancel the hosting just paid).
     */
    public function test_an_order_whose_line_was_removed_and_its_invoice_paid_by_credit_for_the_rest_is_closed(): void
    {
        config(['settings.credits_enabled' => true]);
        $bobs = $this->order($this->bob, 'mybusiness.com');
        $invoice = $this->invoiceOf($bobs);
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $this->bob->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_PENDING, 'currency_code' => 'EUR', 'price' => 20.00, 'quantity' => 1]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'price' => '20.00', 'quantity' => 1,
            'description' => 'Hosting order']);
        (new CreditLedger)->add($this->bob->client()->id, 'EUR', 2500, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Balance');
        $this->assertNotNull((new RecordPaymentService)->payWithCredit($invoice->id, upToCents: 2500));
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'precondition: credit applied in part');
        $alices = $this->order($this->alice, 'mybusiness.com');

        $this->pay($alices); // Alice pays first: closeOthers(Alice) closes Bob's order

        $this->assertSame('mybusiness.com', $alices->fresh()->live_fqdn);
        $this->assertSame(0, InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $bobs->id)->count(), 'its line removed');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'the credit paid the rest (the hosting)');
        $this->assertSame(DomainStatus::Cancelled, $bobs->fresh()->status,
            'Bob\'s order has no line and no money left on it: it should be closed, not left «paid while it was being closed»');
    }

    /**
     * G-DOMAINS-2: an expired row past its window, ordered here once (sent_at, its registration OK), whose late renewal
     * was sent to its registrar with no answer (a PENDING renew log: DomainRenewalService::unconfirmed) and then refunded,
     * so it holds nothing. Another customer pays for the name: closeOne's guard since #752 is (pending && sent) or a
     * PENDING register/transfer; a PENDING renew is not one, so the row is closed as cancelled while its renewal may still
     * go through at the registry.
     */
    public function test_an_expired_row_with_a_renewal_not_confirmed_is_left_to_staff(): void
    {
        $alices = $this->expired($this->alice->client(), 'mybusiness.com', ['meta' => ['sent_at' => '2025-09-20T10:00:00+00:00']]);
        DomainRegistrarLog::query()->create(['domain_id' => $alices->id, 'action' => 'register', 'status' => DomainRegistrarLog::STATUS_OK]);
        DomainRegistrarLog::query()->create(['domain_id' => $alices->id, 'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_PENDING,
            'request' => ['years' => 1]]);
        $this->assertNull($alices->fresh()->live_fqdn, 'precondition: past its window, its renewal refunded: holds nothing');
        $bobs = $this->order($this->bob, 'mybusiness.com');

        $this->pay($bobs);

        $this->assertSame('mybusiness.com', $bobs->fresh()->live_fqdn);
        $this->assertSame(DomainStatus::Expired, $alices->fresh()->status,
            'a renewal at its registrar with no answer yet (it may go through there): staff decide, never closed');
    }

    /**
     * G-DOMAINS-3: an Active WHMCS domain (staff activated it before payment) whose DomainRegister invoice has been
     * Unpaid for 10 days, a debt: abandonedOrders refuses the import for it, though nothing is set up when credit pays it
     * (DomainOrderFulfilment::due sends pending rows only). Hosting lines count only for a Pending service; domain lines
     * count whatever the domain's status.
     */
    public function test_an_unpaid_registration_of_an_active_whmcs_domain_does_not_refuse_the_import(): void
    {
        $this->markTestIncomplete('G-DOMAINS-3 is fixed in g-money-service (abandonedOrders), not in this branch.');
        // @phpstan-ignore deadCode.unreachable
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, domainstatus TEXT)',
            'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, domain TEXT, status TEXT)',
            'CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, date TEXT, status TEXT)',
            'CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, type TEXT, relid INT)',
        ] as $sql) {
            $pdo->exec($sql);
        }
        $old = now()->subDays(10)->toDateString();
        $pdo->exec("INSERT INTO tbldomains VALUES (501, 1, 'trusted.com', 'Active')");
        $pdo->exec("INSERT INTO tblinvoices VALUES (9901, 1, '{$old}', 'Unpaid')");
        $pdo->exec("INSERT INTO tblinvoiceitems VALUES (99011, 9901, 'DomainRegister', 501)");
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $pdo);

        $this->assertSame([], (new ReflectionMethod($command, 'abandonedOrders'))->invoke($command),
            'an Active domain\'s unpaid registration is a debt, not an order credit would set up: the import should not refuse it');
    }
}
