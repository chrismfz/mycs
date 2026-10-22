<?php

namespace Tests\Feature;

use App\Exceptions\ServerRefused;
use App\Helpers\ExtensionHelper;
use App\Livewire\Services\Show;
use App\Models\DomainRegistrarConnection;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Server;
use App\Models\Service;
use App\Models\SslOrder;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use MyCS\Extensions\Servers\OpenproviderSsl\OpenproviderSsl;
use Tests\TestCase;

/**
 * SSL from Openprovider (owner 2026-10-22, instead of TheSSLStore): paid, the service waits for its CSR; the customer's
 * CSR checked against the product, the order asked for once (marked before, a lost answer recovered, never a second
 * order), the hourly sync reading status, dates, certificate and validation; terminate keeps the certificate unless the
 * product says cancel.
 */
class OpenproviderSslTest extends TestCase
{
    use RefreshDatabase;

    private const CSR = "-----BEGIN CERTIFICATE REQUEST-----\nMIIBfake\n-----END CERTIFICATE REQUEST-----";

    private User $owner;

    private Service $service;

    private DomainRegistrarConnection $connection;

    /** @var array<string, mixed> what the CSR decodes to */
    private array $csr = ['subject' => ['common_name' => 'shop.example.test'], 'subject_alternative_name' => ['www.shop.example.test']];

    private bool $wildcardProduct = false;

    /** POST /ssl/orders: 'ok', 'refuse' (400), 'lost' (no answer, the order made) or 'lost-not-made' */
    private string $orderAnswer = 'ok';

    /** @var array<int, array<string, mixed>> orders at Openprovider */
    private array $orders = [];

    private int $nextId = 777;

    /** POST …/renew: 'same' (the order's end moves), 'new' (a new order), 'refuse' (400), 'lost' (done, no answer), 'lost-not-made' */
    private string $renewAnswer = 'same';

    /** Called once while the CSR is decoded: another request arriving at that moment (code review 1). */
    private ?\Closure $duringDecode = null;

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> method, path, payload */
    private array $calls = [];

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

        return match (true) {
            $path === '/v1/auth/login' => $ok(['token' => 'tok']),
            $path === '/v1/customers' => $ok(['handle' => 'XX123-GR']),
            $path === '/v1/ssl/csr/decode' => tap($ok($this->csr), function () {
                if ($this->duringDecode !== null) {
                    [$run, $this->duringDecode] = [$this->duringDecode, null];
                    $run();
                }
            }),
            $path === '/v1/ssl/products/9' => $ok(['id' => 9, 'is_wildcard_supported' => $this->wildcardProduct]),
            $path === '/v1/ssl/approver-emails' => $ok(['results' => ['admin@shop.example.test', 'webmaster@shop.example.test']]),
            $path === '/v1/ssl/orders' && $request->method() === 'POST' => $this->newOrder($request),
            (bool) preg_match('#^/v1/ssl/orders/(\d+)/renew$#', $path, $m) => $this->renew((int) $m[1]),
            $path === '/v1/ssl/orders' => $ok(['results' => array_values(array_filter($this->orders,
                fn ($o) => $o['common_name'] === ($query['common_name_pattern'] ?? null)))]),
            (bool) preg_match('#^/v1/ssl/orders/(\d+)$#', $path, $m) => isset($this->orders[(int) $m[1]]) ? $ok($this->orders[(int) $m[1]])
                : Http::response(['code' => 404, 'desc' => 'not found'], 404),
            default => $ok(),
        };
    }

    private function newOrder(Request $request)
    {
        if ($this->orderAnswer === 'refuse') {
            return Http::response(['code' => 10001, 'desc' => 'Invalid CSR'], 400);
        }
        if ($this->orderAnswer !== 'lost-not-made') {
            $id = $this->nextId++;
            $this->orders[$id] = ['id' => $id, 'common_name' => 'shop.example.test', 'product_id' => $request['product_id'], 'status' => 'REQ', 'csr' => $request['csr'], 'organization_handle' => $request['organization_handle'],
                'order_date' => now('Europe/Amsterdam')->format('Y-m-d H:i:s'), 'expiration_date' => '', 'order_ends_at' => now()->addYear()->format('Y-m-d H:i:s'),
                'reissue_at' => '', 'certificate' => '', 'intermediate_certificate' => '', 'email_approver' => $request['approver_email'] ?? '',
                'domain_validation_methods' => $request['domain_validation_methods'],
                'additional_data' => [['domain' => 'shop.example.test', 'dns_record' => '_abc.shop.example.test', 'dns_value' => 'abc.def.sectigo.com',
                    'file_location' => 'shop.example.test/.well-known/pki-validation/abc.txt', 'file_contents' => "hash\nsectigo.com"]]];
        }
        if (str_starts_with($this->orderAnswer, 'lost')) {
            throw new ConnectionException('timed out');
        }

        return Http::response(['code' => 0, 'desc' => '', 'data' => ['id' => $id]]);
    }

    private function renew(int $id)
    {
        if ($this->renewAnswer === 'refuse') {
            return Http::response(['code' => 10002, 'desc' => 'Not renewable yet'], 400);
        }
        $ends = now('Europe/Amsterdam')->addYear()->addDays(20)->format('Y-m-d H:i:s');
        $newId = $id;
        if (in_array($this->renewAnswer, ['new', 'lost-new'], true)) {
            $newId = 900;
            $this->orders[900] = array_merge($this->orders[$id], ['id' => 900, 'order_date' => now('Europe/Amsterdam')->format('Y-m-d H:i:s'), 'order_ends_at' => $ends]);
        } elseif ($this->renewAnswer !== 'lost-not-made') {
            $this->orders[$id]['order_ends_at'] = $ends;
        }
        if (str_starts_with($this->renewAnswer, 'lost')) {
            throw new ConnectionException('timed out');
        }

        return Http::response(['code' => 0, 'desc' => '', 'data' => ['id' => $newId]]);
    }

    /** An issued certificate whose paid order ends in 20 days; the service paid until then, or a year beyond. */
    private function issued(bool $paidBeyond): SslOrder
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->place();
        $this->orders[777] = array_merge($this->orders[777], ['status' => 'ACT', 'certificate' => "-----BEGIN CERTIFICATE-----\nAAA\n-----END CERTIFICATE-----",
            'expiration_date' => now('Europe/Amsterdam')->addDays(20)->format('Y-m-d H:i:s'), 'order_ends_at' => now('Europe/Amsterdam')->addDays(20)->format('Y-m-d H:i:s')]);
        $this->service->forceFill(['expires_at' => now()->addDays($paidBeyond ? 385 : 20)])->save();
        $this->paidPeriod(now()->subDays(345), now()->addDays(20));
        if ($paidBeyond) {
            $this->paidPeriod(now()->addDays(20), now()->addDays(385));
        }

        return $this->order();
    }

    /** A paid invoice line for this service's period: what «paid beyond the order's end» is read from. */
    private function paidPeriod(\DateTimeInterface $from, \DateTimeInterface $to): void
    {
        $invoice = Invoice::query()->create(['client_id' => $this->service->client_id, 'currency_code' => 'USD', 'due_at' => now()]);
        $this->service->invoiceItems()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => 10, 'description' => 'SSL',
            'period_start' => $from->format('Y-m-d'), 'period_end' => $to->format('Y-m-d')]);
        Invoice::query()->whereKey($invoice->id)->update(['status' => 'paid']); // as a payment would (the observer allows none created paid)
    }

    /** @return list<array<string, mixed>> the payloads sent to this method and path */
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

    public function test_paid_it_waits_for_its_csr_and_orders_nothing(): void
    {
        ExtensionHelper::createServer($this->service->fresh());

        $this->assertTrue($this->order()->awaitingConfiguration());
        $this->assertSame([], $this->calls, 'nothing asked of Openprovider, nothing spent');
    }

    public function test_the_order_is_placed_once_with_its_validation_and_read_back(): void
    {
        ExtensionHelper::createServer($this->service->fresh());

        $this->assertTrue($this->place());

        [$sent] = $this->sent('POST', '/v1/ssl/orders');
        $this->assertSame([9, 1, 'off', ['shop.example.test'], [['host_name' => 'shop.example.test', 'method' => 'dns']], 'XX123-GR', true],
            [$sent['product_id'], $sent['period'], $sent['autorenew'], $sent['host_names'], $sent['domain_validation_methods'], $sent['organization_handle'], $sent['start_provision']]);
        $order = $this->order();
        $this->assertSame([777, 'REQ', 'shop.example.test', 'dns'], [$order->order_id, $order->status, $order->common_name, $order->validation_method]);
        $this->assertSame('abc.def.sectigo.com', $order->validation['data'][0]['dns_value']);
        $this->assertNotNull($order->order_ends_at);
        $this->assertSame('XX123-GR', $order->organization_handle);

        $this->assertInstanceOf(\Exception::class, rescue(fn () => $this->place(), fn ($e) => $e, false), 'never ordered twice');
        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders'));
    }

    public function test_a_csr_the_product_does_not_cover_is_refused_before_anything_is_ordered(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->csr = ['subject' => ['common_name' => '*.shop.example.test'], 'subject_alternative_name' => []];
        $this->assertFalse(rescue(fn () => $this->place(), false, false), 'a wildcard on a plain product');

        $this->csr = ['subject' => ['common_name' => 'shop.example.test'], 'subject_alternative_name' => ['other.example.test']];
        $this->assertFalse(rescue(fn () => $this->place(), false, false), 'another name');

        $this->csr = ['subject' => ['common_name' => 'not a name'], 'subject_alternative_name' => []];
        $this->assertFalse(rescue(fn () => $this->place(), false, false), 'no name');

        $this->assertSame([], $this->sent('POST', '/v1/ssl/orders'));
        $this->assertTrue($this->order()->fresh()->awaitingConfiguration());
    }

    public function test_email_validation_takes_only_an_approver_openprovider_allows(): void
    {
        ExtensionHelper::createServer($this->service->fresh());

        $this->assertFalse(rescue(fn () => $this->place('email', 'me@elsewhere.test'), false, false));
        $this->assertSame([], $this->sent('POST', '/v1/ssl/orders'));

        $this->assertTrue($this->place('email', 'Admin@shop.example.test'));
        $this->assertSame('admin@shop.example.test', $this->sent('POST', '/v1/ssl/orders')[0]['approver_email']);
    }

    public function test_a_refusal_takes_the_mark_off_and_it_may_be_tried_again(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->orderAnswer = 'refuse';

        $e = rescue(fn () => $this->place(), fn ($e) => $e, false);
        $this->assertInstanceOf(ServerRefused::class, $e);
        $this->assertStringContainsString('Invalid CSR', $e->getMessage());
        $this->assertTrue($this->order()->awaitingConfiguration());

        $this->orderAnswer = 'ok';
        $this->assertTrue($this->place());
    }

    public function test_a_lost_answer_is_recovered_never_ordered_twice(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->orderAnswer = 'lost';

        $this->assertFalse(rescue(fn () => $this->place(), false, false));
        $this->assertNull($this->order()->order_id);
        $this->assertNotNull($this->order()->requested_at, 'the mark stays: it may have been ordered');

        $this->orderAnswer = 'ok';
        $this->assertTrue($this->place(), 'the order made is adopted');
        $this->assertSame(777, $this->order()->order_id);
        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders'));
    }

    public function test_a_lost_answer_not_found_is_never_ordered_again_by_itself(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->orderAnswer = 'lost-not-made';
        rescue(fn () => $this->place(), null, false);

        $this->orderAnswer = 'ok';
        $this->assertFalse(rescue(fn () => $this->place(), false, false));
        $this->artisan('ssl:sync')->assertExitCode(0);

        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders'));
        $this->assertNull($this->order()->order_id);
    }

    public function test_a_lost_answer_never_adopts_an_order_of_another_csr(): void
    {
        // Openprovider's dates may be in another timezone: an earlier order of the same name looks new. Its CSR tells.
        ExtensionHelper::createServer($this->service->fresh());
        $this->orders[500] = ['id' => 500, 'common_name' => 'shop.example.test', 'product_id' => 9, 'organization_handle' => 'XX123-GR', 'order_date' => now('Europe/Amsterdam')->addHours(2)->format('Y-m-d H:i:s'),
            'csr' => "-----BEGIN CERTIFICATE REQUEST-----\nOTHER\n-----END CERTIFICATE REQUEST-----"];
        $this->order()->forceFill(['common_name' => 'shop.example.test', 'csr' => self::CSR, 'organization_handle' => 'XX123-GR', 'requested_at' => now()])->save();

        $this->artisan('ssl:sync')->assertExitCode(0);
        $this->assertNull($this->order()->order_id);

        $this->orders[500]['csr'] = str_replace("\n", "\r\n", self::CSR); // the same CSR, other line endings
        $this->artisan('ssl:sync')->assertExitCode(0);
        $this->assertSame(500, $this->order()->order_id);
    }

    public function test_an_order_of_another_service_is_never_adopted(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $other = $this->service->replicate();
        $other->save();
        SslOrder::query()->create(['service_id' => $other->id, 'connection_id' => $this->connection->id, 'product_id' => 9, 'order_id' => 777, 'common_name' => 'shop.example.test']);
        $this->orders[777] = ['id' => 777, 'common_name' => 'shop.example.test', 'product_id' => 9, 'order_date' => now('Europe/Amsterdam')->format('Y-m-d H:i:s'), 'csr' => self::CSR];
        $this->order()->forceFill(['common_name' => 'shop.example.test', 'csr' => self::CSR, 'requested_at' => now()])->save();

        $this->artisan('ssl:sync')->assertExitCode(0);

        $this->assertNull($this->order()->order_id);
    }

    public function test_the_sync_reads_the_issued_certificate_and_keeps_a_failure(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->place();
        $this->orders[777] = array_merge($this->orders[777], ['status' => 'ACT', 'certificate' => "-----BEGIN CERTIFICATE-----\nAAA\n-----END CERTIFICATE-----",
            'intermediate_certificate' => "-----BEGIN CERTIFICATE-----\nBBB\n-----END CERTIFICATE-----", 'expiration_date' => '2027-05-01 10:00:00', 'reissue_at' => '2027-04-15 10:00:00']);

        $this->artisan('ssl:sync')->assertExitCode(0);

        $order = $this->order();
        $this->assertTrue($order->issued());
        $this->assertSame(['ACT', '2027-05-01', '2027-04-15'], [$order->status, $order->expires_at->toDateString(), $order->reissue_at->toDateString()]);

        unset($this->orders[777]);
        $this->artisan('ssl:sync')->assertExitCode(1);
        $this->assertNotNull($this->order()->sync_error);
        $this->assertTrue($this->order()->issued(), 'what was read is kept');
    }

    public function test_terminate_keeps_the_certificate_unless_the_product_says_cancel(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->place();

        ExtensionHelper::terminateServer($this->service->fresh());
        $this->assertSame([], $this->sent('POST', '/v1/ssl/orders/777/cancel'));

        $this->service->forceFill(['status' => 'active'])->save();
        $this->service->product->settings()->create(['key' => 'terminate_mode', 'value' => OpenproviderSsl::CANCEL]);
        ExtensionHelper::terminateServer($this->service->fresh());
        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders/777/cancel'));
    }

    public function test_one_customer_handle_per_account(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->place();
        $second = $this->service->replicate();
        $second->save();
        ExtensionHelper::createServer($second->fresh());

        ExtensionHelper::callService($second->fresh(), 'placeOrder', [self::CSR, 'dns', null]);

        $this->assertCount(1, $this->sent('POST', '/v1/customers'));
        $this->assertSame('XX123-GR', SslOrder::query()->where('service_id', $second->id)->value('organization_handle'));
    }

    public function test_two_orders_at_once_order_once(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $second = null;
        $this->duringDecode = function () use (&$second) {
            $second = rescue(fn () => $this->place(), fn ($e) => $e, false);
        };

        $first = rescue(fn () => $this->place(), fn ($e) => $e, false);

        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders'), 'charged once');
        $this->assertTrue($first === true xor $second === true, 'one of the two ordered');
        $this->assertSame(777, $this->order()->order_id);
    }

    public function test_a_lost_answer_not_found_is_told_and_staff_may_clear_it(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->orderAnswer = 'lost-not-made';
        rescue(fn () => $this->place(), null, false);

        $this->travel(31)->minutes();
        $this->artisan('ssl:sync')->assertExitCode(1);
        $this->assertStringContainsString('no such order is found at Openprovider', (string) $this->order()->sync_error);

        $server = $this->service->provisioningServer();
        ExtensionHelper::getExtension('server', $server->extension, $server->settings)->clearRequest($this->order());
        $this->assertTrue($this->order()->awaitingConfiguration());
        $this->orderAnswer = 'ok';
        $this->assertTrue($this->place());
        $this->assertCount(2, $this->sent('POST', '/v1/ssl/orders'));
    }

    public function test_terminate_with_cancel_finds_a_lost_answers_order_first(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->orderAnswer = 'lost';
        rescue(fn () => $this->place(), null, false);
        $this->service->product->settings()->create(['key' => 'terminate_mode', 'value' => OpenproviderSsl::CANCEL]);

        ExtensionHelper::terminateServer($this->service->fresh());

        $this->assertSame(777, $this->order()->order_id);
        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders/777/cancel'));
    }

    public function test_an_order_is_read_on_its_own_connection(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->place();
        $other = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP2', 'is_active' => false, 'mode' => 'production']);
        $this->service->provisioningServer()->settings()->where('key', 'connection_id')->update(['value' => (string) $other->id]);
        $this->orders[777]['status'] = 'ACT';

        $this->artisan('ssl:sync')->assertExitCode(0);

        $this->assertSame('ACT', $this->order()->status);
    }

    public function test_a_contact_with_no_country_is_told_as_a_refusal(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->owner->client()->primaryContact->forceFill(['country' => ''])->save();

        $e = rescue(fn () => $this->place(), fn ($e) => $e, false);

        $this->assertInstanceOf(ServerRefused::class, $e);
        $this->assertTrue($this->order()->awaitingConfiguration());
    }

    public function test_openproviders_dates_are_amsterdams(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->place();
        $this->orders[777]['expiration_date'] = '2027-05-01 00:30:00';

        $this->artisan('ssl:sync')->assertExitCode(0);

        $this->assertSame('2027-04-30 22:30:00', $this->order()->expires_at->setTimezone('UTC')->format('Y-m-d H:i:s'));
    }

    public function test_renewal_only_once_paid_beyond_the_order_and_only_once(): void
    {
        $this->issued(paidBeyond: false);
        $this->artisan('ssl:sync')->assertExitCode(0);
        $this->assertSame([], $this->sent('POST', '/v1/ssl/orders/777/renew'), 'not paid beyond the order: never renewed');

        $this->service->forceFill(['expires_at' => now()->addDays(385)])->save();
        $this->artisan('ssl:sync')->assertExitCode(0);
        $this->assertSame([], $this->sent('POST', '/v1/ssl/orders/777/renew'), 'a date moved, nothing paid: never renewed');

        $this->paidPeriod(now()->addDays(20), now()->addDays(385));
        $this->artisan('ssl:sync')->assertExitCode(0);
        $this->artisan('ssl:sync')->assertExitCode(0);

        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders/777/renew'), 'charged once');
        $order = $this->order();
        $this->assertNull($order->renew_requested_at, 'seen: the order\'s end moved');
        $this->assertTrue($order->order_ends_at->greaterThan(now()->addYear()));
    }

    public function test_a_renewal_far_from_the_orders_end_or_of_a_suspended_service_waits(): void
    {
        $this->issued(paidBeyond: true);
        $this->orders[777]['order_ends_at'] = now('Europe/Amsterdam')->addDays(60)->format('Y-m-d H:i:s');
        $this->artisan('ssl:sync')->assertExitCode(0);
        $this->orders[777]['order_ends_at'] = now('Europe/Amsterdam')->addDays(20)->format('Y-m-d H:i:s');
        $this->service->forceFill(['status' => 'suspended'])->save();
        $this->artisan('ssl:sync')->assertExitCode(0);

        $this->assertSame([], $this->sent('POST', '/v1/ssl/orders/777/renew'));
    }

    public function test_a_lost_renewal_answer_is_seen_never_renewed_twice(): void
    {
        $this->issued(paidBeyond: true);
        $this->renewAnswer = 'lost';
        $this->artisan('ssl:sync')->assertExitCode(1);
        $this->assertNotNull($this->order()->renew_requested_at);

        $this->renewAnswer = 'same';
        $this->artisan('ssl:sync')->assertExitCode(0);

        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders/777/renew'));
        $this->assertNull($this->order()->renew_requested_at);
    }

    public function test_a_renewal_that_makes_a_new_order_moves_the_service_to_it(): void
    {
        $this->issued(paidBeyond: true);
        $this->renewAnswer = 'new';

        $this->artisan('ssl:sync')->assertExitCode(0);

        $order = $this->order();
        $this->assertSame([900, [777]], [$order->order_id, $order->previous_order_ids]);
        $this->assertNull($order->renew_requested_at);
    }

    public function test_a_lost_renewal_that_made_a_new_order_is_found_by_its_csr(): void
    {
        $this->issued(paidBeyond: true);
        $this->renewAnswer = 'lost-new'; // a new order made, the answer lost
        $this->artisan('ssl:sync')->assertExitCode(1); // told

        $this->artisan('ssl:sync')->assertExitCode(0); // found

        $this->assertSame(900, $this->order()->order_id, 'the new order adopted by its CSR and handle');
        $this->assertNull($this->order()->renew_requested_at);
        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders/777/renew'));
    }

    public function test_a_refused_renewal_is_told_and_tried_again(): void
    {
        $this->issued(paidBeyond: true);
        $this->renewAnswer = 'refuse';
        $this->artisan('ssl:sync')->assertExitCode(1);
        $this->assertStringContainsString('Not renewable yet', (string) $this->order()->sync_error);
        $this->assertNull($this->order()->renew_requested_at);

        $this->renewAnswer = 'same';
        $this->artisan('ssl:sync')->assertExitCode(0);
        $this->assertCount(2, $this->sent('POST', '/v1/ssl/orders/777/renew'));
    }

    public function test_a_renewal_not_seen_after_a_day_is_told_and_staff_may_clear_it(): void
    {
        $this->issued(paidBeyond: true);
        $this->renewAnswer = 'lost-not-made';
        $this->artisan('ssl:sync')->assertExitCode(1);
        $this->travel(25)->hours();
        $this->artisan('ssl:sync')->assertExitCode(1);
        $this->assertStringContainsString('does not show it after a day', (string) $this->order()->sync_error);

        $server = $this->service->provisioningServer();
        ExtensionHelper::getExtension('server', $server->extension, $server->settings)->clearRequest($this->order());
        $this->assertNull($this->order()->renew_requested_at);
        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders/777/renew'), 'clearing charges nothing');
    }

    public function test_the_customer_is_told_of_a_new_certificate_once(): void
    {
        $this->issued(paidBeyond: false);
        $this->artisan('ssl:sync')->assertExitCode(0);
        $this->artisan('ssl:sync')->assertExitCode(0);
        $this->assertSame(1, EmailLog::query()->where('template_key', 'ssl_certificate_issued')->count());

        $this->orders[777]['certificate'] = "-----BEGIN CERTIFICATE-----\nBBB\n-----END CERTIFICATE-----"; // reissued
        $this->artisan('ssl:sync')->assertExitCode(0);
        $this->assertSame(2, EmailLog::query()->where('template_key', 'ssl_certificate_issued')->count());
    }

    public function test_reissue_takes_a_new_csr_for_the_same_name_only(): void
    {
        $this->issued(paidBeyond: false);
        $this->artisan('ssl:sync')->assertExitCode(0);
        $reissue = fn () => ExtensionHelper::callService($this->service->fresh(), 'reissue', [self::CSR, 'https', null]);

        $this->assertTrue($reissue());
        [$sent] = $this->sent('POST', '/v1/ssl/orders/777/reissue');
        $this->assertSame(['shop.example.test'], $sent['host_names']);
        $this->assertSame('https', $sent['domain_validation_methods'][0]['method']);

        $this->csr = ['subject' => ['common_name' => 'other.example.test'], 'subject_alternative_name' => []];
        $this->assertFalse(rescue($reissue, false, false), 'another name: a new certificate, not a reissue');
        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders/777/reissue'));
    }

    public function test_the_approver_changes_only_to_one_openprovider_allows(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        $this->place('email', 'admin@shop.example.test');
        $change = fn (string $to) => ExtensionHelper::callService($this->service->fresh(), 'changeApproverEmail', [$to]);

        $this->assertFalse(rescue(fn () => $change('me@elsewhere.test'), false, false));
        $this->assertTrue($change('webmaster@shop.example.test'));
        $this->assertSame([['id' => 777, 'approver_email' => 'webmaster@shop.example.test']], $this->sent('PUT', '/v1/ssl/orders/777/approver-email'));
    }

    public function test_the_customer_reissues_from_the_page(): void
    {
        $this->issued(paidBeyond: false);
        $this->artisan('ssl:sync')->assertExitCode(0);
        RateLimiter::clear('service-ssl-check:' . $this->service->id);
        RateLimiter::clear('service-ssl-reissue:' . $this->service->id);
        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, [CurrentClient::SESSION_KEY => $this->service->client_id]));

        Livewire::test(Show::class, ['service' => $this->service])->assertSee(__('services.ssl.reissue_title'))
            ->set('sslCsr', self::CSR)->call('sslCheck')->assertSet('sslName', 'shop.example.test')
            ->set('sslMethod', 'dns')->call('sslReissue');

        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders/777/reissue'));
        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders'), 'nothing ordered again');
    }

    public function test_staff_start_a_new_order_only_for_an_ended_one(): void
    {
        $this->issued(paidBeyond: false);
        $this->artisan('ssl:sync')->assertExitCode(0);
        $server = $this->service->provisioningServer();
        $extension = ExtensionHelper::getExtension('server', $server->extension, $server->settings);

        $this->assertFalse(rescue(fn () => $extension->startOver($this->order()) ?? true, false, false), 'a live order never starts over');

        $this->orders[777]['status'] = 'EXP';
        $this->artisan('ssl:sync')->assertExitCode(0);
        $extension->startOver($this->order());

        $order = $this->order();
        $this->assertTrue($order->awaitingConfiguration());
        $this->assertSame([777], $order->previous_order_ids);
        $this->assertNull($order->certificate);
    }

    public function test_a_plan_not_in_years_is_refused(): void
    {
        $this->service->plan->update(['billing_unit' => 'month', 'billing_period' => 1]);

        $this->assertFalse(rescue(fn () => ExtensionHelper::createServer($this->service->fresh()), false, false));
        $this->assertSame(0, SslOrder::query()->count());
    }

    public function test_the_customer_checks_the_csr_then_orders_and_sees_the_certificate(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        RateLimiter::clear('service-ssl-check:' . $this->service->id);
        RateLimiter::clear('service-ssl-order:' . $this->service->id);
        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, [CurrentClient::SESSION_KEY => $this->service->client_id]));

        $page = Livewire::test(Show::class, ['service' => $this->service])->assertSee(__('services.ssl.check'));
        $page->set('sslCsr', self::CSR)->call('sslCheck')->assertSet('sslName', 'shop.example.test')
            ->assertSet('sslApprovers', ['admin@shop.example.test', 'webmaster@shop.example.test']);
        $this->assertSame([], $this->sent('POST', '/v1/ssl/orders'), 'checking orders nothing');
        $page->set('sslMethod', 'https')->call('sslOrder')->assertDispatched('notify');
        $this->assertSame(777, $this->order()->order_id);

        $page = Livewire::test(Show::class, ['service' => $this->service]);
        $page->assertSee('shop.example.test/.well-known/pki-validation/abc.txt');

        $this->order()->forceFill(['certificate' => "-----BEGIN CERTIFICATE-----\nAAA\n-----END CERTIFICATE-----"])->save();
        Livewire::test(Show::class, ['service' => $this->service])->assertSee('-----BEGIN CERTIFICATE-----');
    }

    public function test_the_page_never_orders_with_a_name_it_set_itself_or_an_approver_not_offered(): void
    {
        ExtensionHelper::createServer($this->service->fresh());
        RateLimiter::clear('service-ssl-check:' . $this->service->id);
        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, [CurrentClient::SESSION_KEY => $this->service->client_id]));
        $page = Livewire::test(Show::class, ['service' => $this->service]);

        $this->assertInstanceOf(\Throwable::class, rescue(fn () => $page->set('sslName', 'forged.test'), fn ($e) => $e, false), 'the checked name is locked');
        $page->set('sslCsr', self::CSR)->call('sslCheck')->set('sslMethod', 'email')->set('sslApprover', 'me@elsewhere.test')->call('sslOrder')
            ->assertHasErrors('sslApprover');
        $this->assertSame([], $this->sent('POST', '/v1/ssl/orders'));
    }
}
