<?php

namespace Tests\Feature;

use App\Livewire\Services\Upgrade;
use App\Models\Coupon;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #326 (a downgrade's credit capped at what is left of what was paid). Each test is a path where the
 * cap still gives credit that was never paid, or takes credit that was.
 */
class Adv326DowngradeCreditTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-03-01 10:00:00');
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        // In the table too: an order's counter flushes the settings cache and reloads them from it.
        foreach (['credits_enabled', 'credits_on_downgrade'] as $key) {
            Setting::query()->whereNull('settingable_type')->updateOrCreate(['key' => $key], ['value' => '1', 'type' => 'boolean']);
        }
        config(['settings.credits_enabled' => true, 'settings.credits_on_downgrade' => true]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $price): Product
    {
        $product = Product::factory()->create(['name' => 'P' . $price, 'server_id' => null, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Plan',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => '0.00', 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function link(Product $from, Product ...$to): void
    {
        foreach ($to as $target) {
            DB::table('product_upgrades')->insertOrIgnore(['product_id' => $from->id, 'upgrade_id' => $target->id]);
        }
    }

    /** A running service of $product at its catalog price, with a paid line of $paid for [$start, $end). */
    private function service(Product $product, string $price, string $paid, string $start, string $end): Service
    {
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'product_id' => $product->id, 'plan_id' => $product->plans()->first()->id,
            'status' => 'active', 'price' => $price, 'currency_code' => 'USD', 'expires_at' => $end,
        ]);
        $this->paidLine($service, $paid, $start, $end);

        return $service;
    }

    private function paidLine(Service $service, string $paid, string $start, string $end): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Period', 'price' => $paid, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => $start, 'period_end' => $end]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
    }

    private function change(Service $service, Product $to): void
    {
        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service->fresh()])
            ->set('upgrade', $to->id)
            ->call('doUpgrade');
    }

    private function credit(): float
    {
        return round((float) Credit::query()->where('client_id', $this->user->client()->id)->sum('amount'), 2);
    }

    /**
     * F1 (high): an order made free by a 100% first-cycle coupon makes no invoice line at all (PlaceOrderService: nothing
     * due, started through RenewServiceService), and its service renews at the full price. ChangeCycle::unused() then
     * takes the "imported without a line" branch and prices the free month at services.price: the cap is the very
     * renewal price the PR set out to stop paying back. A free month, downgraded at once, still gives credit.
     */
    public function test_f1_a_month_free_by_a_coupon_order_gives_no_credit_on_a_downgrade(): void
    {
        $big = $this->product('10.00');
        $small = $this->product('2.00');
        $this->link($big, $small);
        $coupon = Coupon::create(['code' => 'FREE1', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 100, 'recurring' => null]);
        (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($big, $big->plans()->firstOrFail())], $coupon);
        $service = Service::query()->latest('id')->firstOrFail();
        $service->forceFill(['status' => 'active'])->save();
        $this->assertSame('10.00', number_format((float) $service->fresh()->price, 2, '.', ''), 'renews at the full price');
        $this->assertSame(0, $service->invoiceItems()->count(), 'a free order has no line');

        Carbon::setTestNow('2026-03-11 10:00:00');
        $this->change($service, $small);

        $this->assertSame((int) $small->id, (int) $service->fresh()->product_id, 'the downgrade happened');
        $this->assertSame(0.0, $this->credit(), 'credit for a month that cost nothing');
    }

    /**
     * F2 (high): the cap is not used up. A downgrade gives credit but leaves the paid line as it is (ChangeCycle::apply
     * ends it; the downgrade does not), so each downgrade in a row is capped against the same paid money again. Half a
     * month paid (5.00 of 10), 20 of 30 days left: at most 3.33 can come back; three small steps give 5.33.
     */
    public function test_f2_downgrades_in_a_row_never_give_back_more_than_was_paid_in_all(): void
    {
        $p10 = $this->product('10.00');
        $p8 = $this->product('8.00');
        $p6 = $this->product('6.00');
        $p2 = $this->product('2.00');
        $this->link($p10, $p8);
        $this->link($p8, $p6);
        $this->link($p6, $p2);
        $service = $this->service($p10, '10.00', '5.00', '2026-02-19', '2026-03-21');

        $this->change($service, $p8);
        $this->change($service, $p6);
        $this->change($service, $p2);

        $this->assertSame((int) $p2->id, (int) $service->fresh()->product_id, 'all three downgrades happened');
        $this->assertLessThanOrEqual(3.34, $this->credit(), 'more credit than the 3.33 left of the 5.00 paid');
    }

    /**
     * F3 (medium, a regression): a renewal paid ahead. The difference counts every day to the expiry (both periods,
     * BillingPeriod::remaining), but the cap only the line covering today (paidCovering): the next period, paid in full,
     * is not counted. 10 → 2 with 20 days of this period and a whole next one paid: 13.33 owed, 6.67 given.
     */
    public function test_f3_a_period_paid_ahead_still_counts_in_the_cap(): void
    {
        $big = $this->product('10.00');
        $small = $this->product('2.00');
        $this->link($big, $small);
        $service = $this->service($big, '10.00', '10.00', '2026-02-19', '2026-03-21');
        $this->paidLine($service, '10.00', '2026-03-21', '2026-04-21');
        $service->forceFill(['expires_at' => '2026-04-21'])->save();

        $this->change($service, $small);

        $this->assertSame((int) $small->id, (int) $service->fresh()->product_id);
        $this->assertGreaterThanOrEqual(13.0, $this->credit(), 'the next period, paid in full, gave nothing back');
    }

    /**
     * F4 (medium, a regression): an upgrade's difference is paid on its own invoice, with a line referenced to the
     * ServiceUpgrade and no period, so the cap does not see it. 10 paid in full, upgraded to 20 (6.67 paid for the 20 days
     * left), then down to 2: 12.00 of paid money is left, the cap allows 6.67.
     */
    public function test_f4_what_an_upgrade_paid_counts_in_the_cap_of_the_next_downgrade(): void
    {
        $mid = $this->product('10.00');
        $big = $this->product('20.00');
        $small = $this->product('2.00');
        $this->link($mid, $big);
        $this->link($big, $small);
        $service = $this->service($mid, '10.00', '10.00', '2026-02-19', '2026-03-21');

        $this->change($service, $big);
        $upgrade = ServiceUpgrade::query()->where('service_id', $service->id)->latest('id')->firstOrFail();
        $this->assertNotNull($upgrade->invoice);
        $due = (float) $upgrade->invoice->items()->sum('line_total');
        $this->assertEqualsWithDelta(6.67, $due, 0.011);
        (new RecordPaymentService)->handle($upgrade->invoice, null, $due, transactionId: 'adv326-up');
        $this->assertSame((int) $big->id, (int) $service->fresh()->product_id, 'the upgrade applied on payment');

        $this->change($service, $small);

        $this->assertSame((int) $small->id, (int) $service->fresh()->product_id);
        $this->assertGreaterThanOrEqual(11.99, $this->credit(), 'the 6.67 paid for the upgrade was not given back');
    }
}
