<?php

namespace Tests\Feature;

use App\Livewire\Services\Upgrade;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Services\Service\RenewServiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of Σ1-b1 (Auto Setup per product, Service::datesOnStart). Each test fails on d88d618.
 */
class AutoSetupAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-01-01 10:00:00');
        config(['settings.cronjob_order_suspend' => 8, 'settings.cronjob_invoice' => 7, 'settings.cronjob_order_cancel' => 7,
            'settings.cronjob_order_terminate' => 60]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $autosetup, string $price = '10.00', string $setupFee = '0.00', string $type = 'recurring'): Product
    {
        $product = Product::factory()->create(['name' => 'P' . $price . $autosetup, 'autosetup' => $autosetup, 'server_id' => null, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Plan',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => $type]);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => $setupFee, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function order(Product $product, ?Coupon $coupon = null, $dueAt = null): Service
    {
        (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->firstOrFail())], $coupon, $dueAt);

        return Service::query()->latest('id')->firstOrFail();
    }

    /** Pays the service's newest open invoice, as the gateway path does (paid, then the line applied). */
    private function payLatest(Service $service): void
    {
        $invoice = $service->invoices()->where('invoices.status', Invoice::STATUS_UNPAID)->latest('invoices.id')->firstOrFail();
        $item = $invoice->items()->where('reference_id', $service->id)->where('reference_type', Service::class)->firstOrFail();
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid']);
        (new RenewServiceService)->handle($service->fresh(), $item);
    }

    /**
     * A1 (high): datesOnStart runs on every pending → active and measures the "wait" from the oldest paid period the
     * service ever had, not from when it went pending. A running service that staff set to Pending and back (a mistake,
     * a WHMCS habit, "hold it while we check") is given every day since its first payment for free.
     */
    public function test_a1_staff_setting_a_running_service_pending_and_back_gives_no_free_time(): void
    {
        $service = $this->order($this->product(Product::AUTOSETUP_PAYMENT));
        $this->payLatest($service); // 1/1: active, paid until 2/1
        $this->assertSame(['active', '2026-02-01'], [$service->fresh()->status, $service->fresh()->expires_at->toDateString()]);

        Carbon::setTestNow('2026-01-25 10:00:00');
        $this->artisan('app:cron-job'); // renewal 2/1–3/1
        $this->payLatest($service->fresh());
        $this->assertSame('2026-03-01', $service->fresh()->expires_at->toDateString());

        Carbon::setTestNow('2026-02-20 10:00:00');
        $service->fresh()->applyStaffChanges(['status' => 'pending']);
        $service->fresh()->applyStaffChanges(['status' => 'active']);

        $this->assertSame('2026-03-01', $service->fresh()->expires_at->toDateString(), 'paid until 3/1; no wait happened (got 50 free days)');
    }

    /** A1b: started late once (correct shift), toggled again the next day: the 10 days are given a second time. */
    public function test_a1b_a_late_started_service_is_not_shifted_twice(): void
    {
        $service = $this->order($this->product(Product::AUTOSETUP_NEVER));
        $this->payLatest($service); // 1/1, paid until 2/1, pending

        Carbon::setTestNow('2026-01-11 10:00:00');
        $service->fresh()->applyStaffChanges(['status' => 'active']);
        $this->assertSame('2026-02-11', $service->fresh()->expires_at->toDateString());

        Carbon::setTestNow('2026-01-12 10:00:00');
        $service->fresh()->applyStaffChanges(['status' => 'pending']);
        $service->fresh()->applyStaffChanges(['status' => 'active']);

        $this->assertSame('2026-02-11', $service->fresh()->expires_at->toDateString(), 'shifted again by the days since 1/1');
    }

    /**
     * A2 (high): «on order» with a one-time plan: active before payment, datesOnStart gives it no date, the suspension
     * skips one-time plans and orders_cancelled only looks at pending services. Never paid, it runs forever, its invoice
     * open forever, nobody told. On «payment» (parent) the same unpaid order was cancelled after Cancel Days.
     */
    public function test_a2_an_unpaid_one_time_product_set_up_on_order_does_not_run_for_ever(): void
    {
        $service = $this->order($this->product(Product::AUTOSETUP_ORDER, '100.00', type: 'one-time'));
        $this->assertSame('pending', $service->fresh()->status, 'a one-time plan is set up on payment (fix of review R1)');

        Carbon::setTestNow('2026-04-15 10:00:00'); // 104 days, invoice never paid
        $this->artisan('app:cron-job');

        $this->assertNotSame('active', $service->fresh()->status, 'a one-time product never paid is still active after 104 days');
    }

    /**
     * A3 (medium): «on order» sets expires_at = order date, but the first invoice's due date is the order's (now + 7, or
     * what staff set on Create Order). Suspension counts Suspend Days from expires_at, so a customer is suspended for an
     * invoice that is not due yet.
     */
    public function test_a3_an_order_set_up_before_payment_is_not_suspended_before_its_invoice_is_due(): void
    {
        $service = $this->order($this->product(Product::AUTOSETUP_ORDER), dueAt: now()->addDays(30)); // due 1/31
        $this->assertSame('active', $service->fresh()->status);

        Carbon::setTestNow('2026-01-12 10:00:00');
        $this->artisan('app:cron-job');

        $this->assertSame('2026-01-31', $service->invoices()->firstOrFail()->due_at->toDateString());
        $this->assertSame('active', $service->fresh()->status, 'suspended on 1/12 for an invoice due 1/31');
    }

    /**
     * A4 (medium): a coupon «for 2 cycles» on a product that waited for staff: the paid-until date moved by the wait but
     * coupon_started_at stays at the paid period's start, so cycleNumber counts the shifted renewal as cycle 3 and bills
     * the second (discounted) cycle at the full price.
     */
    public function test_a4_a_two_cycle_coupon_keeps_its_second_cycle_after_a_late_start(): void
    {
        $coupon = Coupon::create(['code' => 'TWO', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 2]);
        $service = $this->order($this->product(Product::AUTOSETUP_NEVER, '10.00'), $coupon);
        $this->assertSame('5.00', number_format((float) $service->price, 2));
        $this->payLatest($service); // 1/1, cycle 1 at 5.00

        Carbon::setTestNow('2026-01-11 10:00:00');
        $service->fresh()->applyStaffChanges(['status' => 'active']); // paid until 2/11

        Carbon::setTestNow('2026-02-05 10:00:00');
        $this->artisan('app:cron-job');

        $renewal = InvoiceItem::query()->where('reference_id', $service->id)->where('reference_type', Service::class)->whereNotNull('period_start')->latest('id')->firstOrFail();
        $this->assertSame('2026-02-11', $renewal->period_start->toDateString());
        $this->assertSame('5.00', number_format((float) $renewal->price, 2), 'cycle 2 of a 2-cycle coupon billed at full price');
    }

    /**
     * A5 (high): set up on order, the unpaid service is active, so the customer can upgrade it. Its expires_at is the
     * order date: 0 days left, the upgrade costs 0.00 and is applied at once, and ServiceUpgradeService reprices the open
     * first-order line (no period) to the new recurring price: the setup fee is gone from the first invoice.
     */
    public function test_a5_upgrading_an_unpaid_service_set_up_on_order_keeps_what_the_order_owes(): void
    {
        $from = $this->product(Product::AUTOSETUP_ORDER, '10.00', setupFee: '20.00');
        $to = $this->product(Product::AUTOSETUP_ORDER, '15.00');
        DB::table('product_upgrades')->insert(['product_id' => $from->id, 'upgrade_id' => $to->id]);
        $service = $this->order($from);
        $line = $service->invoices()->firstOrFail()->items()->firstOrFail();
        $this->assertSame('30.00', number_format((float) $line->price, 2), 'price + setup fee');

        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service->fresh()])->set('upgrade', $to->id)->call('doUpgrade');

        $this->assertSame(0, ServiceUpgrade::query()->count(), 'not upgradable while its order is unpaid (fix)');
        $this->assertGreaterThanOrEqual(30.0, (float) $line->fresh()->price, 'the first invoice lost its setup fee: ' . $line->fresh()->price);
    }

    /**
     * A6 (low): a first period that cost nothing (price 0, a 100% first-cycle coupon) is started through
     * RenewServiceService without an invoice line, so datesOnStart finds no paid line and gives back none of the wait:
     * a «never» product built by hand ten days later loses ten of its free days (the paid case gets them).
     */
    public function test_a6_a_free_first_period_that_waited_for_staff_counts_from_the_start_too(): void
    {
        $coupon = Coupon::create(['code' => 'FREE1', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 100, 'recurring' => null]);
        $service = $this->order($this->product(Product::AUTOSETUP_NEVER, '10.00'), $coupon);
        $this->assertSame(['pending', '2026-02-01'], [$service->fresh()->status, $service->fresh()->expires_at?->toDateString()]);

        Carbon::setTestNow('2026-01-11 10:00:00');
        $service->fresh()->applyStaffChanges(['status' => 'active']);

        $this->assertSame('2026-02-11', $service->fresh()->expires_at->toDateString(), 'the free month counted from the order, not from the start');
    }
}
