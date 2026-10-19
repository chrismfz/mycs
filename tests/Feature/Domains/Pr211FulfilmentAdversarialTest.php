<?php

namespace Tests\Feature\Domains;

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
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\Order;
use App\Models\User;
use App\Services\Domains\Lookup\LaravelTransport;
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
 * PR #211 adversarial review: a paid registration whose POST /v1/domains Openprovider answers 2xx with an error code
 * (guard: passes) or 2xx without its envelope at all (fails on the PR). Setup copied from DomainOrderFulfilmentTest.
 */
class Pr211FulfilmentAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** @var list<array{0: string, 1: array}> POSTs to the registrar's domain endpoints */
    private array $created = [];

    /** null: answers; 'refuse': 4xx; 'down': 5xx; 'code': 200 + code ≠ 0; 'html': 200 without the envelope */
    private ?string $mode = null;

    /** Run while the registrar is asked (a cancel racing the call). */
    private ?\Closure $during = null;

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
            $request->method() === 'GET' && str_contains($path, '/v1/domains') => Http::response(['data' => ['results' => $this->created === [] ? [] : [$this->record()], 'total' => $this->created === [] ? 0 : 1] + $this->record()]),
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
        if ($this->mode === 'code') {
            return Http::response(['code' => 10001, 'desc' => 'Internal error', 'data' => []], 200);
        }
        if ($this->mode === 'html') {
            return Http::response('<html><body>Scheduled maintenance</body></html>', 200, ['Content-Type' => 'text/html']);
        }
        $this->created[] = [$path, $data];
        if ($this->during !== null) {
            ($this->during)();
        }

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
        config(self::ns($nameservers));
        $invoice = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->sole()->invoice;
        (new RecordPaymentService)->handle($invoice, null, (string) $invoice->total, transactionId: 'tx-' . $domain->id);
    }

    private function runJobs(): void
    {
        foreach (Queue::pushed(FulfilDomainOrderJob::class) as $job) {
            app()->call([$job, 'handle']);
        }
    }

    /** GUARD (passes): a registration answered 200 + code ≠ 0 is «maybe done»: pending, staff, never sent again blindly. */
    public function test_guard_a_registration_answered_200_with_an_error_code_is_pending_and_never_sent_again(): void
    {
        $this->mode = 'code';
        $domain = $this->order('add', 'mybusiness.com');
        $this->pay($domain);
        $this->runJobs();

        $this->assertSame(DomainRegistrarLog::STATUS_PENDING, DomainRegistrarLog::query()->where('domain_id', $domain->id)->value('status'));
        $this->assertNull(InvoiceItem::query()->where('reference_id', $domain->id)->value('applied_at'));
        $this->assertSame(ModuleAction::DOMAIN_REGISTER, ModuleAction::query()->sole()->action);
        $this->assertNotContains('domain_registered', MailFacade::queued(Mail::class)->map(fn (Mail $mail) => $mail->templateKey)->values()->all());

        $this->mode = null;
        RetryModuleAction::run(ModuleAction::query()->sole());
        $this->assertSame([], $this->created, 'never sent again over an unknown outcome');
    }

    /**
     * ADV-1b (fails): a registration answered 200 WITHOUT Openprovider's envelope (a maintenance page) is taken as done:
     * logged OK, the paid line applied, the order closed and the customer mailed «registered», though nothing was
     * registered. Only the next sync's «not in the account» would show it.
     */
    public function test_a_registration_answered_200_without_the_envelope_is_not_taken_as_done(): void
    {
        $this->mode = 'html';
        $domain = $this->order('add', 'mybusiness.com');
        $this->pay($domain);
        $this->runJobs();

        $this->assertNotSame(DomainRegistrarLog::STATUS_OK, DomainRegistrarLog::query()->where('domain_id', $domain->id)->value('status'),
            'a 200 that is not Openprovider\'s answer is no proof of a registration');
        $this->assertNull(InvoiceItem::query()->where('reference_id', $domain->id)->value('applied_at'));
        $this->assertNotContains('domain_registered', MailFacade::queued(Mail::class)->map(fn (Mail $mail) => $mail->templateKey)->values()->all(),
            'the customer is not told «registered» on a maintenance page');
    }

    private static function ns(string $hosts): array
    {
        $list = preg_split('/\s+/', trim($hosts)) ?: [];

        return collect(range(1, 5))->mapWithKeys(fn (int $n) => ["settings.domain_nameserver_{$n}" => $list[$n - 1] ?? ''])->all();
    }
}
