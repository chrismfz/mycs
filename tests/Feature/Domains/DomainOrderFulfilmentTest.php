<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Jobs\Domains\FulfilDomainOrderJob;
use App\Livewire\Cart;
use App\Livewire\DomainShop\Search;
use App\Mail\Mail;
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
use App\Services\Invoice\RecordPaymentService;
use App\Services\Service\RetryModuleAction;
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
 * D8b-2: a domain ordered, once paid (or free), registered or its transfer started at its registrar, once. A registration
 * gets the company's nameservers, a transfer the registry's; a refusal or no answer goes to staff (Pending Module
 * Actions), never asked again on its own.
 */
class DomainOrderFulfilmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** @var list<array{0: string, 1: array}> POSTs to the registrar's domain endpoints */
    private array $created = [];

    /** null: answers; 'refuse': 4xx; 'down': 5xx */
    private ?string $mode = null;

    /** The registrar's record status after the call: ACT for a registration, REQ for a transfer. */
    private string $status = 'ACT';

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
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Openprovider: nixpal.com is registered elsewhere (RDAP: ns1/ns2.myip.gr), every other name free. */
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
            str_contains($url, 'rdap.example/domain/nixpal.com') => Http::response(['objectClassName' => 'domain', 'ldhName' => 'nixpal.com',
                'events' => [['eventAction' => 'expiration', 'eventDate' => '2027-11-17T18:08:48Z']],
                'nameservers' => [['ldhName' => 'NS1.MYIP.GR'], ['ldhName' => 'NS2.MYIP.GR']]]),
            default => Http::response([], 404),
        };
    }

    private function create(string $path, array $data)
    {
        if ($this->mode === 'refuse') {
            return Http::response(['code' => 399, 'desc' => 'Invalid auth code'], 400);
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

    private function pay(Domain $domain, string $nameservers = "NS1.nixpal.com\nns2.nixpal.com\nnot_a_host"): void
    {
        // Set here: a checkout reloads the settings from the database.
        config(['settings.domain_default_nameservers' => $nameservers]);
        $invoice = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->sole()->invoice;
        (new RecordPaymentService)->handle($invoice, null, (string) $invoice->total, transactionId: 'tx-' . $domain->id);
    }

    private function runJobs(): void
    {
        foreach (Queue::pushed(FulfilDomainOrderJob::class) as $job) {
            app()->call([$job, 'handle']);
        }
    }

    public function test_paid_it_is_registered_with_the_company_nameservers_and_turns_active(): void
    {
        $domain = $this->order('add', 'mybusiness.com');
        Queue::assertNotPushed(FulfilDomainOrderJob::class); // not before it is paid
        $this->assertSame(DomainOrderFulfilment::WAITING, app(DomainOrderFulfilment::class)->fulfil($domain->id));

        $this->pay($domain);
        Queue::assertPushed(FulfilDomainOrderJob::class, 1);
        $this->runJobs();

        $this->assertCount(1, $this->created, (string) ModuleAction::query()->value('last_error') . json_encode(DomainRegistrarLog::query()->get()));
        [$path, $sent] = $this->created[0];
        $this->assertStringEndsWith('/domains', $path);
        $this->assertSame(['ns1.nixpal.com', 'ns2.nixpal.com'], array_column($sent['name_servers'], 'name'));
        $this->assertSame(1, $sent['period']);
        $domain->refresh();
        $this->assertSame([DomainStatus::Active, '2027-10-20', '9001'], [$domain->status, $domain->expires_at?->toDateString(), $domain->registrar_domain_id]);
        $this->assertNotNull($domain->meta['sent_at'] ?? null);
        $this->assertNotNull(InvoiceItem::query()->where('reference_id', $domain->id)->value('applied_at'));
        $this->assertSame(DomainRegistrarLog::STATUS_OK, DomainRegistrarLog::query()->where('domain_id', $domain->id)->where('action', 'register')->value('status'));
        $this->assertSame(Order::STATUS_ACTIVE, Order::query()->sole()->status);
        $this->assertSame(0, ModuleAction::query()->count());
        $this->assertContains(['domain_registered', 'Your domain mybusiness.com is registered'],
            MailFacade::queued(Mail::class)->map(fn (Mail $mail) => [$mail->templateKey, $mail->renderedSubject])->values()->all());

        // Never twice: the job again, the cron, staff's Retry.
        $this->assertSame(DomainOrderFulfilment::DONE, app(DomainOrderFulfilment::class)->fulfil($domain->id));
        Carbon::setTestNow('2026-10-20 11:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertCount(1, $this->created);
    }

    public function test_a_transfer_keeps_the_registry_nameservers_sends_its_code_and_stays_pending(): void
    {
        $this->status = 'REQ';
        $domain = $this->order('transfer', 'nixpal.com', ['epp.{item}' => 'Epp#Code-77']);

        $this->pay($domain);
        $this->runJobs();

        [$path, $sent] = $this->created[0];
        $this->assertStringEndsWith('/domains/transfer', $path);
        $this->assertSame('Epp#Code-77', $sent['auth_code']);
        $this->assertSame(['ns1.myip.gr', 'ns2.myip.gr'], array_column($sent['name_servers'], 'name'), 'the nameservers it has (owner 2026-10-17)');
        $domain->refresh();
        $this->assertSame(DomainStatus::PendingTransfer, $domain->status, 'pending until the registrar completes it');
        $this->assertContains('domain_transfer_started', MailFacade::queued(Mail::class)->map(fn (Mail $mail) => $mail->templateKey)->all());
        $this->assertNotNull($domain->meta['sent_at'] ?? null);
    }

    public function test_a_refusal_goes_to_staff_and_is_not_sent_again_until_they_retry(): void
    {
        $this->mode = 'refuse';
        $domain = $this->order('transfer', 'nixpal.com', ['epp.{item}' => 'wrong']);
        $this->pay($domain);
        $this->runJobs();

        $action = ModuleAction::query()->sole();
        $this->assertSame([ModuleAction::DOMAIN_TRANSFER, ModuleAction::STATUS_OPEN], [$action->action, $action->status]);
        $this->assertSame(DomainRegistrarLog::STATUS_FAILED, DomainRegistrarLog::query()->where('domain_id', $domain->id)->value('status'));
        $this->assertSame(DomainStatus::PendingTransfer, $domain->fresh()->status);

        // The cron does not ask again on its own.
        Carbon::setTestNow('2026-10-20 11:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(1, DomainRegistrarLog::query()->where('domain_id', $domain->id)->count());

        // The customer sends the right code; staff retry.
        $domain->forceFill(['epp_code' => 'Right-Code'])->save();
        $this->mode = null;
        $this->status = 'REQ';
        $this->assertTrue(RetryModuleAction::run($action));
        $this->assertSame('Right-Code', $this->created[0][1]['auth_code']);
        $this->assertSame(ModuleAction::STATUS_COMPLETED, $action->fresh()->status);
    }

    public function test_no_answer_leaves_its_charge_pending_and_never_sends_it_again(): void
    {
        $this->mode = 'down';
        $domain = $this->order('add', 'mybusiness.com');
        $this->pay($domain);
        $this->runJobs();

        $this->assertSame(DomainRegistrarLog::STATUS_PENDING, DomainRegistrarLog::query()->where('domain_id', $domain->id)->value('status'));
        $action = ModuleAction::query()->sole();
        $this->assertSame(ModuleAction::DOMAIN_REGISTER, $action->action);

        // Up again: a retry still does not send it (it may have been registered); staff check the registrar.
        $this->mode = null;
        RetryModuleAction::run($action);
        $this->assertSame([], $this->created);
        $this->assertSame(1, DomainRegistrarLog::query()->where('domain_id', $domain->id)->count());

        // Registered there by the first call: «Done by hand» hands it to the sync.
        $action->fresh()->markResolved(null);
        $domain->refresh();
        $this->assertNotNull($domain->meta['sent_at'] ?? null);
        $this->assertNotNull(InvoiceItem::query()->where('reference_id', $domain->id)->value('applied_at'));
        $this->assertSame(DomainRegistrarLog::STATUS_OK, DomainRegistrarLog::query()->where('domain_id', $domain->id)->value('status'));
        $this->assertSame(0, DomainOrderFulfilment::due()->count());
    }

    public function test_closing_it_settles_the_unconfirmed_charge_so_a_retry_can_send_it(): void
    {
        $this->mode = 'down';
        $domain = $this->order('add', 'mybusiness.com');
        $this->pay($domain);
        $this->runJobs();

        // Checked at the registrar: not registered. Closed, then sent again.
        ModuleAction::query()->sole()->closeUnrenewed(null);
        $this->assertSame(DomainRegistrarLog::STATUS_FAILED, DomainRegistrarLog::query()->where('domain_id', $domain->id)->value('status'));
        $this->mode = null;
        $this->assertSame(DomainOrderFulfilment::SENT, app(DomainOrderFulfilment::class)->fulfil($domain->id));
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);
    }

    public function test_without_nameservers_or_a_registrar_api_it_waits_for_staff(): void
    {
        $domain = $this->order('add', 'mybusiness.com');
        $this->pay($domain, '');
        $this->runJobs();

        $this->assertSame([], $this->created);
        $this->assertSame([ModuleAction::DOMAIN_REGISTER, __('domains.order.no_nameservers', ['domain' => 'mybusiness.com'])],
            [ModuleAction::query()->sole()->action, ModuleAction::query()->sole()->last_error]);
        $this->assertSame(0, DomainRegistrarLog::query()->count(), 'nothing asked of the registrar');

        DomainRegistrarConnection::query()->update(['is_active' => false]);
        ModuleAction::query()->delete();
        $this->assertSame(DomainOrderFulfilment::STAFF, app(DomainOrderFulfilment::class)->fulfil($domain->id));
        $this->assertSame(__('domains.order.by_hand', ['domain' => 'mybusiness.com']), ModuleAction::query()->sole()->last_error);
    }

    public function test_an_unpaid_or_cancelled_order_is_never_sent(): void
    {
        $domain = $this->order('add', 'mybusiness.com');
        $this->assertSame(0, DomainOrderFulfilment::due()->count());
        Invoice::query()->update(['status' => Invoice::STATUS_CANCELLED]);
        $domain->forceFill(['status' => DomainStatus::Cancelled])->save();

        $this->assertSame(DomainOrderFulfilment::DONE, app(DomainOrderFulfilment::class)->fulfil($domain->id));
        $this->assertSame([], $this->created);
    }
}
