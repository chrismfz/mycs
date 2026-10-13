<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Round 4 review of PR #32 (an outside review before merge, docs/billing-cursor.md): five findings,
 * each reproduced here first, then fixed.
 */
class BillingCursorReview4Test extends TestCase
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
            'user_id' => $this->user->id,
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
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => $status === Invoice::STATUS_PAID ? Invoice::STATUS_UNPAID : $status, 'currency_code' => 'USD']);
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

    private function pay(Invoice $invoice): void
    {
        $invoice->transactions()->create(['amount' => $invoice->fresh()->remaining]);
    }

    private function yearly(Service $service): Plan
    {
        return Plan::factory()->create([
            'priceable_id' => $service->product_id, 'priceable_type' => Product::class,
            'name' => 'Yearly', 'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring',
        ]);
    }

    /** #1: re-processing a line that was already applied (paid -> pending -> paid) buys nothing, so it unsuspends nothing. */
    public function test_replaying_the_same_paid_line_does_not_unsuspend(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $this->pay($invoice);
        $this->assertSame('2026-11-15', $service->fresh()->expires_at->toDateString());

        $service->fresh()->update(['status' => 'suspended']); // staff, e.g. abuse
        $invoice->fresh()->update(['status' => 'unpaid']);
        $invoice->fresh()->update(['status' => 'paid']);

        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertSame('2026-11-15', $service->fresh()->expires_at->toDateString());
    }

    /** #2: a plan change on a service whose plan relation is already loaded re-periods with the NEW plan. */
    public function test_a_plan_change_with_the_plan_loaded_reperiods_the_open_line(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $yearly = $this->yearly($service);

        $service->plan; // loaded, as ServiceUpgradeService does before it changes plan_id
        $service->update(['plan_id' => $yearly->id, 'price' => 100.00]);

        $this->assertSame('2027-10-15', $invoice->items()->sole()->period_end->toDateString());
    }

    /** #3: re-perioding an open line never stretches it over a period that is already paid. */
    public function test_a_plan_change_does_not_stretch_an_open_line_over_a_paid_period(): void
    {
        $service = $this->service();
        $october = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $november = $this->invoiceFor($service, '2026-11-15', '2026-12-15');
        $this->pay($november); // ahead of the unpaid October: waits
        $this->assertSame('2026-10-15', $service->fresh()->expires_at->toDateString());

        $service->fresh()->update(['plan_id' => $this->yearly($service)->id]);

        $this->assertSame('2026-11-15', $october->items()->sole()->period_end->toDateString());
    }

    /** #4: two upgrades on one invoice each keep their own price in the cron's reprice step. */
    public function test_two_upgrades_on_one_invoice_keep_their_own_prices(): void
    {
        $service = $this->service();
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $upgrades = collect([20.00, 40.00])->map(function ($price) use ($service, $invoice) {
            $product = $this->product($price);
            $upgrade = ServiceUpgrade::create([
                'service_id' => $service->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
                'status' => 'pending', 'invoice_id' => $invoice->id,
            ]);
            $invoice->items()->create([
                'reference_id' => $upgrade->id, 'reference_type' => ServiceUpgrade::class,
                'description' => 'Upgrade', 'quantity' => 1, 'price' => 0.01,
            ]);

            return $upgrade;
        });

        $this->artisan('app:cron-job')->assertExitCode(0);

        foreach ($upgrades as $upgrade) {
            $line = $invoice->items()->where('reference_type', ServiceUpgrade::class)->where('reference_id', $upgrade->id)->sole();
            $this->assertEquals($upgrade->fresh()->calculatePrice()->price, $line->price);
        }
        $this->assertNotEquals(...$invoice->items()->pluck('price')->all());
    }

    /** #5: cancelling the first period does not restart a coupon's cycle count (cycles count from the first billed period). */
    public function test_a_cancelled_first_coupon_cycle_does_not_restart_the_count(): void
    {
        $coupon = Coupon::create([
            'type' => 'percentage', 'applies_to' => 'all', 'code' => 'HALF2', 'value' => 50, 'recurring' => 2,
        ]);
        $service = $this->service(['price' => 5.00, 'coupon_id' => $coupon->id, 'created_at' => '2026-08-15 09:00:00']);
        $this->invoiceFor($service, '2026-08-15', '2026-09-15', 'cancelled', 5.00); // cycle 1
        $this->invoiceFor($service, '2026-09-15', '2026-10-15', 'paid', 5.00);      // cycle 2

        $this->artisan('app:cron-job')->assertExitCode(0); // cycle 3: full price

        $line = $service->invoices()->where('status', 'unpaid')->sole()->items()->sole();
        $this->assertSame('2026-10-15', $line->period_start->toDateString());
        $this->assertSame('10.00', (string) $line->price);
    }
}
