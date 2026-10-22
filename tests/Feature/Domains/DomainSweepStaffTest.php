<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Jobs\Domains\FulfilDomainOrderJob;
use App\Livewire\Cart;
use App\Livewire\DomainShop\Search;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\Order;
use App\Models\User;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\CancelOrder;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Nixpal\DomainTools\Whois\SocketConnector;
use Tests\TestCase;

/**
 * Domain sweep, staff tools (Pending Module Actions of domains: Retry, «Renewed by hand», «Close (not renewed)», «Not
 * done there», «Done by hand», «Close (stop it)»). Each test_F<n> fails on main (e3b94471) for the reason it names.
 */
class DomainSweepStaffTest extends TestCase
{
    use RefreshDatabase;

    private DomainRegistrarConnection $op;

    private User $user;

    /** @var array<string, mixed> the registrar's record of the renewal tests' domain */
    private array $record = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> renew/restore POSTs */
    private array $charges = [];

    /** @var list<string> what each next charge does: ok | timeout | refuse */
    private array $script = [];

    /** Run inside the next charge, before its answer (a staff click racing the call). */
    private ?\Closure $duringCharge = null;

    /** @var list<array{0: string, 1: array}> register/transfer POSTs */
    private array $created = [];

    /** null: answers; 'down': 5xx (outcome unknown) */
    private ?string $createMode = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Cache::flush();
        MailFacade::fake();
        Http::preventStrayRequests();
        LaravelTransport::$resolve = fn (string $host) => ['93.184.216.34'];
        $this->app->bind(SocketConnector::class, fn () => new class implements SocketConnector
        {
            public function query($host, $query, $timeout = 8, $max = 65536)
            {
                return null;
            }
        });
        config(['audit.console' => true, 'settings.default_currency' => 'EUR', 'settings.domain_billing_enabled' => true,
            'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false]);
        session(['currency' => 'EUR']);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $tld = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'sort' => 1, 'registrar_connection_id' => $this->op->id,
            'epp_code_required' => true, 'grace_days' => 40, 'redemption_days' => 0]);
        foreach ([['register', 1, '23.33'], ['renew', 1, '23.33'], ['renew', 2, '46.66'], ['transfer', 1, '20.00']] as [$operation, $years, $price]) {
            DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
        }
        $this->user = User::factory()->create();
        $this->user->client()->ensurePrimaryContact()->update(['first_name' => 'Maria', 'last_name' => 'P', 'email' => 'maria@example.test',
            'phone' => '+30.2100000000', 'address1' => 'Odos 1', 'city' => 'Athina', 'postcode' => '10000', 'country' => 'GR']);
        $this->record = ['id' => 30391502, 'domain' => ['name' => 'example', 'extension' => 'com'], 'status' => 'ACT',
            'expiration_date' => '2026-11-01 10:00:00', 'name_servers' => [['name' => 'ns1.example.net'], ['name' => 'ns2.example.net']]];
        Http::fake(fn (Request $request) => $this->registrar($request));
    }

    protected function tearDown(): void
    {
        LaravelTransport::$resolve = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function registrar(Request $request)
    {
        $url = $request->url();
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (str_contains($url, '/auth/login')) {
            return Http::response(['data' => ['token' => 'tok']]);
        }
        if (str_contains($url, '/domains/check')) {
            return Http::response(['data' => ['results' => array_map(fn ($d) => ['domain' => $d['name'] . '.' . $d['extension'], 'status' => 'free'],
                $request->data()['domains'])]]);
        }
        if ($request->method() === 'POST' && str_ends_with($path, '/customers')) {
            return Http::response(['data' => ['handle' => 'MP000001-GR']]);
        }
        if ($request->method() === 'POST' && (str_ends_with($path, '/renew') || str_ends_with($path, '/restore'))) {
            $this->charges[] = [$path, $request->data()];
            if (($during = $this->duringCharge) !== null) {
                $this->duringCharge = null;
                $during();
            }
            $how = array_shift($this->script) ?? 'ok';
            if ($how === 'timeout') {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }
            if ($how === 'refuse') {
                return Http::response(['code' => 399, 'desc' => 'Insufficient funds'], 400);
            }
            $this->record['expiration_date'] = Carbon::parse($this->record['expiration_date'])->addYears((int) ($request->data()['period'] ?? 1))->format('Y-m-d H:i:s');

            return Http::response(['code' => 0, 'data' => ['success' => true]]);
        }
        if ($request->method() === 'POST' && (str_ends_with($path, '/domains') || str_ends_with($path, '/domains/transfer'))) {
            if ($this->createMode === 'down') {
                return Http::response([], 503);
            }
            $this->created[] = [$path, $request->data()];

            return Http::response(['code' => 0, 'data' => ['id' => 9001, 'status' => 'ACT']]);
        }
        if ($request->method() === 'GET' && str_contains($path, '/v1/domains')) {
            return Http::response(['data' => ['results' => [$this->record], 'total' => 1] + $this->record]);
        }
        if (str_contains($url, 'data.iana.org')) {
            return Http::response(['services' => [[['com'], ['https://rdap.example/']]]]);
        }

        return Http::response([], 404);
    }

    // ---------------------------------------------------------------- renewals

    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'domain_tld_id' => DomainTld::query()->where('tld', 'com')->value('id'), 'registrar_connection_id' => $this->op->id, 'registrar_domain_id' => '30391502',
            'status' => DomainStatus::Active, 'expires_at' => '2026-11-01', 'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    /** A paid renewal line of the domain for [start, end), on an invoice of its own. */
    private function paidLine(Domain $domain, string $start, string $end, bool $paid = true): InvoiceItem
    {
        Queue::fake();
        $invoice = Invoice::query()->create(['client_id' => $domain->client_id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => today(), 'currency_code' => 'EUR']);
        $line = $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'price' => '23.33', 'quantity' => 1,
            'description' => 'example.com renewal', 'period_start' => $start, 'period_end' => $end]);
        if ($paid) {
            (new RecordPaymentService)->handle($invoice, null, (float) $invoice->fresh()->total, transactionId: 'tx-' . $line->id);
        }

        return $line->fresh();
    }

    private function cronRenewals(): void
    {
        foreach (DomainRenewalService::waitingLines()->pluck('id') as $id) {
            app(DomainRenewalService::class)->renewLine((int) $id); // what the cron's «domains_renewed» does, line by line
        }
    }

    /**
     * F1 (money): «Close (not renewed)» on one line's row settles as «not done» every pending charge of the domain,
     * another line's too (closeUnrenewed filters by domain and action, not by its line or invoice). A charge of the
     * next period whose answer never came (pending: «never asked again blindly», D-17) is then asked again.
     */
    public function test_f1_close_on_one_lines_row_never_settles_another_lines_pending_charge(): void
    {
        $domain = $this->domain();
        // Last period: renewed, but the registrar showed a short expiry: its row says «check it on its next sync, then close this».
        $old = $this->paidLine($domain, '2025-11-01', '2026-11-01');
        $old->forceFill(['applied_at' => now()])->save();
        $short = ModuleAction::query()->create(['domain_id' => $domain->id, 'invoice_item_id' => $old->id, 'action' => ModuleAction::DOMAIN_RENEW,
            'status' => ModuleAction::STATUS_OPEN, 'attempts' => 1, 'last_error' => 'Renewed, but the registrar shows ...: check it on its next sync, then close this.']);
        // This period: paid, charged, no answer: pending.
        $line = $this->paidLine($domain, '2026-11-01', '2027-11-01');
        $this->script = ['timeout'];
        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($line->id));
        $pending = DomainRegistrarLog::query()->where('domain_id', $domain->id)->where('action', 'renew')->sole();
        $this->assertSame(DomainRegistrarLog::STATUS_PENDING, $pending->status);

        // Staff close the old, short row as it asks.
        $short->fresh()->closeUnrenewed(null);

        $this->assertSame(DomainRegistrarLog::STATUS_PENDING, $pending->fresh()->status,
            'another line\'s unconfirmed charge was settled «not done» by closing an unrelated row');
    }

    /**
     * F3 (money): «Close (not renewed)» on a paid renewal that is still paid (the registrar refused it; staff close it,
     * the refund to follow, or «checked at the registrar») ends only the row: its line stays a paid line waiting, and the
     * cron's next run renews it at the registrar on its own (refusedFor() needed the open row). The modal says only a
     * later Retry may charge. The order's twin (ADV-3 of D8b-2) got meta.on_hold; the renewal did not.
     */
    public function test_f3_a_renewal_closed_by_staff_is_not_charged_by_the_next_cron_run(): void
    {
        $domain = $this->domain();
        $line = $this->paidLine($domain, '2026-11-01', '2027-11-01');
        $this->script = ['refuse'];
        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertCount(1, $this->charges);

        // Staff: not renewed, we stop here (the customer is to be refunded by hand).
        ModuleAction::query()->open()->where('domain_id', $domain->id)->sole()->closeUnrenewed(null);
        Carbon::setTestNow('2026-10-20 10:30:00');
        $this->cronRenewals();

        $this->assertCount(1, $this->charges, 'closed «not renewed» by staff, charged at the registrar by the cron without any Retry');
    }

    /**
     * F4 (money): «Close (not renewed)» (and «Not done there») take no lock: clicked while a run is asking the registrar
     * (the row of an earlier failure is open, the cron runs again), they settle that run's fresh «pending» log as failed;
     * the call then gets no answer and charge() only adds its error, never setting it back to pending. The next run sees
     * no unconfirmed charge and charges again: two renewals.
     */
    public function test_f4_close_racing_a_charge_in_flight_never_lets_its_unknown_outcome_be_charged_again(): void
    {
        $domain = $this->domain();
        $line = $this->paidLine($domain, '2026-11-01', '2027-11-01');
        // An earlier failure left its row open (the registrar could not be read): the cron keeps trying.
        ModuleAction::query()->create(['domain_id' => $domain->id, 'invoice_item_id' => $line->id, 'action' => ModuleAction::DOMAIN_RENEW,
            'status' => ModuleAction::STATUS_OPEN, 'attempts' => 1, 'last_error' => 'The registrar could not be read: timeout']);
        // The cron's run: while it waits for the registrar, staff click «Close (not renewed)»; the call then times out.
        $this->script = ['timeout'];
        $this->duringCharge = fn () => ModuleAction::query()->open()->where('domain_id', $domain->id)->sole()->closeUnrenewed(null);
        app(DomainRenewalService::class)->renewLine($line->id);
        $this->assertCount(1, $this->charges);

        $this->assertSame(DomainRegistrarLog::STATUS_PENDING, DomainRegistrarLog::query()->where('domain_id', $domain->id)->where('action', 'renew')->sole()->status,
            'a charge with no answer must stay pending, whatever a click settled meanwhile');
        Carbon::setTestNow('2026-10-20 10:30:00');
        $this->cronRenewals();
        $this->assertCount(1, $this->charges, 'its outcome unknown, charged again by the next run');
    }

    // ---------------------------------------------------------------- orders

    private function order(string $fqdn): Domain
    {
        Queue::fake();
        Livewire::test(Search::class)->call('add', $fqdn)->assertRedirect(route('cart'));
        session([CurrentClient::SESSION_KEY => $this->user->client()->id]);
        Livewire::withCookie('cart', (string) \App\Models\Cart::query()->latest('id')->value('ulid'))->actingAs($this->user)->test(Cart::class)
            ->call('checkout')->assertRedirect();

        return Domain::query()->where('fqdn', $fqdn)->sole();
    }

    private function pay(Domain $domain): void
    {
        config(['settings.domain_nameserver_1' => 'ns1.example.net', 'settings.domain_nameserver_2' => 'ns2.example.net']);
        $invoice = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->sole()->invoice;
        (new RecordPaymentService)->handle($invoice, null, (string) $invoice->total, transactionId: 'tx-' . $domain->id);
        foreach (Queue::pushed(FulfilDomainOrderJob::class) as $job) {
            app()->call([$job, 'handle']);
        }
    }

    /**
     * F2 (money): «Close (stop it)» on an order whose registration got no answer settles that charge as «not done»
     * (its log failed: «Checked by staff at the registrar: not done»), though its modal says only «Stops it … Cancel or
     * refund its order separately» and «Not done there» is the action for «checked». CancelOrder then finds nothing sent
     * and cancels and refunds the order, while the registrar may have registered it at our cost.
     */
    public function test_f2_close_stop_it_keeps_an_unconfirmed_registration_so_cancel_order_never_refunds_it(): void
    {
        $this->createMode = 'down';
        $domain = $this->order('mybusiness.com');
        $this->pay($domain);
        $log = DomainRegistrarLog::query()->where('domain_id', $domain->id)->where('action', 'register')->sole();
        $this->assertSame(DomainRegistrarLog::STATUS_PENDING, $log->status, 'precondition: no answer, maybe registered');
        $action = ModuleAction::query()->open()->where('domain_id', $domain->id)->sole();

        $action->closeUnrenewed(null); // «Close (stop it)»: stop sending it; the order is staff's to cancel or refund
        $left = CancelOrder::handle(Order::query()->sole(), refund: 'manual');

        $this->assertNotSame(DomainStatus::Cancelled, $domain->fresh()->status,
            'maybe registered at the registrar, cancelled and refunded here: ' . implode('; ', array_map(fn ($e) => $e->getMessage(), $left)));
        $this->assertSame(DomainRegistrarLog::STATUS_PENDING, $log->fresh()->status, '«Close (stop it)» said nothing of checking the registrar');
    }
}
