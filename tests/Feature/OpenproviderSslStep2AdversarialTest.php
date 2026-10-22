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
 * Adversarial review of SSL step 2 (#350): reissue, approver change, the customer's notice and the automatic renewal
 * charged at Openprovider inside `ssl:sync`. Each ADV-n fails on the code as reviewed; Openprovider is faked as in
 * OpenproviderSslTest, with a few more answers (a 4xx after the renewal was made, a renewal answered whose end has not
 * moved yet, the order list down).
 */
class OpenproviderSslStep2AdversarialTest extends TestCase
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
     * ADV-1 (High): two syncs at once renew twice. Sync B read the order (its end 20 days away) before sync A renewed it;
     * A renews, sees the end moved and takes the mark off; B's renewIfPaid, on its old model, claims again (the claim
     * only asks `renew_requested_at IS NULL`, which A has just cleared) and charges a second renewal.
     */
    public function test_adv1_a_sync_on_an_old_model_never_renews_again_what_another_sync_renewed(): void
    {
        $this->issued(paidBeyond: true);
        $stale = $this->order();
        $this->extension()->sync($stale); // sync B has read the order: ACT, ends in 20 days

        $this->artisan('ssl:sync')->assertExitCode(0); // sync A: renewed, seen, mark off
        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders/777/renew'));

        rescue(fn () => $this->extension()->renewIfPaid($stale), null, false); // sync B goes on

        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders/777/renew'), 'charged twice: the claim must hold the order end it renews (or re-read it)');
    }

    /**
     * ADV-2 (High): staff clear a stuck renewal while Openprovider's order list is down: clearRequest swallows any
     * failure of the last look (catch Throwable → not found) and clears. The renewal was made (a new order, its answer
     * lost), so the next sync charges it again.
     */
    public function test_adv2_a_stuck_renewal_is_never_cleared_when_the_last_look_failed(): void
    {
        $this->issued(paidBeyond: true);
        $this->renewAnswer = 'lost-new';
        $this->artisan('ssl:sync')->assertExitCode(1);
        $this->travel(25)->hours();

        $this->listDown = true;
        $cleared = rescue(fn () => $this->extension()->clearRequest($this->order()) ?? true, false, false);
        $this->listDown = false;
        $this->renewAnswer = 'same';
        $this->artisan('ssl:sync');

        $this->assertFalse($cleared, 'cleared without having looked: the order list did not answer');
        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders/777/renew'), 'charged twice after the clear');
        $this->assertSame(900, $this->order()->order_id);
    }

    /**
     * ADV-3 (Medium): a 4xx after the renewal was made (a proxy's 408 once it forwarded, or Openprovider's own after
     * making a new order) takes the mark off as «nothing done». The renewal made a new order, so the old one still ends
     * in 20 days and the very next sync charges again; the new order is never adopted.
     */
    public function test_adv3_a_refusal_after_the_renewal_was_made_never_renews_twice(): void
    {
        $this->issued(paidBeyond: true);
        $this->renewAnswer = 'new-then-408';
        $this->artisan('ssl:sync');

        $this->renewAnswer = 'same';
        $this->artisan('ssl:sync');

        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders/777/renew'), 'a 4xx is not proof nothing was made: look before asking again');
        $this->assertSame(900, $this->order()->order_id);
    }

    /**
     * ADV-4 (High): the customer reissues (a new CSR) while a renewal is waiting to be seen. recoverRenewal looks for
     * the renewal's new order by the order's CSR, which is now the reissue's: the renewal made (with the old CSR) is
     * never found, is told as stuck after a day, and staff clearing it charges again. Reissue is not refused meanwhile.
     */
    public function test_adv4_a_reissue_while_a_renewal_is_pending_never_loses_the_renewal(): void
    {
        $this->issued(paidBeyond: true);
        $this->renewAnswer = 'lost-new';
        $this->artisan('ssl:sync')->assertExitCode(1);

        // The fix chosen: reissue waits while a renewal is unseen (refused), so the renewal is still found by its CSR.
        rescue(fn () => ExtensionHelper::callService($this->service->fresh(), 'reissue', [self::CSR2, 'dns', null]), null, false);
        $this->artisan('ssl:sync');

        $this->assertSame(900, $this->order()->order_id, 'the renewal made is found whatever CSR a reissue set since (or reissue waits)');
    }

    /**
     * ADV-5 (High): «paid» is read from expires_at, the Next Due Date staff may move by hand (ServiceObserver: comping an
     * unpaid period, a correction, an import). No invoice is paid here, yet Openprovider is charged a renewal. The paid
     * periods (Service::paidThrough, paid invoice lines) are what the customer paid.
     */
    public function test_adv5_a_due_date_moved_by_staff_without_payment_renews_nothing(): void
    {
        $this->issued(paidBeyond: false);
        $this->artisan('ssl:sync')->assertExitCode(0);

        $this->service->fresh()->forceFill(['expires_at' => now()->addDays(385)])->save(); // staff, no invoice paid
        $this->artisan('ssl:sync');

        $this->assertSame([], $this->sent('POST', '/v1/ssl/orders/777/renew'), 'charged at Openprovider for a period nobody paid');
    }

    /**
     * ADV-6 (Medium): a free plan (or one-time) never pays a renewal, yet one with an expires_at a year on (imported,
     * set by staff) is renewed and charged every year.
     */
    public function test_adv6_a_free_plan_is_never_renewed_at_our_cost(): void
    {
        $this->issued(paidBeyond: true);
        $this->service->plan->update(['type' => 'free', 'price' => 0]);

        $this->artisan('ssl:sync');

        $this->assertSame([], $this->sent('POST', '/v1/ssl/orders/777/renew'));
    }

    /**
     * ADV-7 (Medium): renewalSeen takes ANY move of the old order's end (a second, an hour of Amsterdam's DST, a date
     * Openprovider rewrites) as the renewal. The renewal made a new order whose answer was lost; the old order's end
     * shifts an hour; the mark goes off, the new order is never looked for, and the next sync charges again.
     */
    public function test_adv7_a_drift_of_the_old_orders_end_is_not_the_renewal(): void
    {
        $this->issued(paidBeyond: true);
        $this->renewAnswer = 'lost-new';
        $this->artisan('ssl:sync')->assertExitCode(1);

        $this->orders[777]['order_ends_at'] = now('Europe/Amsterdam')->addDays(20)->addHour()->format('Y-m-d H:i:s');
        $this->renewAnswer = 'same';
        $this->artisan('ssl:sync');
        $this->artisan('ssl:sync');

        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders/777/renew'), 'renewed twice');
        $this->assertSame(900, $this->order()->order_id);
    }

    /**
     * ADV-8 (Medium): paid, never renewed, and nobody told. The customer paid the next year late (suspended during the
     * last 30 days, or the sync was down): the order's end is past, so renewIfPaid returns false silently: no sync_error,
     * exit 0. The customer has paid a year and the certificate lapses.
     */
    public function test_adv8_a_paid_order_whose_end_passed_unrenewed_is_told(): void
    {
        $this->issued(paidBeyond: true);
        $this->orders[777]['order_ends_at'] = now('Europe/Amsterdam')->subDays(2)->format('Y-m-d H:i:s');
        $this->orders[777]['expiration_date'] = now('Europe/Amsterdam')->subDays(2)->format('Y-m-d H:i:s');

        $this->artisan('ssl:sync');

        $this->assertNotNull($this->order()->sync_error, 'paid beyond an order that ended unrenewed: staff must be told');
    }

    /**
     * ADV-9 (Medium): terminate (product says cancel) while a renewal waits to be seen. Only the old order is cancelled;
     * the renewal's new order (made, answer lost) stays live at Openprovider, paid by us, and the terminated service is
     * no longer synced, so it is never found. Terminate should look for the renewal first (as it does for a lost order).
     */
    public function test_adv9_terminate_with_a_pending_renewal_reaches_the_renewals_order(): void
    {
        $this->issued(paidBeyond: true);
        $this->service->product->settings()->create(['key' => 'terminate_mode', 'value' => OpenproviderSsl::CANCEL]);
        $this->renewAnswer = 'lost-new';
        $this->artisan('ssl:sync')->assertExitCode(1);

        $threw = rescue(fn () => ExtensionHelper::terminateServer($this->service->fresh()) && false, true, false);
        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders/777/cancel'), 'terminate ran: the old order cancelled');

        $this->assertTrue($threw || $this->sent('POST', '/v1/ssl/orders/900/cancel') !== [],
            'the renewal\'s order is neither cancelled nor is terminate refused while the renewal is unknown');
    }

    /**
     * ADV-10 (Medium): a renewal Openprovider confirmed (2xx, the same id) whose end has not moved yet (processed later)
     * is told as stuck after a day like one never made, and staff may clear it: the next sync charges a second renewal.
     * A confirmed renewal is known done; only an unknown one is for staff to clear.
     */
    public function test_adv10_a_confirmed_renewal_is_never_cleared_into_a_second_charge(): void
    {
        $this->issued(paidBeyond: true);
        $this->renewAnswer = 'answered-not-moved';
        $this->artisan('ssl:sync');
        $this->travel(25)->hours();
        $this->artisan('ssl:sync');

        rescue(fn () => $this->extension()->clearRequest($this->order()), null, false);
        $this->artisan('ssl:sync');

        $this->assertCount(1, $this->sent('POST', '/v1/ssl/orders/777/renew'), 'Openprovider answered the first: charged twice');
    }

    /**
     * ADV-11 (Medium): the renewal adopts another service's order. The same customer orders a second certificate for the
     * same name with the same CSR (a key reused) while service 1's renewal is unseen, and that order's answer is lost
     * (order_id null, so the «linked to another service» check misses it). Service 1 adopts it as its renewal; service 2's
     * own recovery then skips it as another's, is stuck, and is ordered (charged) again after staff clear it.
     */
    public function test_adv11_a_renewal_never_adopts_an_order_another_service_is_waiting_for(): void
    {
        $this->issued(paidBeyond: true);
        $this->renewAnswer = 'lost-not-made';
        $this->artisan('ssl:sync')->assertExitCode(1);

        $second = $this->sslService();
        ExtensionHelper::createServer($second->fresh());
        $this->orderAnswer = 'lost';
        rescue(fn () => $this->place($second), null, false);
        $this->assertNull($this->order($second)->order_id);
        $made = array_key_last($this->orders);

        $this->artisan('ssl:sync');

        $this->assertNotSame($made, $this->order()->order_id, 'service 1 took service 2\'s new order as its renewal');
        $this->assertSame($made, $this->order($second)->order_id);
    }
}
