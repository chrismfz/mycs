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
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Adversarial review of #326, round 2: DowngradeCredit::cap(). */
class Adv326Round2Test extends TestCase
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
        DB::table('product_upgrades')->insertOrIgnore(['product_id' => $product->id, 'upgrade_id' => $product->id]);

        return $product->fresh();
    }

    private function service(Product $product, array $attributes = []): Service
    {
        return Service::factory()->create($attributes + [
            'client_id' => $this->user->client()->id, 'product_id' => $product->id, 'plan_id' => $product->plans()->first()->id,
            'status' => 'active', 'price' => '10.00', 'currency_code' => 'USD', 'expires_at' => '2026-03-21',
        ]);
    }

    private function paidLine(Service $service, string $paid, string $start, string $end): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Period', 'price' => $paid, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => $start, 'period_end' => $end]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
    }

    private function downgrade(Service $service, Product $to): void
    {
        DB::table('product_upgrades')->insertOrIgnore(['product_id' => $service->product_id, 'upgrade_id' => $to->id]);
        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service->fresh()])
            ->set('upgrade', $to->id)
            ->call('doUpgrade');
        $this->assertSame((int) $to->id, (int) $service->fresh()->product_id, 'the downgrade happened');
    }

    private function credit(): float
    {
        return round((float) Credit::query()->where('client_id', $this->user->client()->id)->sum('amount'), 2);
    }

    /**
     * G1 (medium): the cap is what is left of what was paid, but the customer keeps the service for those days, on the
     * new plan. Credit can only be what is left of what was paid less what the days left cost after the change. Half a
     * month paid (5.00 of 10), 20 of 30 days left, to a 2.00 plan: 3.33 is left of the 5.00, the 20 days at 2.00 cost
     * 1.33 (0.67 with the half off kept): at most 2.67 back. The cap gives 3.33, all of it: the 20 days are free.
     */
    public function test_g1_a_downgrade_does_not_give_back_the_days_the_customer_still_uses(): void
    {
        $service = $this->service($this->product('10.00'));
        $this->paidLine($service, '5.00', '2026-02-19', '2026-03-21');

        $this->downgrade($service, $this->product('2.00'));

        $this->assertLessThanOrEqual(2.67, $this->credit(), 'the days left on the new plan were given back too');
    }

    /**
     * G2 (medium): the same, plainer. 5.00 paid (half off a 10.00 month), down to an 8.00 plan with 20 of 30 days left:
     * 3.33 is left of what was paid, the 20 days at 8.00 cost 5.33 (2.67 with the half off kept). Nothing is owed back
     * beyond 0.67; the cap allows the whole renewal difference, 1.33.
     */
    public function test_g2_a_half_paid_month_down_to_a_dearer_plan_than_was_paid_gives_back_at_most_the_rest(): void
    {
        $service = $this->service($this->product('10.00'));
        $this->paidLine($service, '5.00', '2026-02-19', '2026-03-21');

        $this->downgrade($service, $this->product('8.00'));

        $this->assertLessThanOrEqual(0.67, $this->credit(), 'credit beyond what was paid for the days left');
    }

    /**
     * G3 (medium, a regression of round 1): every service imported from WHMCS has no period line (the importer writes
     * none), and one that ever had a promotion keeps it as coupon_id (ImportFromWhmcs::serviceCoupon, used up). The cap
     * is then 0: a customer who paid the WHMCS invoices in full gets nothing back on a downgrade. Round 1 (and main)
     * gave 5.33.
     */
    public function test_g3_an_imported_service_with_a_used_up_promotion_still_gets_its_downgrade_credit(): void
    {
        $coupon = Coupon::create(['code' => 'WHMCS1', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 10, 'recurring' => null]);
        $service = $this->service($this->product('10.00'), [
            'coupon_id' => $coupon->id, 'coupon_started_at' => '2025-01-01 00:00:00', 'coupon_expired_at' => '2026-01-01 00:00:00',
        ]);
        $this->assertSame(0, $service->invoiceItems()->count(), 'imported: no period line');

        $this->downgrade($service, $this->product('2.00'));

        $this->assertEqualsWithDelta(5.33, $this->credit(), 0.011, 'a fully paid imported service lost its downgrade credit');
    }
}
