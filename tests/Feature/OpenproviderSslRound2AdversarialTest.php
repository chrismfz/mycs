<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\DomainRegistrarConnection;
use App\Models\Server;
use App\Models\Service;
use App\Models\SslOrder;
use App\Models\User;
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
 * Second-round adversarial review of #345 (Openprovider SSL), against its fix 3721494d. Each test is one finding and
 * fails on 3721494d.
 *
 * R2-1 (medium, money): the customer handle is saved on the order before the claim, unconditionally, from a model read
 *   before it. A second request (another tab) making its own handle while the first one orders overwrites the handle the
 *   first order was made with. Recovery now requires that handle (ADV-5's fix), so a lost answer is never adopted: the
 *   paid order stays unknown, the request is «stuck» and staff's only tool is to clear it, after which the customer is
 *   charged a second certificate.
 * R2-2 (medium, money): clearing an unanswered order request does not look at Openprovider first, and is offered from
 *   the moment of the request (even while it is in flight). A lost answer whose order IS at Openprovider is cleared, and
 *   the customer's next order is a second charge; recover() could have adopted it in the same click.
 * R2-3 (low, money): ADV-8's fix is incomplete. Terminate with «cancel» right after a lost answer, before Openprovider's
 *   list shows the order, returns success and cancels nothing; the service is terminated and ssl:sync never looks at a
 *   terminated service, so the order made is never cancelled. It should fail (and be retried) while the request is open.
 */
class OpenproviderSslRound2AdversarialTest extends TestCase
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

    /** Run once while Openprovider makes a customer (another request arriving meanwhile). */
    private ?Closure $onCustomer = null;

    /** Openprovider's order list not showing an order made a moment ago yet. */
    private bool $listLags = false;

    private int $handles = 0;

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
        if ($path === '/v1/customers' && $this->onCustomer !== null) {
            $run = $this->onCustomer;
            $this->onCustomer = null;
            $run();
        }

        return match (true) {
            $path === '/v1/auth/login' => $ok(['token' => 'tok']),
            $path === '/v1/customers' => $ok(['handle' => 'XX' . (++$this->handles) . '-GR']),
            $path === '/v1/ssl/csr/decode' => $ok($this->csr),
            (bool) preg_match('#^/v1/ssl/products/(\d+)$#', $path, $p) => $ok(['id' => (int) $p[1], 'is_wildcard_supported' => false]),
            $path === '/v1/ssl/approver-emails' => $ok(['results' => ['admin@shop.example.test']]),
            $path === '/v1/ssl/orders' && $request->method() === 'POST' => $this->newOrder($request),
            $path === '/v1/ssl/orders' => $ok(['results' => $this->listLags ? [] : array_values(array_filter($this->orders,
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

    /** R2-1: a second request making its own customer handle never replaces the handle the first order was made with. */
    public function test_r2_1_a_parallel_request_does_not_overwrite_the_handle_of_the_order_made(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->orderAnswer = 'lost'; // the order is made at Openprovider, its answer lost
        // While the first request makes the customer's handle, a second one (another tab) runs through and orders.
        $this->onCustomer = fn () => rescue(fn () => $this->place(), null, false);

        rescue(fn () => $this->place(), null, false);
        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders'));
        $made = $this->orders[777]['organization_handle'];

        $this->artisan('ssl:sync')->run();

        $this->assertSame($made, $this->order()->organization_handle, 'the handle of the order made was overwritten by the request that was refused');
        $this->assertSame(777, $this->order()->order_id, 'the paid order is never recovered: its handle no longer matches');
    }

    /** R2-2: clearing a request whose order is at Openprovider never lets the customer be charged a second certificate. */
    public function test_r2_2_clearing_a_request_whose_order_exists_does_not_allow_a_second_charge(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->orderAnswer = 'lost';
        rescue(fn () => $this->place(), null, false);
        $this->assertNull($this->order()->order_id);
        $this->assertArrayHasKey(777, $this->orders); // made and charged at Openprovider

        // Five minutes later the customer asks why it is pending; staff clear it (the action is offered already).
        $this->travel(5)->minutes();
        $server = $this->service->fresh()->provisioningServer();
        rescue(fn () => ExtensionHelper::getExtension('server', $server->extension, $server->settings)->clearRequest($this->order()), null, false);
        $this->orderAnswer = 'ok';
        rescue(fn () => $this->place(), null, false);

        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders'), 'cleared without looking at Openprovider: a second certificate charged');
    }

    /** R2-3: terminate with «cancel» while a lost answer is not found yet fails (to be retried), never succeeds silently. */
    public function test_r2_3_terminate_does_not_succeed_while_a_lost_answer_is_unresolved(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->service->product->settings()->create(['key' => 'terminate_mode', 'value' => OpenproviderSsl::CANCEL]);
        $this->orderAnswer = 'lost';
        rescue(fn () => $this->place(), null, false);
        $this->listLags = true; // made a moment ago: not in Openprovider's list yet

        $failed = rescue(fn () => ExtensionHelper::terminateServer($this->service->fresh()), fn (Throwable $e) => $e, false);
        $this->listLags = false;
        if ($failed instanceof Throwable) {
            ExtensionHelper::terminateServer($this->service->fresh()); // the module action retried
        }

        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders/777/cancel'), 'terminated as done: the order made is never cancelled');
    }
}
