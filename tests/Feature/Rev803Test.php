<?php

namespace Tests\Feature;

use App\Exceptions\CouponUnusableException;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Order\CancelOrder;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #803 (Coupon::uses reads `services.activated_at` and no invoice or payment). Failing tests for
 * what was found; nothing here fixes app code. Invented data only, no network.
 */
class Rev803Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        Cache::flush();
        Carbon::setTestNow('2026-01-01 10:00:00');
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $autosetup): Product
    {
        $product = $this->createProduct()->product;
        $product->update(['autosetup' => $autosetup, 'server_id' => null]);

        return $product->fresh();
    }

    private function place(Product $product, Coupon $coupon)
    {
        return (new PlaceOrderService)->place($this->user->client(), 'USD',
            [new OrderLine($product, $product->plans()->firstOrFail()->load('prices'), 1, [])], $coupon->fresh());
    }

    /**
     * REV-803-1 (low to medium; introduced by #803). A product with Auto Setup «on order» (SetUpOnOrder) is active the
     * moment the order is placed, before anything is paid, so `activated_at` is set at checkout. The customer never
     * pays; staff cancel the order from Pending Orders (the only way an unpaid order ends by default:
     * `cronjob_order_cancel` is 0, and its step takes pending services only). On main the use was freed (cancelled,
     * billed, never paid: S8-CLIENT-PORTAL-3); now it is a use for ever, and the customer who comes back to order and
     * pay is refused «You have already used this promotion». docs/orders.md still says an order that lapsed unpaid
     * frees the coupon; for these products it never does.
     *
     * Should (as S8-CLIENT-PORTAL-3 and the doc read): an order nobody paid for does not use the promotion up. If the
     * owner's «what ran once is a use» is meant to cover a start nobody paid for, this is the rule's cost and the doc
     * should say so for «on order» products.
     */
    public function test_rev_803_1_an_unpaid_order_of_an_on_order_product_cancelled_by_staff_uses_the_coupon_up(): void
    {
        $this->markTestIncomplete('REV-803-1 accepted (2026-11-11): an «on order» product starts at checkout, so the service ran and is a use by the rule; staff give the discount by hand.');

        $product = $this->product(Product::AUTOSETUP_ORDER);
        $coupon = Coupon::query()->create(['code' => 'REV803A', 'type' => 'percentage', 'value' => 50, 'once_per_client' => true, 'max_uses_per_user' => 1]);
        $order = $this->place($product, $coupon)->order->fresh();
        $service = $order->services()->sole();
        $this->assertSame(Service::STATUS_ACTIVE, $service->status, 'precondition: set up on the order, before any payment');
        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->findOrFail($order->invoice_id)->status, 'precondition: nothing paid');

        CancelOrder::handle($order->fresh());
        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status, 'precondition: cancelled by staff');
        $this->assertSame(Invoice::STATUS_CANCELLED, Invoice::query()->findOrFail($order->invoice_id)->status, 'precondition: its invoice cancelled, never paid');

        try {
            $this->place($product, $coupon);
            $this->addToAssertionCount(1);
        } catch (CouponUnusableException $e) {
            $this->fail('a customer whose first order was never paid and was cancelled is refused the promotion: ' . $e->getMessage());
        }
    }

    /**
     * REV-803-2 (low; introduced by #803). `activated_at` is written only by a save that leaves the service `active`
     * (ServiceObserver::saving). A service that begins its life running in another status never gets it: the admin API
     * (`ServiceController::store`, app/Http/Controllers/Api/Admin/ServiceController.php:56, `status` in
     * pending,active,suspended,terminated,cancelled) and the staff form create one `suspended` (an account taken over
     * while on hold). Cancelled later, it is «cancelled and never started» and its coupon use is freed, though the
     * service existed and ran. On main it stayed a use (no invoice: «delivered for nothing»).
     *
     * Should: a service made already started (any status but pending and cancelled) has its start recorded, so its
     * coupon stays used when it is cancelled.
     */
    public function test_rev_803_2_a_service_created_suspended_and_later_cancelled_frees_its_coupon(): void
    {
        $this->markTestIncomplete('REV-803-2 accepted (2026-11-11): a staff or API action that skips active (created suspended, pending → suspended) leaves no start; rare, done by staff.');

        $product = $this->product(Product::AUTOSETUP_PAYMENT);
        $coupon = Coupon::query()->create(['code' => 'REV803B', 'type' => 'percentage', 'value' => 50, 'once_per_client' => true, 'max_uses' => 1]);
        // As ServiceController::store: Service::create() of the validated fields.
        $service = Service::create(['product_id' => $product->id, 'plan_id' => $product->plans()->firstOrFail()->id, 'client_id' => $this->user->client()->id,
            'quantity' => 1, 'status' => Service::STATUS_SUSPENDED, 'currency_code' => 'USD', 'price' => 5, 'coupon_id' => $coupon->id]);
        $this->assertSame(1, $coupon->uses()->count(), 'precondition: a use while it runs');

        $service->fresh()->applyStaffChanges(['status' => Service::STATUS_CANCELLED]);

        $this->assertSame(1, $coupon->uses()->count(), 'a service that existed, suspended, and was then cancelled is still a use');
    }
}
