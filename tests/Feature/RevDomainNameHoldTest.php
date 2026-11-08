<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Jobs\Domains\FulfilDomainOrderJob;
use App\Livewire\Cart;
use App\Livewire\DomainShop\Search;
use App\Mail\SystemMail;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Domains\DomainNameHold;
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Domains\Ordering\DomainOrderFulfilment;
use App\Services\Invoice\RecordPaymentService;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Nixpal\DomainTools\Whois\SocketConnector;
use Tests\TestCase;

/**
 * Adversarial review of «Who holds a domain name» (D-25). Each test is a finding that fails on feat/domain-name-hold.
 *
 * Originally: who holds a domain name (owner 2026-11-08, docs/domains.md D-25): an active, grace or redemption row, or a pending
 * order once paid (or free, or sent). An expired row or an unpaid order does not: another customer may order the name,
 * whoever pays first holds it, a later payment is refused as a registrar's refusal, and the rows left behind are closed.
 */
class RevDomainNameHoldTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    /** What the registrar's availability check says of every name. */
    private string $checkSays = 'free';

    /** @var list<string> the names sent to the registrar (register or transfer) */
    private array $sent = [];

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

    /** Openprovider: every name free (checkSays); a registration answered ACT. */
    private function registrar(Request $request)
    {
        $url = $request->url();
        $path = (string) parse_url($url, PHP_URL_PATH);
        $record = fn (string $fqdn) => ['id' => 9001, 'domain' => ['name' => explode('.', $fqdn)[0], 'extension' => 'com'], 'status' => 'ACT',
            'expiration_date' => '2027-11-08 10:00:00', 'name_servers' => [['name' => 'ns1.nixpal.com'], ['name' => 'ns2.nixpal.com']],
            'owner_handle' => 'MP000001-GR', 'admin_handle' => 'MP000001-GR', 'tech_handle' => 'MP000001-GR', 'billing_handle' => 'MP000001-GR'];

        return match (true) {
            str_contains($url, '/auth/login') => Http::response(['data' => ['token' => 'tok']]),
            str_contains($url, '/domains/check') => Http::response(['data' => ['results' => array_map(fn ($d) => [
                'domain' => $d['name'] . '.' . $d['extension'], 'status' => $this->checkSays], $request->data()['domains'])]]),
            $request->method() === 'POST' && str_ends_with($path, '/customers') => Http::response(['data' => ['handle' => 'MP000001-GR']]),
            $request->method() === 'POST' && str_ends_with($path, '/domains') => $this->created($request->data()),
            $request->method() === 'GET' && str_contains($path, '/v1/domains') => Http::response(['data' => ['results' => $this->sent === [] ? [] : [$record($this->sent[0])],
                'total' => $this->sent === [] ? 0 : 1] + ($this->sent === [] ? [] : $record($this->sent[0]))]),
            default => Http::response([], 404),
        };
    }

    private function created(array $data)
    {
        $this->sent[] = $data['domain']['name'] . '.' . $data['domain']['extension'];

        return Http::response(['code' => 0, 'data' => ['id' => 9001, 'status' => 'ACT']]);
    }

    /** $user orders a registration of $fqdn through the shop and the cart: its pending row, not paid. */
    private function order(User $user, string $fqdn): Domain
    {
        $this->shop($user, 'add', $fqdn);

        return Domain::query()->where('fqdn', $fqdn)->where('client_id', $user->client()->id)->latest('id')->firstOrFail();
    }

    /** $user's own cart: $how ('add' or 'transfer') $fqdn, then checkout. */
    private function shop(User $user, string $how, string $fqdn, array $set = []): void
    {
        Once::flush();
        $this->actingAs($user);
        session([CurrentClient::SESSION_KEY => $user->client()->id]);
        Livewire::test(Search::class)->call($how, $fqdn)->assertRedirect(route('cart'));
        $ulid = (string) \App\Models\Cart::query()->where('client_id', $user->client()->id)->latest('id')->value('ulid');
        $cart = Livewire::withCookie('cart', $ulid)->actingAs($user)->test(Cart::class);
        $item = \App\Models\Cart::query()->where('ulid', $ulid)->first()->items()->sole();
        foreach ($set as $key => $value) {
            $cart->set(str_replace('{item}', (string) $item->id, $key), $value);
        }
        $cart->call('checkout')->assertRedirect();
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

    private function runJobs(): void
    {
        foreach (Queue::pushed(FulfilDomainOrderJob::class) as $job) {
            app()->call([$job, 'handle']);
        }
    }

    private function expired(Client $client, string $fqdn): Domain
    {
        return Domain::factory()->create(['client_id' => $client->id, 'sld' => explode('.', $fqdn)[0], 'tld' => 'com', 'fqdn' => $fqdn,
            'status' => DomainStatus::Expired, 'expires_at' => '2026-10-20']);
    }

    /** @return list<string> the subjects of the mails to staff */
    private function staffMails(): array
    {
        return MailFacade::queued(SystemMail::class)->map(fn (SystemMail $mail) => $mail->envelope()->subject)->values()->all();
    }

    /** A renewal of $domain on its own invoice, paid now (its job queued, not run: the line waits for the registrar). */
    private function paidRenewal(Domain $domain): InvoiceItem
    {
        $invoice = Invoice::query()->create(['client_id' => $domain->client_id, 'currency_code' => 'EUR', 'status' => Invoice::STATUS_UNPAID, 'due_at' => now()]);
        $line = $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'price' => '23.33', 'quantity' => 1,
            'description' => "Renewal {$domain->fqdn}", 'period_start' => '2026-10-20', 'period_end' => '2027-10-20']);
        (new RecordPaymentService)->handle($invoice->fresh(), null, (string) $invoice->fresh()->total, transactionId: 'tx-renew-' . $domain->id);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        return $line->fresh();
    }

    /**
     * ADV-DNH-1 (whoever pays first): Alice's expired name, her renewal paid and waiting for its job (RenewDomainJob, queued),
     * then Bob orders and pays the name. Bob paid second, yet Alice's row is closed as cancelled: her paid renewal goes to
     * staff as «the domain has ended», while she still holds it at the registry. closeOne() leaves a paid pending order to
     * staff, but never looks at an expired row's paid renewal.
     */
    public function test_an_expired_row_whose_renewal_was_paid_first_is_not_closed_by_a_later_payer(): void
    {
        $alices = $this->expired($this->alice->client(), 'mybusiness.com');
        $renewal = $this->paidRenewal($alices);
        $this->assertNull($renewal->applied_at, 'waiting for its job');

        $bobs = $this->order($this->bob, 'mybusiness.com');
        $this->pay($bobs);

        $this->assertNotSame(DomainStatus::Cancelled, $alices->fresh()->status, 'Alice paid her renewal first: her row is not closed under her');
    }

    /**
     * ADV-DNH-2 (race, paid not read again under the lock): closeOne() asks DomainOrderFulfilment::paid() before it locks
     * the row; inside its transaction it reads the row again but only checks status/holdsName. Alice's payment landing
     * between the two (her claim refused, her money on a paid invoice for staff) still gets her paid order closed as
     * cancelled (her paid invoice is not «open», so nothing stops it), against «paid and refused: staff's, never closed».
     */
    public function test_an_order_paid_while_it_is_being_closed_is_not_closed(): void
    {
        $alices = $this->order($this->alice, 'mybusiness.com');
        $bobs = $this->order($this->bob, 'mybusiness.com');
        $lock = Cache::lock("domain-registrar:{$alices->id}", 60);
        $lock->get();
        $this->pay($bobs); // Alice's order busy: left open
        $lock->release();
        $this->assertSame(DomainStatus::PendingRegister, $alices->fresh()->status);

        // Alice's payment commits just after closeOne() read «not paid», before it locks her row.
        $invoiceId = $this->invoiceOf($alices)->id;
        $level = DB::transactionLevel();
        $fired = false;
        DB::listen(function ($query) use (&$fired, $alices, $invoiceId, $level) {
            if (!$fired && DB::transactionLevel() > $level && str_contains($query->sql, 'domains') && in_array($alices->id, $query->bindings, false)
                && preg_match('/^select/i', $query->sql)) {
                $fired = true;
                DB::table('invoices')->where('id', $invoiceId)->update(['status' => Invoice::STATUS_PAID]);
            }
        });

        DomainNameHold::closeOthers($bobs->id, tellStaff: false);

        $this->assertTrue($fired, 'the race was staged');
        $this->assertSame(DomainStatus::PendingRegister, $alices->fresh()->status, 'paid meanwhile: staff\'s to refund or credit, not closed');
    }

    /**
     * ADV-DNH-3 (same customer twice): unique live_fqdn used to stop a second order of a name already ordered («ordered a
     * moment ago»). Now an unpaid order holds nothing, so the same customer can check out the same name twice (two tabs, a
     * retry); paying both invoices (or one mass payment) charges twice, the second only refused to staff.
     */
    public function test_the_same_customer_cannot_order_a_name_twice(): void
    {
        $this->order($this->alice, 'mybusiness.com');
        try {
            $this->order($this->alice, 'mybusiness.com');
        } catch (\Throwable) {
            // refused: what this test wants
        }

        $this->assertSame(1, Domain::query()->where('client_id', $this->alice->client()->id)->where('fqdn', 'mybusiness.com')
            ->whereNotIn('status', DomainStatus::terminalValues())->count(), 'one open order per customer and name');
    }

    /**
     * ADV-DNH-4 (held only while paid): a pending order holds its name only while PAID (spec). Its payment refunded before it
     * was sent (gateway refund, chargeback): name_held_at stays, so it still holds the name and blocks every other
     * customer, while it is never sent (paid() is false) and never cancelled by the D8b cron (a refunded invoice is neither
     * open nor cancelled).
     */
    public function test_an_order_whose_payment_was_refunded_no_longer_holds_its_name(): void
    {
        $bobs = $this->order($this->bob, 'mybusiness.com');
        $this->pay($bobs);
        $this->assertNotNull($bobs->fresh()->name_held_at);
        DB::table('invoices')->where('id', $this->invoiceOf($bobs)->id)->update(['status' => Invoice::STATUS_REFUNDED]);

        $this->assertFalse(DomainOrderFulfilment::paid($bobs->fresh(), DomainOrderFulfilment::lineOf($bobs->fresh())), 'no longer paid');
        $this->assertNull($bobs->fresh()->live_fqdn, 'not paid any more: it does not hold the name');
    }
}
