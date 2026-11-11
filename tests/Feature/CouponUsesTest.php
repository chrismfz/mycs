<?php

namespace Tests\Feature;

use App\Exceptions\CouponUnusableException;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\AcceptOrder;
use App\Services\Order\CancelOrder;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The rule of Coupon::uses() (sweep 9, owner 2026-11-11): a use is freed only when its service is cancelled and never
 * started (`services.activated_at`). Its neighbours are in Sweep8ClientPortalTest (never paid; paid, cancelled,
 * refunded; the limits and the cart), Rev793Test, Rev793R2Test, CouponRulesAdversarialTest and Sweep9CouponsCheckoutTest.
 * Invented data only, no network.
 */
class CouponUsesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Coupon $coupon;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-01-01 10:00:00');
        $this->user = User::factory()->create();
        $this->coupon = Coupon::query()->create(['code' => 'USESHALF', 'type' => 'percentage', 'value' => 50, 'max_uses' => 1, 'max_uses_per_user' => 1, 'once_per_client' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $autosetup = Product::AUTOSETUP_PAYMENT): Product
    {
        $product = $this->createProduct()->product;
        $product->update(['autosetup' => $autosetup, 'server_id' => null]);

        return $product->fresh();
    }

    private function place(Product $product)
    {
        return (new PlaceOrderService)->place($this->user->client(), 'USD',
            [new OrderLine($product, $product->plans()->firstOrFail()->load('prices'), 1, [])], $this->coupon->fresh());
    }

    private function uses(): int
    {
        return $this->coupon->uses()->where('client_id', $this->user->client()->id)->count();
    }

    /** An order waiting for its payment holds its use: a second checkout with the coupon sees the first. */
    public function test_a_pending_unpaid_order_holds_its_use(): void
    {
        $product = $this->product();
        $service = $this->place($product)->order->fresh()->services()->sole();
        $this->assertSame([Service::STATUS_PENDING, null], [$service->status, $service->activated_at]);
        $this->assertSame(1, $this->uses());

        $this->expectException(CouponUnusableException::class);
        $this->place($product);
    }

    /** Started by staff with nothing paid (Accept «start unpaid»), then cancelled: it ran, a use for ever. */
    public function test_an_unpaid_order_staff_started_and_then_cancelled_stays_a_use(): void
    {
        $product = $this->product(Product::AUTOSETUP_ACCEPT);
        $order = $this->place($product)->order->fresh();
        AcceptOrder::handle($order, null, runCreate: false, sendEmail: false, startUnpaid: true);
        $service = $order->services()->sole();
        $this->assertSame(Service::STATUS_ACTIVE, $service->status, 'precondition: started by staff, unpaid');
        $this->assertNotNull($service->activated_at);
        $this->assertSame(0, Invoice::query()->where('status', Invoice::STATUS_PAID)->count());

        CancelOrder::handle($order->fresh());

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertSame(1, $this->uses());
    }

    /** The status set to active by hand on the service's page, then cancelled: the same. */
    public function test_a_service_staff_set_active_by_hand_and_then_cancelled_stays_a_use(): void
    {
        $order = $this->place($this->product())->order->fresh();
        $service = $order->services()->sole();
        $service->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);
        $this->assertNotNull($service->fresh()->activated_at);

        $service->fresh()->applyStaffChanges(['status' => Service::STATUS_CANCELLED]);

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertSame(1, $this->uses());
    }

    /**
     * Paid, but its product waits for staff to set it up (Auto Setup «on accept»), and the order is cancelled and
     * refunded before they do: nothing was ever delivered, the promotion is free again.
     */
    public function test_a_paid_order_cancelled_and_refunded_before_staff_set_it_up_frees_its_use(): void
    {
        $order = $this->place($this->product(Product::AUTOSETUP_ACCEPT))->order->fresh();
        $invoice = Invoice::query()->findOrFail($order->invoice_id);
        (new RecordPaymentService)->handle($invoice, null, (float) $invoice->total, transactionId: 'bank-1');
        $service = $order->services()->sole();
        $this->assertSame([Invoice::STATUS_PAID, Service::STATUS_PENDING, null], [$invoice->fresh()->status, $service->status, $service->activated_at],
            'precondition: paid, waiting for staff');
        $this->assertSame(1, $this->uses());

        CancelOrder::handle($order->fresh(), refund: 'manual');

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertSame(0, $this->uses());
    }

    /**
     * As the importer writes a service (rows, no model events): `activated_at` is WHMCS's regdate for every status but
     * Pending. One that ever ran is a use whatever its status and its invoices; a pending one holds its use; only a
     * cancelled one with no start is free.
     */
    #[DataProvider('importedServices')]
    public function test_an_imported_service_is_a_use_unless_cancelled_and_never_started(string $status, ?string $activatedAt, int $uses): void
    {
        $service = $this->place($this->product())->order->fresh()->services()->sole();
        DB::table('services')->where('id', $service->id)->update(['status' => $status, 'activated_at' => $activatedAt]);
        DB::table('invoices')->update(['status' => Invoice::STATUS_CANCELLED]); // its only invoice, never paid

        $this->assertSame($uses, $this->uses());
    }

    /** @return array<string, array{0: string, 1: string|null, 2: int}> */
    public static function importedServices(): array
    {
        return [
            'cancelled, ran once' => [Service::STATUS_CANCELLED, '2024-03-01 00:00:00', 1],
            'terminated, ran once' => [Service::STATUS_TERMINATED, '2024-03-01 00:00:00', 1],
            'active' => [Service::STATUS_ACTIVE, '2024-03-01 00:00:00', 1],
            'pending' => [Service::STATUS_PENDING, null, 1],
            'terminated with no start' => [Service::STATUS_TERMINATED, null, 1],
            'cancelled with no start' => [Service::STATUS_CANCELLED, null, 0],
        ];
    }
}
