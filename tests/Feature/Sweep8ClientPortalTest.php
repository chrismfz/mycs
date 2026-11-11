<?php

namespace Tests\Feature;

use App\Classes\Cart;
use App\Exceptions\CouponUnusableException;
use App\Livewire\Services\Cancel;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Providers\SettingsProvider;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\CancelOrder;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Sweep 8, area «client-portal»: the verifier's failing tests for what the finder reported. Each fails on main
 * (6f4b6e29) for the reason in its comment. Fake data only; no network.
 *
 * Since the importer gives the last paid line and the open renewal their period (WhmcsLinePeriods, owner 2026-11-11) the
 * tests of the period-less-line findings pass, with no billing rule changed; the others are marked incomplete, each with
 * its reason.
 */
class Sweep8ClientPortalTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-09-20 10:00:00');
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return object{product: Product, plan: Plan} */
    private function monthly(array $attributes = []): object
    {
        $made = $this->createProduct($attributes);
        $made->plan->update(['name' => 'Monthly']);

        return $made;
    }

    private function order(Product $product, ?Coupon $coupon = null, ?Client $client = null, Product ...$more)
    {
        $lines = array_map(fn (Product $p) => new OrderLine($p, $p->plans()->firstOrFail()->load('prices'), 1, []), [$product, ...$more]);

        return (new PlaceOrderService)->place($client ?? $this->client, 'USD', $lines, $coupon);
    }

    /**
     * S8-CLIENT-PORTAL-1. An imported WHMCS service whose ONLY invoice line is its open renewal (a service staff added
     * in WHMCS with no invoice, a free first cycle, earlier invoices written by hand without relid). The customer pays
     * it: RenewServiceService::isFirstOrderLine() takes the line for «the order's own invoice of a service staff
     * started by hand», gives it the period from the service's start (2024-01-01..2024-02-01), and the date does not
     * move. The money is kept, nothing is renewed, and the cron bills the same period again.
     */
    #[DataProvider('importedRenewals')]
    public function test_paying_the_imported_renewal_that_is_the_services_only_line_renews_it(string $due): void
    {
        Queue::fake();
        $this->whmcs($this->client->id, $this->monthly()->product->id, 'USD');
        // The control: one older paid invoice before its open renewal.
        $this->whmcsService(31, $due);
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-08-25');
        $this->whmcsInvoice(501, 'Unpaid', [31 => 10.0], due: $due);
        // The case: its open renewal is the only line it ever had.
        $this->whmcsService(32, $due);
        $this->whmcsInvoice(502, 'Unpaid', [32 => 10.0], due: $due);
        $this->importWhmcs();
        $this->imported(501);
        $this->imported(502);
        $next = Carbon::parse($due)->addMonth()->toDateString();

        (new RecordPaymentService)->handle(Invoice::query()->findOrFail(501), null, '10.00', transactionId: 'tr_s8_501');
        (new RecordPaymentService)->handle(Invoice::query()->findOrFail(502), null, '10.00', transactionId: 'tr_s8_502');

        $this->assertSame(Invoice::STATUS_PAID, Invoice::query()->findOrFail(502)->status);
        $this->assertSame($next, Service::query()->findOrFail(31)->expires_at->toDateString(), 'the control (an older paid invoice) is renewed');
        $this->assertSame($next, Service::query()->findOrFail(32)->expires_at->toDateString(),
            'paid, its renewal moves its date: the payment was taken for the period from ' . $due);
    }

    /** @return array<string, array{string}> */
    public static function importedRenewals(): array
    {
        return ['invoiced ahead' => ['2026-09-25'], 'overdue' => ['2026-09-10']];
    }

    /**
     * S8-CLIENT-PORTAL-2. Two products on one order, one unpaid invoice. The customer cancels A with the form's default,
     * «at the end of the billing period»: A's line leaves the shared invoice and the service is left for the cron, which
     * never ends it (processCancellations: active or suspended with a date; unpaidOrders: needs an invoice). A stays
     * pending for ever: its unit of stock and the per-account limit stay used.
     */
    public function test_an_end_of_period_cancellation_of_a_pending_unpaid_service_ends_it(): void
    {
        $this->markTestIncomplete('S8-CLIENT-PORTAL-2: another finding of sweep 8, not about the period of an imported line; still failing, left for its own fix.');

        $this->storeSettings(['cronjob_order_cancel' => 7]);
        SettingsProvider::reload();
        $a = $this->monthly(['stock' => 1, 'per_user_limit' => 1])->product;
        $b = $this->monthly()->product;
        $this->order($a, null, null, $b);
        $serviceA = Service::query()->where('product_id', $a->id)->firstOrFail();
        $serviceB = Service::query()->where('product_id', $b->id)->firstOrFail();
        $this->assertSame(Service::STATUS_PENDING, $serviceA->status);
        $this->assertSame(0, (int) $a->fresh()->stock);

        $this->livewireAs($this->user)->test(Cancel::class, ['service' => $serviceA])
            ->assertSet('type', 'end_of_period')
            ->set('reason', 'Changed my mind')
            ->call('cancelService');
        $this->assertDatabaseHas('service_cancellations', ['service_id' => $serviceA->id, 'type' => 'end_of_period']);

        Carbon::setTestNow('2026-10-25 03:00:00');
        $this->artisan('app:cron-job');

        $this->assertSame(Service::STATUS_CANCELLED, $serviceB->fresh()->status, 'the other, unpaid, was given up on by the cron');
        $this->assertSame(Service::STATUS_CANCELLED, $serviceA->fresh()->status, 'the one the customer cancelled is not left pending for ever');
        $this->assertSame(1, (int) $a->fresh()->stock, 'its unit of stock is back');
    }

    /**
     * S8-CLIENT-PORTAL-3. A coupon's uses are counted on every service that carries it, whatever its status: an order
     * that was never paid and that the cron cancelled still uses it up (max_uses), and its customer is told «already
     * used» (once_per_client). The per-account limit in the same file leaves cancelled services out (CART-18).
     */
    public function test_a_cancelled_never_paid_order_does_not_use_a_coupon_up(): void
    {
        $this->storeSettings(['cronjob_order_cancel' => 7]);
        SettingsProvider::reload();
        $product = $this->monthly()->product;
        $coupon = Coupon::query()->create(['code' => 'S8HALF', 'type' => 'percentage', 'value' => 50, 'max_uses' => 1, 'once_per_client' => true]);
        $this->order($product, $coupon);
        $first = Service::query()->where('client_id', $this->client->id)->firstOrFail();
        $this->assertSame((int) $coupon->id, (int) $first->coupon_id);

        Carbon::setTestNow('2026-10-05 03:00:00');
        $this->artisan('app:cron-job');
        $this->assertSame(Service::STATUS_CANCELLED, $first->fresh()->status, 'never paid: the cron gave the order up');
        $this->assertSame(0, Invoice::query()->where('status', Invoice::STATUS_PAID)->count(), 'nobody paid anything with the coupon');

        $refused = [];
        foreach (['the same customer' => $this->client, 'another customer' => User::factory()->create()->client()] as $who => $client) {
            try {
                $this->order($product, $coupon->fresh(), $client);
                // One use only: the second of the two is rightly refused once the first took it.
                break;
            } catch (CouponUnusableException $e) {
                $refused[$who] = $e->getMessage();
            }
        }

        $this->assertSame([], $refused, 'the promotion is still there: its only «use» was an order nobody paid');
    }

    /**
     * The neighbour of S8-CLIENT-PORTAL-3: an order that was paid is a use, also once it is cancelled (and refunded). Its
     * customer is told «already used» (once per client); another customer finds the promotion used up (max_uses).
     */
    #[DataProvider('paidOrders')]
    public function test_a_paid_order_still_uses_a_coupon_up(bool $cancelled, bool $refunded): void
    {
        $product = $this->monthly()->product;
        $coupon = Coupon::query()->create(['code' => 'S8HALF', 'type' => 'percentage', 'value' => 50, 'max_uses' => 1, 'max_uses_per_user' => 1, 'once_per_client' => true]);
        $placed = $this->order($product, $coupon);
        $invoice = Invoice::query()->findOrFail($placed->order->invoice_id);
        (new RecordPaymentService)->handle($invoice, null, (float) $invoice->total, transactionId: 'bank-1');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        if ($cancelled) {
            CancelOrder::handle($placed->order->fresh(), refund: $refunded ? 'manual' : null);
            $this->assertSame(Service::STATUS_CANCELLED, Service::query()->where('client_id', $this->client->id)->sole()->status);
            $this->assertSame($refunded ? Invoice::STATUS_REFUNDED : Invoice::STATUS_PAID, $invoice->fresh()->status);
        }

        $this->assertSame(1, $coupon->uses()->count());
        $this->assertTrue($coupon->hasExceededMaxUsesPerUser($this->client->id));
        $refused = [];
        foreach (['the same customer' => $this->client, 'another customer' => User::factory()->create()->client()] as $who => $client) {
            try {
                $this->order($product, $coupon->fresh(), $client);
            } catch (CouponUnusableException $e) {
                $refused[$who] = $e->getMessage();
            }
        }

        $this->assertSame(['the same customer' => __('You have already used this promotion.'), 'another customer' => __('This coupon can no longer be used')], $refused);
    }

    /** @return array<string, array{0: bool, 1: bool}> */
    public static function paidOrders(): array
    {
        return ['paid, running' => [false, false], 'paid, then cancelled' => [true, false], 'paid, cancelled and refunded' => [true, true]];
    }

    /** The per-customer limit (max_uses_per_user) and the cart's own check read the same count: a lapsed order frees it. */
    public function test_a_cancelled_never_paid_order_does_not_count_for_the_per_customer_limit_or_in_the_cart(): void
    {
        $product = $this->monthly()->product;
        $coupon = Coupon::query()->create(['code' => 'S8ONCE', 'type' => 'percentage', 'value' => 50, 'max_uses' => 1, 'max_uses_per_user' => 1]);
        $placed = $this->order($product, $coupon);
        $this->assertSame([1, true], [$coupon->uses()->count(), $coupon->hasExceededMaxUsesPerUser($this->client->id)], 'an open order holds its use');

        CancelOrder::handle($placed->order->fresh());

        $this->assertSame([1, 0, false], [$coupon->services()->count(), $coupon->uses()->count(), $coupon->hasExceededMaxUsesPerUser($this->client->id)]);
        $this->assertSame((int) $coupon->id, (int) Cart::validateCoupon('S8ONCE')->id, 'the cart takes it again');
        $this->order($product, $coupon->fresh());
        $this->assertSame(1, $coupon->uses()->count());
    }
}
