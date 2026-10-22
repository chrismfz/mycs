<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\DomainRegistrarConnection;
use App\Models\Server;
use App\Models\Service;
use App\Models\SslOrder;
use App\Models\User;
use App\Services\Domains\RegistrarNotAsked;
use App\Services\Domains\Registrars\OpenproviderRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Servers\OpenproviderSsl\OpenproviderSsl;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #345 (Openprovider SSL, step 1). Each test is one finding and fails on the PR's code.
 *
 * ADV-1 (high, money): two order requests for one service in parallel (a double click on two tabs, a retried POST)
 *   both pass the «not ordered yet» check, both mark and both order: two certificates charged, the first one orphaned.
 * ADV-2 (medium, money): the order uses the product's Openprovider product id of the moment it is placed, not the one
 *   recorded when the service was paid (createServer): staff changing the product setting changes what paid services buy.
 * ADV-3 (medium, money): the period ordered is the service's plan of the moment (a cycle change to 3 years, its invoice
 *   not paid), not the period paid when the service was created.
 * ADV-4 (medium): a lost answer whose order is not found stays silent forever: ssl:sync exits 0 and keeps no sync_error,
 *   so nobody is told that a customer who paid waits on a certificate that will never come.
 * ADV-5 (low, money): recovering a lost answer adopts an order made under another account's organization handle (the same
 *   CSR reused across accounts, e.g. by an agency), as long as no service here has it yet.
 * ADV-6 (low): a CSR whose name ends in a dot is accepted and ordered as «name.»; Openprovider's order is «name», which
 *   recover() then never matches.
 * ADV-7 (low): sslCall()'s guard is a prefix check: «/v1/ssl/../customers» passes it and reaches another API.
 * ADV-8 (low, money): terminate with «cancel» while an order request's answer was lost cancels nothing: the order made
 *   stays at Openprovider (and ssl:sync never looks at a terminated service to adopt it).
 */
class OpenproviderSslAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const CSR = "-----BEGIN CERTIFICATE REQUEST-----\nMIIBfake\n-----END CERTIFICATE REQUEST-----";

    private User $owner;

    private Service $service;

    private DomainRegistrarConnection $connection;

    /** @var array<string, mixed> */
    private array $csr = ['subject' => ['common_name' => 'shop.example.test'], 'subject_alternative_name' => ['www.shop.example.test']];

    private string $orderAnswer = 'ok';

    /** @var array<int, array<string, mixed>> */
    private array $orders = [];

    private int $nextId = 777;

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private array $calls = [];

    /** Run once while Openprovider decodes the CSR (another request arriving meanwhile). */
    private ?Closure $onDecode = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['settings.default_currency' => 'USD']);
        $this->connection = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->connection->storeCredentials(['username' => 'op-user', 'password' => 'op-secret'], ['password']);
        $server = Server::query()->create(['name' => 'SSL', 'extension' => 'OpenproviderSsl', 'type' => 'server', 'enabled' => true]);
        $server->settings()->create(['key' => 'connection_id', 'value' => (string) $this->connection->id]);
        $this->owner = User::factory()->create();
        $this->owner->client()->ensurePrimaryContact()->forceFill(['country' => 'GR', 'address1' => 'Odos 12', 'city' => 'Athens', 'postcode' => '10000', 'phone' => '+30.2100000000'])->save();
        $product = $this->createProduct();
        $product->plan->update(['billing_unit' => 'year', 'billing_period' => 1]);
        $product->product->update(['server_id' => $server->id]);
        $product->product->settings()->create(['key' => 'product_id', 'value' => '9']);
        $this->service = Service::factory()->create(['client_id' => $this->owner->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'server_id' => $server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'expires_at' => now()->addYear()]);
        Http::fake(fn (Request $request) => $this->openprovider($request));
    }

    private function openprovider(Request $request)
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $this->calls[] = [$request->method(), $path, $request->data() ?: $query];
        $ok = fn (array $data = []) => Http::response(['code' => 0, 'desc' => '', 'data' => $data]);
        if ($path === '/v1/ssl/csr/decode' && $this->onDecode !== null) {
            $run = $this->onDecode;
            $this->onDecode = null;
            $run();
        }

        return match (true) {
            $path === '/v1/auth/login' => $ok(['token' => 'tok']),
            $path === '/v1/customers' => $ok(['handle' => 'XX123-GR']),
            $path === '/v1/ssl/csr/decode' => $ok($this->csr),
            (bool) preg_match('#^/v1/ssl/products/(\d+)$#', $path, $p) => $ok(['id' => (int) $p[1], 'is_wildcard_supported' => false]),
            $path === '/v1/ssl/approver-emails' => $ok(['results' => ['admin@shop.example.test']]),
            $path === '/v1/ssl/orders' && $request->method() === 'POST' => $this->newOrder($request),
            $path === '/v1/ssl/orders' => $ok(['results' => array_values(array_filter($this->orders,
                fn ($o) => $o['common_name'] === ($query['common_name_pattern'] ?? null)))]),
            (bool) preg_match('#^/v1/ssl/orders/(\d+)$#', $path, $m) => isset($this->orders[(int) $m[1]]) ? $ok($this->orders[(int) $m[1]])
                : Http::response(['code' => 404, 'desc' => 'not found'], 404),
            default => $ok(),
        };
    }

    private function newOrder(Request $request)
    {
        $id = null;
        if ($this->orderAnswer !== 'lost-not-made') {
            $id = $this->nextId++;
            $this->orders[$id] = ['id' => $id, 'common_name' => rtrim((string) ($request['host_names'][0] ?? 'shop.example.test'), '.'), 'product_id' => $request['product_id'],
                'status' => 'REQ', 'csr' => $request['csr'], 'organization_handle' => $request['organization_handle'] ?? null, 'period' => $request['period'],
                'order_date' => now('Europe/Amsterdam')->format('Y-m-d H:i:s'), 'expiration_date' => '', 'order_ends_at' => '', 'reissue_at' => '', 'certificate' => '',
                'intermediate_certificate' => '', 'email_approver' => '', 'domain_validation_methods' => $request['domain_validation_methods'], 'additional_data' => []];
        }
        if (str_starts_with($this->orderAnswer, 'lost')) {
            throw new ConnectionException('timed out');
        }

        return Http::response(['code' => 0, 'desc' => '', 'data' => ['id' => $id]]);
    }

    /** @return list<array<string, mixed>> */
    private function sent(string $method, string $path): array
    {
        return array_values(array_map(fn ($c) => $c[2], array_filter($this->calls, fn ($c) => $c[0] === $method && $c[1] === $path)));
    }

    private function order(): SslOrder
    {
        return SslOrder::query()->where('service_id', $this->service->id)->sole();
    }

    private function place(string $method = 'dns', ?string $approver = null): mixed
    {
        return ExtensionHelper::callService($this->service->fresh(), 'placeOrder', [self::CSR, $method, $approver]);
    }

    /** ADV-1: a second request arrives while the first one is checking the CSR: one certificate is ordered, never two. */
    public function test_adv1_two_parallel_order_requests_order_one_certificate(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->onDecode = fn () => rescue(fn () => $this->place(), null, false);

        rescue(fn () => $this->place(), null, false);

        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders'), 'two requests in parallel: two certificates charged at Openprovider');
        $this->assertSame(777, $this->order()->order_id, 'the first order is orphaned');
    }

    /** ADV-2: what the customer paid for (the product id when the service was created) is what is ordered. */
    public function test_adv2_the_product_ordered_is_the_one_paid_for(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->assertSame(9, $this->order()->product_id);
        $this->service->product->settings()->where('key', 'product_id')->update(['value' => '10']); // staff move the product to a dearer certificate

        $this->place();

        $this->assertSame(9, $this->sent('POST', '/v1/ssl/orders')[0]['product_id'] ?? null, 'a paid service orders another product than the one paid');
    }

    /** ADV-3: the period ordered is the period paid, not the plan the service has moved to since (its invoice unpaid). */
    public function test_adv3_the_period_ordered_is_the_one_paid(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $threeYears = $this->service->plan->replicate();
        $threeYears->fill(['billing_unit' => 'year', 'billing_period' => 3])->save();
        $this->service->forceFill(['plan_id' => $threeYears->id])->save(); // ChangeCycle «now»: a new 3-year period billed, not paid yet

        $this->place();

        $this->assertSame(1, $this->sent('POST', '/v1/ssl/orders')[0]['period'] ?? null, 'three years ordered with one paid');
    }

    /** ADV-4: an order request lost and not found after a while is told (sync_error, a failing exit), not kept silent. */
    public function test_adv4_a_lost_order_never_found_is_told_to_staff(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->order()->forceFill(['common_name' => 'shop.example.test', 'csr' => self::CSR, 'requested_at' => now()->subHours(6)])->save();

        $exit = $this->artisan('ssl:sync')->run();

        $this->assertNotNull($this->order()->sync_error, 'six hours later, nothing found and nobody told');
        $this->assertSame(1, $exit);
    }

    /** ADV-5: a lost answer adopts only an order made for this account (its organization handle). */
    public function test_adv5_a_lost_answer_never_adopts_another_accounts_order(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->orders[600] = ['id' => 600, 'common_name' => 'shop.example.test', 'product_id' => 9, 'order_date' => now('Europe/Amsterdam')->format('Y-m-d H:i:s'),
            'csr' => self::CSR, 'organization_handle' => 'YY999-GR'];
        $this->order()->forceFill(['common_name' => 'shop.example.test', 'csr' => self::CSR, 'organization_handle' => 'XX123-GR', 'requested_at' => now()])->save();

        $this->artisan('ssl:sync')->run();

        $this->assertNull($this->order()->order_id, "another account's certificate adopted");
    }

    /** ADV-6: a name with a trailing dot is refused (or normalised) before anything is ordered. */
    public function test_adv6_a_trailing_dot_name_is_not_ordered_as_it_is(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->csr = ['subject' => ['common_name' => 'shop.example.test.'], 'subject_alternative_name' => []];

        rescue(fn () => $this->place(), null, false);

        foreach ($this->sent('POST', '/v1/ssl/orders') as $sent) {
            $this->assertSame(['shop.example.test'], $sent['host_names'], 'ordered as «shop.example.test.»');
        }
        $this->assertNotSame('shop.example.test.', $this->order()->common_name);
    }

    /** ADV-7: only Openprovider's SSL API is reached through sslCall(), whatever dot segments the path holds. */
    public function test_adv7_ssl_call_refuses_a_path_leaving_the_ssl_api(): void
    {
        $e = rescue(fn () => app(OpenproviderRegistrar::class)->sslCall(DomainRegistrarCredentials::fromConnection($this->connection), 'GET', '/v1/ssl/../customers'),
            fn (Throwable $e) => $e, false);

        $this->assertInstanceOf(RegistrarNotAsked::class, $e, 'the prefix guard is passed by «/v1/ssl/../»');
    }

    /** ADV-8: terminate with «cancel» after a lost answer cancels the order that was made. */
    public function test_adv8_terminate_cancels_an_order_whose_answer_was_lost(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->service->product->settings()->create(['key' => 'terminate_mode', 'value' => OpenproviderSsl::CANCEL]);
        $this->orderAnswer = 'lost';
        rescue(fn () => $this->place(), null, false);
        $this->assertNull($this->order()->order_id);

        ExtensionHelper::terminateServer($this->service->fresh());

        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders/777/cancel'), 'the certificate made stays at Openprovider, never cancelled');
    }
}
