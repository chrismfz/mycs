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
use App\Models\ModuleAction;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Invoice\RecordPaymentService;
use App\Support\CurrentClient;
use Illuminate\Database\Events\QueryExecuted;
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
use Throwable;

/**
 * Sweep 4, lens r-domains-other-fixes (adversarial verifier): failing tests for what #750 (DomainNameHold, D-25) left.
 */
class Sweep4RDomainsOtherFixesTest extends TestCase
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
     * R-DOMAINS-OTHER-FIXES-1: an expired row past its window that was once ordered and sent here (meta.sent_at, its
     * registration logged OK, both kept for good once it is active) is never closed when another customer's paid order
     * takes the name: closeOne's «sent to its registrar» guard, meant for pending orders, applies to every row. Only
     * imported rows (no sent_at) are closed, the designed D-25 flow for «an expired one past its window».
     */
    public function test_an_expired_row_once_ordered_here_is_closed_when_another_pays_for_its_name(): void
    {
        $expired = $this->expired($this->alice->client(), 'mybusiness.com', ['meta' => ['sent_at' => '2025-09-20T10:00:00+00:00']]);
        DomainRegistrarLog::query()->create(['domain_id' => $expired->id, 'action' => 'register', 'status' => DomainRegistrarLog::STATUS_OK]);
        $this->assertNull($expired->fresh()->live_fqdn, 'past its window: it holds nothing');
        $bobs = $this->order($this->bob, 'mybusiness.com');

        $this->pay($bobs);

        $this->assertSame('mybusiness.com', $bobs->fresh()->live_fqdn, 'Bob paid: he holds the name');
        $this->assertSame(DomainStatus::Cancelled, $expired->fresh()->status,
            'the expired row past its window should be closed as for an imported one; it was left «sent to its registrar»');
    }

    /**
     * R-DOMAINS-OTHER-FIXES-2 (simulated interleaving, SQLite): closeOne reads «paid» on the other order without the
     * invoice lock; a payment of that order landing between that read and CancelOpenInvoices' lockInvoice makes cancel()
     * return null («paid meanwhile»), which closeOne takes for «nothing kept» and closes the paid order. The payment is
     * replayed through RecordPaymentService at the moment closeOne lists the open invoices (after paid(), before the
     * lock); on MariaDB it would be another process committing in that window.
     */
    public function test_an_order_paid_while_it_is_being_closed_is_not_cancelled_under_its_payment(): void
    {
        $alices = $this->order($this->alice, 'mybusiness.com');
        $bobs = $this->order($this->bob, 'mybusiness.com');
        $bobsInvoice = $this->invoiceOf($bobs);
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired, $bobs, $bobsInvoice) {
            $sql = str_replace(['"', '`'], '', $query->sql);
            if ($fired || !str_contains($sql, 'from invoices where status in') || !str_contains($sql, 'invoice_items')
                || !in_array($bobs->id, $query->bindings, false)) {
                return;
            }
            $fired = true;
            $invoice = Invoice::query()->findOrFail($bobsInvoice->id);
            (new RecordPaymentService)->handle($invoice, null, (string) $invoice->total, transactionId: 'tx-race-' . $bobs->id);
        });

        $this->pay($alices); // Alice pays first and takes the name; closeOthers(Alice) closes Bob's order

        $this->assertTrue($fired, 'the window was reached');
        $this->assertSame(Invoice::STATUS_PAID, $bobsInvoice->fresh()->status, 'Bob paid');
        $openAction = ModuleAction::query()->where('domain_id', $bobs->id)->where('status', ModuleAction::STATUS_OPEN)->exists();
        $this->assertTrue($bobs->fresh()->status !== DomainStatus::Cancelled || $openAction,
            'Bob\'s paid order was closed as cancelled with no Pending Module Action: his money has no task for staff');
        // Fixer: left open for staff, and his payment opened its Pending Module Action (refuse(): Alice paid first).
        $this->assertSame(DomainStatus::PendingRegister, $bobs->fresh()->status);
        $this->assertTrue($openAction);
        $this->assertSame('mybusiness.com', $alices->fresh()->live_fqdn);
    }

    /**
     * R-DOMAINS-OTHER-FIXES-3: «Renewed by hand» (ModuleAction::markResolved) on an expired row whose name another row
     * holds: markRenewedByHand has no claim (renewLine does), commits the applied line and the later expiry, then the
     * lifecycle makes it active and hits the live_fqdn unique key: the line applied, the expiry moved, the row still
     * expired.
     */
    public function test_renewed_by_hand_on_an_expired_row_whose_name_is_held_leaves_nothing_half_done(): void
    {
        // Ordered and sent here once (meta.sent_at), no registrar connection now (manual). Busy (its registrar lock taken)
        // when Bob's row takes the name, closeOthers leaves it open, so both rows coexist, as #750 allows. (The verifier's
        // first setup relied on R-DOMAINS-OTHER-FIXES-1's «sent to its registrar» guard, fixed now.)
        $alices = $this->expired($this->alice->client(), 'mybusiness.com', ['meta' => ['sent_at' => '2025-09-20T10:00:00+00:00']]);
        $busy = Cache::lock("domain-registrar:{$alices->id}", 60);
        $this->assertTrue($busy->get());
        Domain::factory()->create(['client_id' => $this->bob->client()->id, 'sld' => 'mybusiness', 'tld' => 'com', 'fqdn' => 'mybusiness.com',
            'status' => DomainStatus::Active, 'expires_at' => '2027-11-01']);
        $busy->release();
        $this->assertSame(DomainStatus::Expired, $alices->fresh()->status, 'precondition: left open beside the holder');
        $invoice = Invoice::query()->create(['client_id' => $alices->client_id, 'currency_code' => 'EUR', 'status' => Invoice::STATUS_UNPAID, 'due_at' => now()]);
        $line = $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $alices->id, 'price' => '23.33', 'quantity' => 1,
            'description' => 'Renewal mybusiness.com', 'period_start' => '2026-09-20', 'period_end' => '2027-09-20']);
        (new RecordPaymentService)->handle($invoice->fresh(), null, (string) $invoice->fresh()->total, transactionId: 'tx-renew-' . $alices->id);

        $this->assertSame(DomainRenewalService::WAITING, app(DomainRenewalService::class)->renewLine($line->id));
        $action = ModuleAction::query()->where('domain_id', $alices->id)->where('status', ModuleAction::STATUS_OPEN)->sole();
        $this->assertSame(ModuleAction::DOMAIN_RENEW, $action->action);

        $thrown = null;
        try {
            $action->markResolved(null); // «Renewed by hand»
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $alices->refresh();
        $this->assertFalse($line->fresh()->applied_at !== null && $alices->status === DomainStatus::Expired,
            'half done: its line applied and its expiry moved to ' . $alices->expires_at?->toDateString() . ', the row still expired'
            . ($thrown !== null ? ' (' . $thrown::class . ')' : ''));
        // Refused before anything is written: said to staff, the line waiting, the expiry where it was, the action open.
        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertStringContainsString('#' . Domain::query()->where('fqdn', 'mybusiness.com')->whereKeyNot($alices->id)->value('id'), $thrown->getMessage());
        $this->assertNull($line->fresh()->applied_at);
        $this->assertSame('2026-09-20', $alices->expires_at?->toDateString());
        $this->assertSame(ModuleAction::STATUS_OPEN, $action->fresh()->status);
    }

    /**
     * Fixer (R-DOMAINS-OTHER-FIXES-1): the «sent to its registrar» guard still keeps a pending order that may be done at
     * its registrar (a registration logged, its hold lost past the model) out of closeOthers: staff's.
     */
    public function test_a_pending_order_sent_to_its_registrar_is_still_left_to_staff(): void
    {
        $sent = Domain::factory()->create(['client_id' => $this->alice->client()->id, 'sld' => 'mybusiness', 'tld' => 'com', 'fqdn' => 'mybusiness.com',
            'status' => DomainStatus::PendingRegister]);
        DomainRegistrarLog::query()->create(['domain_id' => $sent->id, 'action' => 'register', 'status' => DomainRegistrarLog::STATUS_PENDING]);
        Domain::query()->whereKey($sent->id)->update(['name_held_at' => null]); // past the model
        $this->assertNull($sent->fresh()->live_fqdn);
        $bobs = $this->order($this->bob, 'mybusiness.com');

        $this->pay($bobs);

        $this->assertSame('mybusiness.com', $bobs->fresh()->live_fqdn);
        $this->assertSame(DomainStatus::PendingRegister, $sent->fresh()->status, 'sent to its registrar: staff decide');
    }

    /**
     * Fixer (R-DOMAINS-OTHER-FIXES-3): staff «Renew now» on an expired row whose name another row holds is refused before
     * the registrar is asked (no year bought there for a row that cannot be active here), nothing billed.
     */
    public function test_renew_now_on_an_expired_row_whose_name_is_held_is_refused_before_the_registrar(): void
    {
        $alices = $this->expired($this->alice->client(), 'mybusiness.com', ['domain_tld_id' => DomainTld::query()->where('tld', 'com')->value('id')]);
        $bobs = Domain::factory()->create(['client_id' => $this->bob->client()->id, 'sld' => 'mybusiness', 'tld' => 'com', 'fqdn' => 'mybusiness.com',
            'status' => DomainStatus::Active, 'expires_at' => '2027-11-01']);
        $this->assertSame(DomainStatus::Cancelled, $alices->fresh()->status, 'closed by Bob\'s row (FIXES-1 fixed): set back by staff below');
        Domain::query()->whereKey($alices->id)->update(['status' => DomainStatus::Expired->value]); // past the model
        $this->assertNotNull($alices->fresh()->effectiveRegistrarConnection());
        Http::fake(fn () => throw new \LogicException('the registrar must not be asked'));

        try {
            app(DomainRenewalService::class)->renewNow($alices->fresh(), Staff::factory()->create());
            $this->fail('renewed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('#' . $bobs->id, $e->getMessage());
        }
        $this->assertSame(0, InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $alices->id)->count());
        $this->assertSame(DomainStatus::Expired, $alices->fresh()->status);
    }
}
