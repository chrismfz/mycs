<?php

namespace Tests\Feature;

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
use App\Models\User;
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Invoice\RecordPaymentService;
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
use Tests\TestCase;

/**
 * Sweep 4, independent review of the r-domains-other-fixes fixes: failing tests for what the fixes left or broke.
 */
class Rev4RDomainsOtherFixesTest extends TestCase
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
     * REV4-RDOF-1: the fix of R-DOMAINS-OTHER-FIXES-1 drops the whole «sent to its registrar» guard for every row that is
     * not pending, including a transfer that replaces its renewal (RenewAsTransfer) whose outcome is not known: its log is
     * left STATUS_PENDING and the row stays Expired, «check the target before anything else; it may be under way». Only
     * meta.sent_at and an OK log stay on a row for good (the finding's reason); a PENDING one is a charge at a registrar
     * not confirmed, which the guard was there for. Its renewal refunded (so it no longer holds), another customer pays
     * for the name, and closeOthers now closes the row as cancelled under a transfer that may be completing.
     */
    public function test_an_expired_row_with_a_renewal_transfer_not_confirmed_is_still_left_to_staff(): void
    {
        $alices = $this->expired($this->alice->client(), 'mybusiness.com');
        DomainRegistrarLog::query()->create(['domain_id' => $alices->id, 'action' => 'transfer', 'status' => DomainRegistrarLog::STATUS_PENDING,
            'request' => ['years' => 1, 'renewal' => true]]);
        $this->assertNull($alices->fresh()->live_fqdn, 'precondition: past its window, its renewal refunded: holds nothing');
        $bobs = $this->order($this->bob, 'mybusiness.com');

        $this->pay($bobs);

        $this->assertSame('mybusiness.com', $bobs->fresh()->live_fqdn);
        $this->assertSame(DomainStatus::Expired, $alices->fresh()->status,
            'a transfer replacing its renewal with no answer yet (it may be under way at the target): staff decide, never closed');
    }
}
