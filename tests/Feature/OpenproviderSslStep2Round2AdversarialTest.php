<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\DomainRegistrarConnection;
use App\Models\Invoice;
use App\Models\Server;
use App\Models\Service;
use App\Models\SslOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Servers\OpenproviderSsl\OpenproviderSsl;
use Tests\TestCase;

/**
 * Second-round adversarial review of SSL step 2 (#350), against the fix commit e0b431fc. Same Openprovider fake as
 * OpenproviderSslStep2AdversarialTest. Each R2-n fails on e0b431fc.
 */
class OpenproviderSslStep2Round2AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const CSR = "-----BEGIN CERTIFICATE REQUEST-----\nMIIBfake\n-----END CERTIFICATE REQUEST-----";

    private const CSR2 = "-----BEGIN CERTIFICATE REQUEST-----\nMIIBnewkey\n-----END CERTIFICATE REQUEST-----";

    private User $owner;

    private Service $service;

    private Server $server;

    private DomainRegistrarConnection $connection;

    /** @var array<string, mixed> */
    private array $csr = ['subject' => ['common_name' => 'shop.example.test'], 'subject_alternative_name' => ['www.shop.example.test']];

    /** POST /ssl/orders: 'ok', 'lost' (made, no answer) */
    private string $orderAnswer = 'ok';

    /** @var array<int, array<string, mixed>> */
    private array $orders = [];

    private int $nextId = 777;

    /**
     * POST …/renew: 'same', 'new', 'lost-new' (new order made, answer lost), 'lost-not-made', 'new-then-408' (a new order
     * made, then a proxy's 4xx), 'answered-not-moved' (2xx with the same id, the end not moved yet)
     */
    private string $renewAnswer = 'same';

    /** GET /v1/ssl/orders (the list) answers 500 */
    private bool $listDown = false;

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
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
        $this->server = Server::query()->create(['name' => 'SSL', 'extension' => 'OpenproviderSsl', 'type' => 'server', 'enabled' => true]);
        $this->server->settings()->create(['key' => 'connection_id', 'value' => (string) $this->connection->id]);
        $this->owner = User::factory()->create();
        $this->owner->client()->ensurePrimaryContact()->forceFill(['country' => 'GR', 'address1' => 'Odos 12', 'city' => 'Athens', 'postcode' => '10000', 'phone' => '+30.2100000000'])->save();
        $this->service = $this->sslService();
        Http::fake(fn (Request $request) => $this->openprovider($request));
    }

    private function sslService(): Service
    {
        $product = $this->createProduct();
        $product->plan->update(['billing_unit' => 'year', 'billing_period' => 1]);
        $product->product->update(['server_id' => $this->server->id]);
        $product->product->settings()->create(['key' => 'product_id', 'value' => '9']);

        return Service::factory()->create(['client_id' => $this->owner->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'server_id' => $this->server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'expires_at' => now()->addYear()]);
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
            $path === '/v1/ssl/csr/decode' => $ok($this->csr),
            $path === '/v1/ssl/products/9' => $ok(['id' => 9, 'is_wildcard_supported' => false]),
            $path === '/v1/ssl/approver-emails' => $ok(['results' => ['admin@shop.example.test', 'webmaster@shop.example.test']]),
            $path === '/v1/ssl/orders' && $request->method() === 'POST' => $this->newOrder($request),
            (bool) preg_match('#^/v1/ssl/orders/(\d+)/renew$#', $path, $m) => $this->renew((int) $m[1]),
            $path === '/v1/ssl/orders' && $this->listDown => Http::response(['code' => 500, 'desc' => 'Internal error'], 500),
            $path === '/v1/ssl/orders' => $ok(['results' => array_values(array_filter($this->orders,
                fn ($o) => $o['common_name'] === ($query['common_name_pattern'] ?? null)))]),
            (bool) preg_match('#^/v1/ssl/orders/(\d+)$#', $path, $m) => isset($this->orders[(int) $m[1]]) ? $ok($this->orders[(int) $m[1]])
                : Http::response(['code' => 404, 'desc' => 'not found'], 404),
            default => $ok(),
        };
    }

    private function newOrder(Request $request)
    {
        $id = $this->nextId++;
        $this->orders[$id] = ['id' => $id, 'common_name' => 'shop.example.test', 'product_id' => $request['product_id'], 'status' => 'REQ', 'csr' => $request['csr'],
            'organization_handle' => $request['organization_handle'], 'order_date' => now('Europe/Amsterdam')->format('Y-m-d H:i:s'), 'expiration_date' => '',
            'order_ends_at' => now('Europe/Amsterdam')->addYear()->format('Y-m-d H:i:s'), 'reissue_at' => '', 'certificate' => '', 'intermediate_certificate' => '',
            'email_approver' => $request['approver_email'] ?? '', 'domain_validation_methods' => $request['domain_validation_methods'], 'additional_data' => []];
        if ($this->orderAnswer === 'lost') {
            throw new ConnectionException('timed out');
        }

        return Http::response(['code' => 0, 'desc' => '', 'data' => ['id' => $id]]);
    }

    private function renew(int $id)
    {
        $ends = now('Europe/Amsterdam')->addYear()->addDays(20)->format('Y-m-d H:i:s');
        $newId = $id;
        if (in_array($this->renewAnswer, ['new', 'lost-new', 'new-then-408'], true)) {
            $newId = 900;
            $this->orders[900] = array_merge($this->orders[$id], ['id' => 900, 'order_date' => now('Europe/Amsterdam')->format('Y-m-d H:i:s'), 'order_ends_at' => $ends]);
        } elseif (!in_array($this->renewAnswer, ['lost-not-made', 'answered-not-moved'], true)) {
            $this->orders[$id]['order_ends_at'] = $ends;
        }
        if ($this->renewAnswer === 'new-then-408') {
            return Http::response(['code' => 408, 'desc' => 'Request Timeout'], 408); // a proxy's, after forwarding
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
        // Paid as the fix reads it (ADV-5): paid invoices' periods, the current one and, when paid beyond, the next.
        $this->paidPeriod(now()->subDays(345), now()->addDays(20));
        if ($paidBeyond) {
            $this->paidPeriod(now()->addDays(20), now()->addDays(385));
        }

        return $this->order();
    }

    private function paidPeriod(\DateTimeInterface $from, \DateTimeInterface $to): void
    {
        $invoice = Invoice::query()->create(['client_id' => $this->service->client_id, 'currency_code' => 'USD', 'due_at' => now()]);
        $this->service->invoiceItems()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => 10, 'description' => 'SSL',
            'period_start' => $from->format('Y-m-d'), 'period_end' => $to->format('Y-m-d')]);
        Invoice::query()->whereKey($invoice->id)->update(['status' => 'paid']);
    }

    private function place(?Service $service = null): mixed
    {
        return ExtensionHelper::callService(($service ?? $this->service)->fresh(), 'placeOrder', [self::CSR, 'dns', null]);
    }

    private function order(?Service $service = null): SslOrder
    {
        return SslOrder::query()->where('service_id', ($service ?? $this->service)->id)->sole();
    }

    private function extension(): OpenproviderSsl
    {
        $server = $this->service->provisioningServer();

        return ExtensionHelper::getExtension('server', $server->extension, $server->settings);
    }

    /** @return list<array<string, mixed>> */
    private function sent(string $method, string $path): array
    {
        return array_values(array_map(fn ($c) => $c[2], array_filter($this->calls, fn ($c) => $c[0] === $method && $c[1] === $path)));
    }

    /**
     * R2-1 (Medium): ADV-8's fix is unreachable once Openprovider marks the ended order expired. renewIfPaid returns
     * false on `status !== 'ACT'` before it ever reaches the «paid and ended unrenewed» check, so an order Openprovider
     * shows as EXP (its own status, in lang/en/services.php) whose customer has paid the next year is silent again: no
     * sync_error, exit 0. The same for an order whose reissue never validated (status REQ) through its end. The customer
     * has paid, the certificate lapses, nobody is told; and the order can't be ordered again (order_id is set).
     */
    public function test_r2_1_a_paid_order_that_ended_and_shows_expired_is_still_told(): void
    {
        $this->issued(paidBeyond: true);
        $this->orders[777]['status'] = 'EXP';
        $this->orders[777]['order_ends_at'] = now('Europe/Amsterdam')->subDays(2)->format('Y-m-d H:i:s');
        $this->orders[777]['expiration_date'] = now('Europe/Amsterdam')->subDays(2)->format('Y-m-d H:i:s');

        $this->artisan('ssl:sync');

        $this->assertSame([], $this->sent('POST', '/v1/ssl/orders/777/renew'));
        $this->assertNotNull($this->order()->sync_error, 'paid beyond an order Openprovider shows expired: staff must be told (ADV-8 again)');
    }

    /**
     * R2-2 (Medium): CR-5's fix keeps the old certificate while a reissue (or a renewal's new order) waits for its
     * validation, so `issued()` stays true. resendApproverEmail and changeApproverEmail (and Show::sslAwaitingEmail)
     * refuse whenever issued(): a customer who reissued with EMAIL validation can neither resend the approver mail nor
     * pick another approver, and the page shows no validation block either (the email method has no additional_data,
     * so `filled($sslOrder->validation['data'])` is false). The reissue can't be completed; the old certificate expires.
     */
    public function test_r2_2_a_reissue_waiting_for_email_approval_can_resend_the_approver_mail(): void
    {
        $this->issued(paidBeyond: false);
        $this->artisan('ssl:sync');
        $this->assertTrue(ExtensionHelper::callService($this->service->fresh(), 'reissue', [self::CSR, 'email', 'admin@shop.example.test']));
        // Openprovider: the reissue waits for the approver (no new certificate yet).
        $this->orders[777] = array_merge($this->orders[777], ['status' => 'REQ', 'certificate' => '', 'email_approver' => 'admin@shop.example.test']);
        $this->artisan('ssl:sync');

        $resent = rescue(fn () => ExtensionHelper::callService($this->service->fresh(), 'resendApproverEmail', []), false, false);

        $this->assertTrue($resent === true, 'the reissue waits for the approver, who never got the mail: resend refused (issued() kept by CR-5)');
        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders/777/approver-email/resend'));
    }

    /**
     * R2-3 (Medium, renews unpaid): the renewal is charged for the ORDER's period (the renew call sends none, so
     * Openprovider renews for the order's 3 years), while «paid» only asks for 30 days beyond the order's end. A
     * service ordered on a 3-year plan and moved to the 1-year plan (ChangeCycle, a plan of the same product) pays one
     * year and is renewed for three at our cost; if it is cancelled after that year, two are lost.
     */
    public function test_r2_3_a_renewal_is_never_charged_for_more_years_than_were_paid(): void
    {
        $this->issued(paidBeyond: true); // the next YEAR paid
        SslOrder::query()->whereKey($this->order()->id)->update(['period' => 3]); // ordered on the 3-year plan, now on the 1-year one

        $this->artisan('ssl:sync');

        $renewals = $this->sent('POST', '/v1/ssl/orders/777/renew');
        $this->assertTrue($renewals === [] || (int) ($renewals[0]['period'] ?? 0) === 1,
            'renewed for the order\'s 3 years with 1 year paid: send the period paid, or renew only what was paid');
    }

    /**
     * R2-4 (Medium, second charge): ADV-11's exclusion is not row-specific and counts dead services. Any OTHER SslOrder
     * on the connection with the same CSR, order_id null and requested_at set (here a terminated service whose request
     * was stuck and never cleared: terminate in KEEP mode returns at once) makes recoverRenewal skip EVERY row, so the
     * renewal's new order (made, answer lost) is never adopted. After a day it throws StillNotFound, which is exactly
     * what lets staff clear it; the next sync then charges a second renewal.
     */
    public function test_r2_4_a_dead_services_stale_request_never_hides_the_renewals_new_order(): void
    {
        $this->issued(paidBeyond: true);
        $old = $this->sslService();
        $old->forceFill(['status' => 'terminated'])->save();
        SslOrder::query()->create(['service_id' => $old->id, 'connection_id' => $this->connection->id, 'product_id' => 9, 'period' => 1,
            'common_name' => 'shop.example.test', 'csr' => self::CSR, 'validation_method' => 'dns', 'organization_handle' => 'XX123-GR',
            'requested_at' => now()->subMonths(6)]);

        $this->renewAnswer = 'lost-new';
        $this->artisan('ssl:sync')->assertExitCode(1);
        $this->renewAnswer = 'same';
        $this->artisan('ssl:sync');

        $this->assertSame(900, $this->order()->order_id, 'the renewal\'s order is never adopted');

        $this->travel(25)->hours();
        rescue(fn () => $this->extension()->clearRequest($this->order()), null, false);
        $this->artisan('ssl:sync');
        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders/777/renew'), 'charged twice');
    }

    /**
     * R2-5 (Low): CR-5's «keep the certificate in use» also keeps it when Openprovider cancelled the order (revoked).
     * sync sees status CAN with no certificate and keeps the old one and its expiry: the customer still sees a valid,
     * downloadable certificate (issued() true) that browsers reject. Before the fix it was cleared. Only a new order or
     * a reissue waiting (REQ) should keep it.
     */
    public function test_r2_5_a_cancelled_order_does_not_keep_showing_its_revoked_certificate(): void
    {
        $this->issued(paidBeyond: false);
        $this->artisan('ssl:sync');
        $this->assertTrue($this->order()->issued());

        $this->orders[777] = array_merge($this->orders[777], ['status' => 'CAN', 'certificate' => '', 'intermediate_certificate' => '', 'expiration_date' => '']);
        $this->artisan('ssl:sync');

        $this->assertFalse($this->order()->issued(), 'a cancelled (revoked) order still offers its certificate as issued');
    }
}
