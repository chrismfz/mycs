<?php

namespace Tests\Feature;

use App\Events\Service\Updated;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Round 3 review of PR #32 (docs/billing-cursor.md), accountant's view: every euro paid turns into
 * exactly the service time it bought, and no unpaid time is given away. A FAILING test is a confirmed
 * mismatch; each docblock gives the ledger (money in / money out / days granted).
 */
#[Group('local-review')]
class BillingCursorReview5Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 10:00:00');
        config([
            'settings.cronjob_invoice' => 7,
            'settings.credits_enabled' => false,
            'settings.cronjob_order_suspend' => 2,
            'settings.cronjob_order_terminate' => 14,
        ]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(float $price = 10.00): object
    {
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => $price]);

        return $product;
    }

    private function service(array $attributes = [], ?object $product = null): Service
    {
        $product ??= $this->product();

        return Service::factory()->create(array_merge([
            'client_id' => $this->user->client()->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => '2026-10-15',
            'currency_code' => 'USD',
            'price' => 10.00,
        ], $attributes));
    }

    private function invoiceFor(Service $service, ?string $start, ?string $end, string $status = 'unpaid', float $price = 10.00): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => $status === Invoice::STATUS_PAID ? Invoice::STATUS_UNPAID : $status, 'currency_code' => 'USD']);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'description' => 'Renewal',
            'quantity' => 1,
            'price' => $price,
            'period_start' => $start,
            'period_end' => $end,
        ]);

        // A paid fixture (a period paid in the past): stored paid, its lines added while it was open (MT-1c: a paid
        // invoice keeps its lines, and is never created paid).
        if ($status === Invoice::STATUS_PAID) {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        }

        return $invoice->fresh();
    }

    private function pay(Invoice $invoice, ?float $amount = null): void
    {
        $invoice->transactions()->create(['amount' => $amount ?? $invoice->fresh()->remaining]);
    }

    private function expires(Service $service): ?string
    {
        return $service->fresh()->expires_at?->toDateString();
    }

    private function cronOn(string $now): void
    {
        Carbon::setTestNow($now);
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    private function coupon(float $percent, int $recurring): Coupon
    {
        return Coupon::create([
            'type' => 'percentage', 'applies_to' => 'all', 'code' => 'C' . $percent . 'x' . $recurring,
            'value' => $percent, 'recurring' => $recurring,
        ]);
    }

    public function test_replay_preserves_staff_backward_correction(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $this->pay($invoice);
        $this->assertNotNull($invoice->items()->sole()->applied_at);
        $service->fresh()->update(['expires_at' => '2026-10-20', 'status' => 'suspended']);
        $invoice->fresh()->update(['status' => 'unpaid']);
        $invoice->fresh()->update(['status' => 'paid']);
        $this->assertSame(['2026-10-20', 'suspended'], [$this->expires($service), $service->fresh()->status]);
    }

    public function test_upgrade_preserves_proration_of_a_short_renewal_hole(): void
    {
        $service = $this->service();
        $future = $this->invoiceFor($service, '2026-11-01', '2026-12-01');
        $this->pay($future);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $open = $service->invoices()->where('status', 'unpaid')->sole();
        $this->assertSame('5.48', (string) $open->items()->sole()->price);
        $target = $this->product(20);
        $upgradeInvoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'plan_id' => $target->plan->id, 'product_id' => $target->product->id, 'status' => 'pending', 'invoice_id' => $upgradeInvoice->id]);
        $upgradeInvoice->items()->create(['reference_id' => $upgrade->id, 'reference_type' => ServiceUpgrade::class, 'description' => 'Monthly product upgrade', 'quantity' => 1, 'price' => 1]);
        $this->pay($upgradeInvoice->fresh());
        $line = $open->items()->sole();
        $this->assertSame('2026-11-01', $line->period_end->toDateString());
        $this->assertSame('10.97', (string) $line->price, 'The 17-day hole is repriced as an entire month');
    }

    public function test_free_coupon_does_not_reset_staff_price_two_cycles_later(): void
    {
        $service = $this->service(['created_at' => '2026-10-12', 'expires_at' => '2026-11-12', 'price' => 0, 'coupon_id' => $this->coupon(100, 2)->id]);
        $this->cronOn('2026-11-08 10:00:00');
        $this->cronOn('2026-12-08 10:00:00');
        $this->pay($service->invoices()->where('status', 'unpaid')->sole());
        $service->fresh()->update(['price' => 7]);
        $this->cronOn('2027-01-08 10:00:00');
        $this->pay($service->invoices()->where('status', 'unpaid')->sole());
        $this->cronOn('2027-02-08 10:00:00');
        $this->assertSame('7.00', (string) $service->invoices()->where('status', 'unpaid')->sole()->items()->sole()->price);
    }

    public function test_coupon_expires_even_if_staff_moves_due_date_past_expiry_cycle(): void
    {
        $service = $this->service(['created_at' => '2026-08-15', 'expires_at' => '2026-09-15', 'price' => 5, 'coupon_id' => $this->coupon(50, 2)->id]);
        $this->invoiceFor($service, '2026-08-15', '2026-09-15', 'paid', 5);
        $service->update(['expires_at' => '2026-11-15']);
        $this->cronOn('2026-11-12 10:00:00');
        $this->assertSame('10.00', (string) $service->invoices()->where('status', 'unpaid')->sole()->items()->sole()->price);
    }

    public function test_periodless_payment_does_not_overlap_a_paid_future_period(): void
    {
        $service = $this->service();
        $future = $this->invoiceFor($service, '2026-11-01', '2026-12-01');
        $this->pay($future);
        $manual = $this->invoiceFor($service, null, null);
        $this->pay($manual);
        $line = $manual->items()->sole();
        $this->assertTrue($line->period_end->lessThanOrEqualTo($future->items()->sole()->period_start) || $line->period_start->greaterThanOrEqualTo($future->items()->sole()->period_end), 'Two paid lines overlap by fourteen days');
    }

    public function test_failing_service_updated_listener_does_not_drop_other_paid_services(): void
    {
        $a = $this->service();
        $b = $this->service();
        $invoice = $this->invoiceFor($a, '2026-10-15', '2026-11-15');
        $invoice->items()->create(['reference_id' => $b->id, 'reference_type' => Service::class, 'description' => 'Second service', 'quantity' => 1, 'price' => 10, 'period_start' => '2026-10-15', 'period_end' => '2026-11-15']);
        Event::listen(Updated::class, function () {
            throw new \RuntimeException('Simulated external listener failure');
        });
        try {
            $this->pay($invoice);
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated external listener failure', $e->getMessage());
        }
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('2026-11-15', $this->expires($a));
        $this->assertSame('2026-11-15', $this->expires($b), 'Second service on a paid invoice was never renewed');
    }

    public function test_periodless_full_cycle_and_the_remaining_gap_are_both_preserved(): void
    {
        $service = $this->service();
        $future = $this->invoiceFor($service, '2026-11-01', '2026-12-01');
        $this->pay($future);
        $manual = $this->invoiceFor($service, null, null);
        $this->pay($manual);
        $line = $manual->items()->sole();
        $this->assertSame('2026-12-01', $line->period_start->toDateString());
        $this->assertSame('2027-01-01', $line->period_end->toDateString());
        $this->assertSame('10.00', (string) $line->price);
        $this->assertSame('2026-10-15', $this->expires($service));
        $this->artisan('app:cron-job')->assertExitCode(0);
        $gap = $service->invoices()->where('status', 'unpaid')->sole();
        $this->assertSame('5.48', (string) $gap->items()->sole()->price);
        $this->pay($gap);
        $this->assertSame('2027-01-01', $this->expires($service));
        // A replay of the earlier waiting payment does not undo staff's subsequent correction.
        $service->fresh()->update(['expires_at' => '2026-12-15', 'status' => 'suspended']);
        $manual->fresh()->update(['status' => 'unpaid']);
        $manual->fresh()->update(['status' => 'paid']);
        $this->assertSame('2026-12-15', $this->expires($service));
        $this->assertSame('suspended', $service->fresh()->status);
    }

    public function test_coupon_expiration_is_saved_once_and_not_reapplied_by_repricing(): void
    {
        $service = $this->service(['created_at' => '2026-10-12', 'expires_at' => '2026-11-12', 'price' => 0, 'coupon_id' => $this->coupon(100, 2)->id]);
        $this->cronOn('2026-11-08 10:00:00');
        $this->assertSame('2026-10-12', $service->fresh()->coupon_started_at->toDateString());
        $this->cronOn('2026-12-08 10:00:00');
        $this->assertNotNull($service->fresh()->coupon_expired_at);
        $this->assertSame('10.00', $service->fresh()->calculatePrice());
        $invoice = $service->invoices()->where('status', 'unpaid')->sole();
        $invoice->update(['status' => 'cancelled']);
        $service->fresh()->update(['price' => 7]);
        $this->cronOn('2026-12-08 11:00:00');
        $this->assertSame('7.00', (string) $service->invoices()->where('status', 'unpaid')->sole()->items()->sole()->price);
    }

    public function test_yearly_plan_change_prices_a_clipped_line_proportionally(): void
    {
        $service = $this->service();
        $open = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $future = $this->invoiceFor($service, '2026-11-15', '2026-12-15');
        $this->pay($future);
        $yearly = Plan::factory()->create(['priceable_id' => $service->product_id, 'priceable_type' => Product::class, 'name' => 'Yearly', 'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        $service->update(['plan_id' => $yearly->id, 'price' => 100]);
        $line = $open->items()->sole();
        $this->assertSame('2026-11-15', $line->period_end->toDateString());
        $this->assertSame('8.49', (string) $line->price);
    }

    public function test_an_actual_renewal_failure_does_not_skip_other_lines_and_is_reported(): void
    {
        $a = $this->service();
        $b = $this->service();
        $a->plan->update(['billing_unit' => 'hour']);
        $invoice = $this->invoiceFor($a, null, null);
        $invoice->items()->create(['reference_id' => $b->id, 'reference_type' => Service::class, 'description' => 'Second service', 'quantity' => 1, 'price' => 10, 'period_start' => '2026-10-15', 'period_end' => '2026-11-15']);
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (\InvalidArgumentException $e) use (&$reported) {
            $reported[] = $e;
        });
        $this->pay($invoice); // the payment is recorded: whoever paid is not told it failed (BIL-9)
        $this->assertNotEmpty($reported, 'A real failed renewal must still surface as an error (reported)');
        $this->assertNotNull($invoice->fresh()->apply_pending_since, 'and left for the cron to apply again');
        $this->assertSame('2026-11-15', $this->expires($b));
        $this->assertSame('2026-10-15', $this->expires($a));
        $this->assertNull($invoice->items()->where('reference_id', $a->id)->sole()->applied_at);
    }

    public function test_a_prorated_hole_rounded_to_zero_is_settled_without_a_gateway(): void
    {
        $service = $this->service(['price' => '0.01', 'expires_at' => '2026-10-31']);
        $future = $this->invoiceFor($service, '2026-11-01', '2026-12-01');
        $this->pay($future);
        $this->cronOn('2026-10-25 10:00:00');
        $gap = $service->invoiceItems()->whereDate('period_start', '2026-10-31')->sole();
        $this->assertSame('0.00', (string) $gap->price);
        $this->assertSame('paid', $gap->invoice->status);
        $this->assertNotNull($gap->applied_at);
        $this->assertSame('2026-12-01', $this->expires($service));
    }
}
