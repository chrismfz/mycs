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
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Domains\Ordering\DomainOrderFulfilment;
use App\Services\Domains\Registrars\OpenproviderRegistrar;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\CancelOrder;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 * Adversarial review of D8b-2 (PR #180, DomainOrderFulfilment): a domain ordered is registered or its transfer started
 * at its registrar only once it is paid (or free), exactly once, and never left undelivered. Each failing test is a
 * finding (ADV-n in the PR's findings table); the passing ones are regressions for hypotheses that held.
 */
class DomainOrderFulfilmentAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** @var list<array{0: string, 1: array}> POSTs that created a domain or a transfer at the registrar */
    private array $created = [];

    /** null: answers; 'refuse': 4xx; 'refuse-echo': 4xx whose description repeats the auth code; 'down': 5xx */
    private ?string $mode = null;

    private string $status = 'ACT';

    /** RDAP of nixpal.com: 'ok' (two nameservers), 'down' (503), 'one' (one nameserver) */
    private string $rdap = 'ok';

    /** Runs while the registrar handles the create (a race with something else). */
    private ?\Closure $during = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
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
        config(['settings.default_currency' => 'EUR', 'settings.domain_billing_enabled' => true]);
        session(['currency' => 'EUR']);
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $com = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'sort' => 1, 'registrar_connection_id' => $op->id, 'epp_code_required' => true]);
        foreach ([['register', 1, '23.33'], ['renew', 1, '23.33'], ['transfer', 1, '20.00']] as [$operation, $years, $price]) {
            DomainTldPrice::query()->create(['domain_tld_id' => $com->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
        }
        $this->user = User::factory()->create();
        $this->user->client()->ensurePrimaryContact()->update(['first_name' => 'Maria', 'last_name' => 'P', 'email' => 'maria@example.test',
            'phone' => '+30.2100000000', 'address1' => 'Odos 1', 'city' => 'Athina', 'postcode' => '10000', 'country' => 'GR']);
        Http::fake(fn (Request $request) => $this->registrar($request));
    }

    protected function tearDown(): void
    {
        LaravelTransport::$resolve = null;
        OpenproviderRegistrar::$glueResolver = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function registrar(Request $request)
    {
        $url = $request->url();
        $path = (string) parse_url($url, PHP_URL_PATH);

        return match (true) {
            str_contains($url, '/auth/login') => Http::response(['data' => ['token' => 'tok']]),
            str_contains($url, '/domains/check') => Http::response(['data' => ['results' => array_map(fn ($d) => [
                'domain' => $d['name'] . '.' . $d['extension'],
                'status' => $d['name'] . '.' . $d['extension'] === 'nixpal.com' ? 'active' : 'free',
            ], $request->data()['domains'])]]),
            $request->method() === 'POST' && str_ends_with($path, '/customers') => Http::response(['data' => ['handle' => 'MP000001-GR']]),
            $request->method() === 'POST' && (str_ends_with($path, '/domains') || str_ends_with($path, '/domains/transfer')) => $this->create($path, $request->data()),
            $request->method() === 'GET' && str_contains($path, '/v1beta/domains') => Http::response(['data' => ['results' => $this->created === [] ? [] : [$this->record()], 'total' => $this->created === [] ? 0 : 1] + $this->record()]),
            str_contains($url, 'data.iana.org') => Http::response(['services' => [[['com'], ['https://rdap.example/']]]]),
            str_contains($url, 'rdap.example/domain/nixpal.com') => match ($this->rdap) {
                'down' => Http::response([], 503),
                'one' => Http::response(['objectClassName' => 'domain', 'ldhName' => 'nixpal.com', 'nameservers' => [['ldhName' => 'NS1.MYIP.GR']]]),
                default => Http::response(['objectClassName' => 'domain', 'ldhName' => 'nixpal.com',
                    'events' => [['eventAction' => 'expiration', 'eventDate' => '2027-11-17T18:08:48Z']],
                    'nameservers' => [['ldhName' => 'NS1.MYIP.GR'], ['ldhName' => 'NS2.MYIP.GR']]]),
            },
            default => Http::response([], 404),
        };
    }

    private function create(string $path, array $data)
    {
        if ($this->during !== null) {
            ($this->during)();
        }
        if ($this->mode === 'refuse') {
            return Http::response(['code' => 399, 'desc' => 'Invalid auth code'], 400);
        }
        if ($this->mode === 'refuse-echo') {
            return Http::response(['code' => 399, 'desc' => 'Invalid auth code ' . ($data['auth_code'] ?? '')], 400);
        }
        if ($this->mode === 'down') {
            return Http::response([], 503);
        }
        $this->created[] = [$path, $data];

        return Http::response(['data' => ['id' => 9001, 'status' => $this->status]]);
    }

    private function record(): array
    {
        $fqdn = $this->created === [] ? '' : $this->created[0][1]['domain']['name'] . '.' . $this->created[0][1]['domain']['extension'];

        return ['id' => 9001, 'domain' => ['name' => explode('.', $fqdn)[0], 'extension' => 'com'], 'status' => $this->status,
            'expiration_date' => '2027-10-20 10:00:00', 'name_servers' => array_map(fn ($ns) => ['name' => $ns['name']], $this->created[0][1]['name_servers'] ?? []),
            'owner_handle' => 'MP000001-GR', 'admin_handle' => 'MP000001-GR', 'tech_handle' => 'MP000001-GR', 'billing_handle' => 'MP000001-GR'];
    }

    private function order(string $how, string $fqdn, array $set = []): Domain
    {
        Livewire::test(Search::class)->call($how, $fqdn)->assertRedirect(route('cart'));
        session([CurrentClient::SESSION_KEY => $this->user->client()->id]);
        $cart = Livewire::withCookie('cart', (string) \App\Models\Cart::query()->latest('id')->value('ulid'))->actingAs($this->user)->test(Cart::class);
        $item = \App\Models\Cart::query()->latest('id')->first()->items()->sole();
        foreach ($set as $key => $value) {
            $cart->set(str_replace('{item}', (string) $item->id, $key), $value);
        }
        $cart->call('checkout')->assertRedirect();

        return Domain::query()->where('fqdn', $fqdn)->sole();
    }

    private function invoiceOf(Domain $domain): Invoice
    {
        return InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->sole()->invoice;
    }

    private function pay(Domain $domain, string $nameservers = "ns1.nixpal.com\nns2.nixpal.com", ?string $amount = null): void
    {
        config(['settings.domain_default_nameservers' => $nameservers]); // a checkout reloads the settings
        $invoice = $this->invoiceOf($domain);
        (new RecordPaymentService)->handle($invoice, null, $amount ?? (string) $invoice->total, transactionId: 'tx-' . $domain->id . '-' . ($amount ?? 'full'));
    }

    private function runJobs(): void
    {
        foreach (Queue::pushed(FulfilDomainOrderJob::class) as $job) {
            app()->call([$job, 'handle']);
        }
    }

    private function cron(string $at = '2026-10-20 10:30:00'): void
    {
        Carbon::setTestNow($at);
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Findings (fail on the PR as it is)
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * ADV-1 (high): a domain ordered whose unpaid invoice staff delete (allowed: it has no payments; its lines go by
     * cascade) has no line left: due() takes «no own line» as a free transfer and the cron registers it at our cost.
     */
    public function test_adv1_an_order_whose_unpaid_invoice_was_deleted_is_not_registered_for_free(): void
    {
        $domain = $this->order('add', 'mybusiness.com');
        config(['settings.domain_default_nameservers' => "ns1.nixpal.com\nns2.nixpal.com"]);
        $this->invoiceOf($domain)->delete();
        $this->assertSame(DomainStatus::PendingRegister, $domain->fresh()->status, 'deleting the invoice leaves the domain pending');

        $this->cron();

        $this->assertSame([], $this->created, 'registered at the registrar although nobody paid for it');
    }

    /**
     * ADV-1b (high): the same hole in fulfil() itself. lineOf() leaves out lines on cancelled invoices, so a domain whose
     * only line is on a cancelled invoice reads as free (no line): a job still queued from a payment taken back, or a
     * staff Retry, registers it.
     */
    public function test_adv1b_a_domain_whose_line_is_on_a_cancelled_invoice_is_not_treated_as_free(): void
    {
        $domain = $this->order('add', 'mybusiness.com');
        config(['settings.domain_default_nameservers' => "ns1.nixpal.com\nns2.nixpal.com"]);
        // E.g. a service of the same order cancelled first took the whole invoice (D8b-1 code review H1); the domain
        // stays pending until the unpaid-order step cancels it, cronjob_order_cancel days later.
        Invoice::query()->whereKey($this->invoiceOf($domain)->id)->update(['status' => Invoice::STATUS_CANCELLED]);

        $outcome = app(DomainOrderFulfilment::class)->fulfil($domain->id);

        $this->assertNotSame(DomainOrderFulfilment::SENT, $outcome);
        $this->assertSame([], $this->created, 'registered although its only line was cancelled, unpaid');
    }

    /**
     * ADV-2 (high): a transfer that was sent stays pending_transfer for days (the order stays pending with it). Staff's
     * «Cancel Order» (or «Cancel & Refund», «Fraud») then cancels the domain here although the registrar is moving it
     * into our account at our cost: the customer refunded, the domain lost to the sync (Cancelled blocks it).
     */
    public function test_adv2_cancelling_the_order_after_its_domain_was_sent_does_not_cancel_the_domain(): void
    {
        $this->status = 'REQ';
        $domain = $this->order('transfer', 'nixpal.com', ['epp.{item}' => 'Epp#Code-77']);
        $this->pay($domain);
        $this->runJobs();
        $this->assertCount(1, $this->created);
        $domain->refresh();
        $this->assertNotNull($domain->meta['sent_at'] ?? null);
        $this->assertSame(Order::STATUS_PENDING, Order::query()->sole()->status, 'the order stays pending while the transfer runs');

        CancelOrder::handle(Order::query()->sole());

        $this->assertNotSame(DomainStatus::Cancelled, $domain->fresh()->status,
            'a domain already sent to the registrar (paid there by us) was cancelled with its order');
    }

    /**
     * ADV-2b (high): the same, racing. CancelOrder locks the domain row; fulfil() only holds the cache lock and keeps a
     * stale model through the registrar call. An order cancelled during the call ends Cancelled (and refundable) while
     * the registrar registered it, and the customer still gets «registered».
     */
    public function test_adv2b_an_order_cancelled_during_the_registrar_call_does_not_end_cancelled_but_registered(): void
    {
        $domain = $this->order('add', 'mybusiness.com');
        $this->pay($domain);
        $this->during = function () {
            $this->during = null;
            CancelOrder::handle(Order::query()->sole());
        };
        $this->runJobs();

        $this->assertCount(1, $this->created, 'registered at the registrar');
        $this->assertNotSame(DomainStatus::Cancelled, $domain->fresh()->status,
            'registered at the registrar, cancelled (and refundable) here');
    }

    /**
     * ADV-3 (medium): «Close (not done)» does not stop anything. The next cron run (due(): no open action any more)
     * sends it again on its own, before any Retry: a registrar that refused is asked again every time staff close it,
     * and an order staff closed to refund by hand is registered anyway.
     */
    public function test_adv3_closing_a_refused_order_does_not_let_the_cron_send_it_again_on_its_own(): void
    {
        $this->mode = 'refuse';
        $domain = $this->order('add', 'mybusiness.com');
        $this->pay($domain);
        $this->runJobs();
        $action = ModuleAction::query()->sole();
        $this->assertSame(ModuleAction::DOMAIN_REGISTER, $action->action);

        // Staff: «not done, and we will not do it» (e.g. refunded by hand).
        $action->closeUnrenewed(null);
        $this->mode = null;
        $this->cron();

        $this->assertSame([], $this->created, 'closed by staff, registered by the cron without a Retry');
    }

    /**
     * ADV-4 (medium-high): a transfer must keep the nameservers it has (owner 2026-10-17, D-16: always the current ones;
     * its mail says «your website and email keep working»). When RDAP cannot be read (an outage, a TLD without RDAP)
     * the company's defaults are sent instead: the customer's DNS moves to us and the site goes down.
     */
    public function test_adv4_a_transfer_never_sends_the_company_nameservers_when_the_registry_could_not_be_read(): void
    {
        $this->status = 'REQ';
        $this->rdap = 'down';
        $domain = $this->order('transfer', 'nixpal.com', ['epp.{item}' => 'Epp#Code-77']);
        $this->pay($domain, "ns1.company.example\nns2.company.example");
        $this->runJobs();

        $sent = array_column($this->created[0][1]['name_servers'] ?? [], 'name');
        $this->assertNotSame(['ns1.company.example', 'ns2.company.example'], $sent,
            'the transfer replaced the domain\'s live nameservers with the company defaults because RDAP was down');
    }

    /** ADV-4b (medium): one nameserver read from the registry is thrown away for the defaults (not kept or asked). */
    public function test_adv4b_a_transfer_with_one_registry_nameserver_does_not_swap_it_for_the_defaults(): void
    {
        $this->status = 'REQ';
        $this->rdap = 'one';
        $domain = $this->order('transfer', 'nixpal.com', ['epp.{item}' => 'Epp#Code-77']);
        $this->pay($domain, "ns1.company.example\nns2.company.example");
        $this->runJobs();

        $sent = array_column($this->created[0][1]['name_servers'] ?? [], 'name');
        $this->assertNotSame(['ns1.company.example', 'ns2.company.example'], $sent, 'its live nameserver was replaced');
    }

    /**
     * ADV-5 (medium-high): a TLD without a registrar API is «register it by hand, then Done by hand». Done by hand only
     * marks it sent: no sync ever runs for it (no API), so it stays pending_register for good, without an expiry
     * (never invoiced for renewal, never reminded), and its order stays pending. A paid domain never delivered here.
     */
    public function test_adv5_done_by_hand_on_a_tld_without_an_api_delivers_the_domain(): void
    {
        $domain = $this->order('add', 'mybusiness.com');
        DomainRegistrarConnection::query()->update(['is_active' => false]);
        $this->pay($domain);
        $this->runJobs();
        $action = ModuleAction::query()->sole();
        $this->assertSame(__('domains.order.by_hand', ['domain' => 'mybusiness.com']), $action->last_error);

        $action->markResolved(null);
        $this->cron('2026-10-21 10:00:00');

        $domain->refresh();
        $this->assertSame(DomainStatus::Active, $domain->status, 'registered by hand, still pending_register');
        $this->assertSame('2027-10-20', $domain->expires_at?->toDateString(), 'no expiry: never invoiced for renewal');
        $this->assertSame(Order::STATUS_ACTIVE, Order::query()->sole()->status);
    }

    /**
     * ADV-6 (low): anything thrown before the registrar's POST (a nameserver inside the domain without glue, a contact
     * without a country, a customer handle that could not be made) is filed as «no answer»: its log stays pending, so
     * every Retry says «an earlier request has no answer» and staff must Close a charge that never existed.
     */
    public function test_adv6_a_failure_before_anything_is_sent_is_not_left_as_an_unconfirmed_charge(): void
    {
        OpenproviderRegistrar::$glueResolver = fn (string $host) => null;
        $domain = $this->order('add', 'mybusiness.com');
        $this->pay($domain, "ns1.mybusiness.com\nns2.mybusiness.com");
        $this->runJobs();

        $this->assertSame([], $this->created);
        $this->assertNotSame(DomainRegistrarLog::STATUS_PENDING, DomainRegistrarLog::query()->where('domain_id', $domain->id)->value('status'),
            'nothing reached the registrar, yet it is kept as an unconfirmed charge');
    }

    /**
     * ADV-7 (low, hardening): a refusal's description is stored as is in the registrar log, the Pending Module Action
     * (shown to staff and to the MCP) and the error report. Redact::text knows the stored secrets, not this domain's EPP
     * code: a registrar that repeats the code it refused leaks it.
     */
    public function test_adv7_a_refusal_that_repeats_the_epp_code_does_not_store_it(): void
    {
        $this->mode = 'refuse-echo';
        $domain = $this->order('transfer', 'nixpal.com', ['epp.{item}' => 'Epp#Code-77']);
        $this->pay($domain);
        $this->runJobs();

        $this->assertStringNotContainsString('Epp#Code-77', (string) ModuleAction::query()->sole()->last_error);
        $this->assertStringNotContainsString('Epp#Code-77', (string) DomainRegistrarLog::query()->where('domain_id', $domain->id)->value('error'));
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Hypotheses that held (regressions)
    // ---------------------------------------------------------------------------------------------------------------

    public function test_the_job_twice_the_cron_and_a_held_lock_send_it_once(): void
    {
        $domain = $this->order('add', 'mybusiness.com');
        $this->pay($domain);

        $lock = Cache::lock("domain-registrar:{$domain->id}", 600);
        $lock->get();
        $this->assertSame(DomainOrderFulfilment::BUSY, app(DomainOrderFulfilment::class)->fulfil($domain->id));
        $lock->release();

        $this->runJobs();
        $this->runJobs();
        $this->cron();
        $this->assertCount(1, $this->created);
        $this->assertSame(1, DomainRegistrarLog::query()->where('domain_id', $domain->id)->count());
    }

    public function test_a_partial_payment_sends_nothing(): void
    {
        $domain = $this->order('add', 'mybusiness.com');
        $this->pay($domain, amount: '5.00');

        Queue::assertNotPushed(FulfilDomainOrderJob::class);
        $this->assertSame(0, DomainOrderFulfilment::due()->count());
        $this->assertSame(DomainOrderFulfilment::WAITING, app(DomainOrderFulfilment::class)->fulfil($domain->id));
        $this->assertSame([], $this->created);
    }

    public function test_an_order_cancelled_after_payment_but_before_the_job_is_never_sent(): void
    {
        $domain = $this->order('add', 'mybusiness.com');
        $this->pay($domain);
        CancelOrder::handle(Order::query()->sole());

        $this->runJobs();
        $this->cron();
        $this->assertSame([], $this->created);
    }

    public function test_a_refunded_invoice_is_never_sent(): void
    {
        $domain = $this->order('add', 'mybusiness.com');
        $this->pay($domain);
        Invoice::query()->whereKey($this->invoiceOf($domain)->id)->update(['status' => Invoice::STATUS_REFUNDED]);

        $this->runJobs();
        $this->cron();
        $this->assertSame([], $this->created);
    }

    public function test_garbage_default_nameservers_send_nothing(): void
    {
        $domain = $this->order('add', 'mybusiness.com');
        $this->pay($domain, "127.0.0.1\nlocalhost\nNS1.EXAMPLE.COM\nns1.example.com.\n-bad-.com");
        $this->runJobs();

        $this->assertSame([], $this->created);
        $this->assertSame(__('domains.order.no_nameservers', ['domain' => 'mybusiness.com']), ModuleAction::query()->sole()->last_error);
    }

    public function test_an_open_renewal_action_on_the_domain_is_left_alone(): void
    {
        $domain = $this->order('add', 'mybusiness.com');
        $renew = ModuleAction::query()->create(['domain_id' => $domain->id, 'action' => ModuleAction::DOMAIN_RENEW, 'status' => ModuleAction::STATUS_OPEN]);
        $this->pay($domain);
        $this->runJobs();

        $this->assertCount(1, $this->created);
        $this->assertSame(ModuleAction::STATUS_OPEN, $renew->fresh()->status);
    }

    public function test_no_answer_then_the_cron_and_retry_never_ask_again(): void
    {
        $this->mode = 'down';
        $domain = $this->order('add', 'mybusiness.com');
        $this->pay($domain);
        $this->runJobs();
        $this->mode = null;
        ModuleAction::query()->delete(); // even with its staff row gone, the pending log alone holds it

        $this->cron();
        $this->assertSame(DomainOrderFulfilment::STAFF, app(DomainOrderFulfilment::class)->fulfil($domain->id));
        $this->assertSame([], $this->created);
    }
}
