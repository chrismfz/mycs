<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Jobs\Domains\FulfilDomainOrderJob;
use App\Livewire\Cart;
use App\Livewire\DomainShop\Search;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\Order;
use App\Models\User;
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Domains\Registrars\OpenproviderRegistrar;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\CancelOrder;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Nixpal\DomainTools\Whois\SocketConnector;
use Tests\TestCase;

/**
 * Sweep 8, domains (adversarial verifier). Each failing test is a finding. The registrar is the Http fake of
 * DomainOrderFulfilmentAdversarialTest: no network.
 */
class Sweep8DomainsTest extends TestCase
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
            $request->method() === 'GET' && str_contains($path, '/v1/domains') => Http::response(['data' => ['results' => $this->created === [] ? [] : [$this->record()], 'total' => $this->created === [] ? 0 : 1] + $this->record()]),
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

        return Http::response(['code' => 0, 'data' => ['id' => 9001, 'status' => $this->status]]);
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
        config(self::ns($nameservers)); // a checkout reloads the settings
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

    /**
     * S8-DOMAINS-1: CancelOrder's own rule (docs/domains.md: «δεν ακυρώνει ούτε επιστρέφει χρήματα για domain που στάλθηκε»)
     * holds only while the domain is still pending. A registration that completed is Active: «Cancel & Refund» gives the
     * whole payment back, says nothing, and the name stays registered at our cost.
     */
    public function test_s8_domains_1_cancel_and_refund_does_not_silently_refund_a_domain_registered_at_the_registrar(): void
    {
        $domain = $this->order('add', 'mybusiness.com');
        $this->pay($domain);
        $this->runJobs();
        $this->assertCount(1, $this->created, 'registered at the registrar');
        $domain->refresh();
        $this->assertSame(DomainStatus::Active, $domain->status, 'the registration completed');
        $invoice = $this->invoiceOf($domain);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $client = $this->user->client();
        $creditBefore = (string) DB::table('credits')->where('client_id', $client->id)->sum('amount');

        $left = CancelOrder::handle(Order::query()->sole(), refund: 'credit');

        $creditAfter = (string) DB::table('credits')->where('client_id', $client->id)->sum('amount');
        $refunded = bccomp($creditAfter, $creditBefore, 2) !== 0 || $invoice->fresh()->status !== Invoice::STATUS_PAID;
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status, 'the domain stays registered and Active');
        $this->assertFalse($refunded && $left === [],
            'the payment of a domain registered at the registrar (paid there by us) was refunded in full, the domain stays Active, and staff were told nothing');
    }

    /**
     * S8-DOMAINS-1, the fix (owner 2026-11-11: tell staff, no automation): the refund goes ahead, the registrar is not
     * called, the domain keeps its status, and staff get a notice and a «check this» task that names the domain.
     */
    public function test_s8_domains_1_cancel_and_refund_of_a_registered_domain_opens_a_task_that_names_it(): void
    {
        $domain = $this->order('add', 'mybusiness.com');
        $this->pay($domain);
        $this->runJobs();
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);
        $order = Order::query()->sole();
        $this->assertSame(['mybusiness.com'], CancelOrder::registeredDomains($order)->pluck('fqdn')->all(), 'what the confirmation warns about');
        $calls = count($this->created);
        Http::fake(fn (Request $request) => $this->fail('Cancel & Refund called the registrar: ' . $request->url()));

        $left = CancelOrder::handle($order, refund: 'credit');

        $this->assertSame(["domain #{$domain->id}"], array_keys($left));
        $this->assertStringContainsString('mybusiness.com is already registered at the registrar: order #', $left["domain #{$domain->id}"]->getMessage());
        $task = ModuleAction::query()->where('domain_id', $domain->id)->where('action', ModuleAction::DOMAIN_REFUNDED)->sole();
        $this->assertSame(ModuleAction::STATUS_OPEN, $task->status);
        $this->assertStringContainsString('mybusiness.com', (string) $task->last_error);
        $this->assertTrue($task->isCheck(), 'a task to look at, not a failure');
        $this->assertSame(1, ModuleAction::openChecks()['domains']);
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status, 'its status is not changed');
        $this->assertSame($calls, count($this->created));
        $this->assertSame(Invoice::STATUS_REFUNDED, $this->invoiceOf($domain)->fresh()->status, 'the refund staff asked for was made');
        $this->assertSame('23.33', number_format((float) DB::table('credits')->where('client_id', $this->user->client()->id)->sum('amount'), 2, '.', ''));

        $task->markResolved(null);
        $this->assertSame(ModuleAction::STATUS_RESOLVED, $task->fresh()->status, 'closed by staff («Checked»)');
    }

    /**
     * «Cancel Order» (no refund) of a registered domain: it stays Active and paid, the registrar is not called, and staff
     * are told with the same task, in the one text that claims nothing about refunds (open item of the sweep-8 reviews; this test first pinned
     * «tells nothing»). More in Sweep8OpenCancelOrderDomainTest.
     */
    public function test_cancel_order_without_a_refund_of_a_registered_domain_opens_the_task_too(): void
    {
        $domain = $this->order('add', 'mybusiness.com');
        $this->pay($domain);
        $this->runJobs();
        $calls = count($this->created);
        Http::fake(fn (Request $request) => $this->fail('Cancel Order called the registrar: ' . $request->url()));

        $left = CancelOrder::handle(Order::query()->sole());

        $this->assertSame(["domain #{$domain->id}"], array_keys($left));
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $this->invoiceOf($domain)->fresh()->status);
        $this->assertSame($calls, count($this->created));
        $task = ModuleAction::query()->where('domain_id', $domain->id)->where('action', ModuleAction::DOMAIN_REFUNDED)->sole();
        $this->assertStringContainsString('mybusiness.com is already registered at the registrar: order #', (string) $task->last_error);
        $this->assertStringContainsString('does not undo the registration (check its invoice for what was refunded)', (string) $task->last_error);
    }

    /** The neighbour: a domain ordered and never sent is cancelled with its order as before, with nothing to tell. */
    public function test_cancel_and_refund_of_a_domain_not_registered_cancels_it_as_before(): void
    {
        $pending = $this->order('add', 'mybusiness.com');
        $order = Order::query()->sole();
        $this->assertSame([], CancelOrder::registeredDomains($order)->all());

        $this->assertSame([], CancelOrder::handle($order, refund: 'credit'));

        $this->assertSame(DomainStatus::Cancelled, $pending->fresh()->status);
        $this->assertSame(0, ModuleAction::query()->where('action', ModuleAction::DOMAIN_REFUNDED)->count());
    }

    private static function ns(string $hosts): array
    {
        $list = preg_split('/\s+/', trim($hosts)) ?: [];

        return collect(range(1, 5))->mapWithKeys(fn (int $n) => ["settings.domain_nameserver_{$n}" => $list[$n - 1] ?? ''])->all();
    }
}
