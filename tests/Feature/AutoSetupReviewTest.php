<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
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
 * Independent code review of Σ1-b1 (d88d618, Auto Setup / Service::datesOnStart). Each test fails on the branch.
 */
class AutoSetupReviewTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-01-01 10:00:00');
        config(['settings.cronjob_order_suspend' => 8, 'settings.cronjob_invoice' => 7, 'settings.cronjob_order_cancel' => 7]);
        $this->product = $this->createProduct()->product;
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(string $autosetup, ?Coupon $coupon = null): Service
    {
        $this->product->update(['autosetup' => $autosetup, 'server_id' => null]);
        $product = $this->product->fresh();
        (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->first())], $coupon);

        return Service::query()->latest('id')->firstOrFail();
    }

    private function pay(Service $service, ?InvoiceItem $item = null): void
    {
        $item ??= InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)->oldest('id')->firstOrFail();
        DB::table('invoices')->where('id', $item->invoice_id)->update(['status' => 'paid']);
        (new RenewServiceService)->handle($service->fresh(), $item);
    }

    /**
     * R1. An unpaid one-time service set up on order is active at once and nothing ever pursues its invoice: one-time
     * plans get no expires_at (datesOnStart), the cron's suspension skips one-time plans, and the unpaid-order
     * cancellation only looks at pending services. With «payment» the same unpaid order is cancelled after Cancel Days.
     */
    public function test_an_unpaid_one_time_service_set_up_on_order_is_not_kept_running_for_free(): void
    {
        $this->product->plans()->first()->update(['type' => 'one-time']);
        $service = $this->order(Product::AUTOSETUP_ORDER);
        $this->assertSame('pending', $service->fresh()->status, 'a one-time plan is set up on payment (fix of R1)');
        $this->assertSame('unpaid', $service->invoices()->firstOrFail()->status);

        Carbon::setTestNow('2026-03-15 10:00:00'); // its invoice unpaid for ten weeks
        $this->artisan('app:cron-job');

        $this->assertNotSame('active', $service->fresh()->status,
            'an unpaid one-time order set up on order must be suspended or cancelled like any unpaid order, not run for ever');
    }

    /**
     * R2. Staff putting a running service back to pending and active again (a mis-click corrected the same minute) moves
     * its paid-until date by the days since its FIRST paid period ever: datesOnStart takes the min period_start of all
     * paid lines, not the time it actually waited. Before d88d618 a status change by staff never moved the date.
     */
    public function test_a_status_round_trip_by_staff_does_not_give_away_the_days_since_its_first_payment(): void
    {
        $service = $this->order(Product::AUTOSETUP_PAYMENT);
        $this->pay($service); // 1/1 → active, paid until 1/2
        $this->assertSame(['active', '2026-02-01'], [$service->fresh()->status, $service->fresh()->expires_at->toDateString()]);

        Carbon::setTestNow('2026-01-25 10:00:00');
        $service->fresh()->applyStaffChanges(['status' => 'pending']);
        $service->fresh()->applyStaffChanges(['status' => 'active']); // corrected at once

        $this->assertSame('2026-02-01', $service->fresh()->expires_at->toDateString(),
            'it never waited: 24 free days given (expires_at moved to 2026-02-25)');
    }

    /**
     * R3. A paid service started late (Auto Setup «never»/«accept», or a failed create) keeps coupon_started_at at the
     * paid period's start (1/1) while its periods now run from the day it started (1/11): Service::cycleNumber counts
     * 1/1→2/1→3/1 past 2/11 and the renewal 2/11–3/11, the coupon's 2nd cycle, is billed at full price.
     */
    public function test_a_coupon_for_two_cycles_still_discounts_the_second_cycle_of_a_service_started_late(): void
    {
        $coupon = Coupon::create(['type' => 'percentage', 'applies_to' => 'all', 'code' => 'HALF2', 'value' => 50, 'recurring' => 2]);
        $service = $this->order(Product::AUTOSETUP_NEVER, $coupon);
        $this->assertSame('5.00', number_format((float) $service->price, 2));
        $this->pay($service); // 1/1, paid until 1/2, pending (waits for staff)

        Carbon::setTestNow('2026-01-11 10:00:00');
        $service->fresh()->applyStaffChanges(['status' => 'active']);
        $this->assertSame('2026-02-11', $service->fresh()->expires_at->toDateString());

        Carbon::setTestNow('2026-02-05 10:00:00');
        $this->artisan('app:cron-job');

        $renewal = $service->fresh()->invoiceItems()->whereNotNull('period_start')->latest('id')->firstOrFail();
        $this->assertSame('2026-02-11', $renewal->period_start->toDateString());
        $this->assertSame('5.00', number_format((float) $renewal->price, 2), 'the 2nd of the coupon\'s 2 cycles is discounted');
        $this->assertNull($service->fresh()->coupon_expired_at);
    }
}
