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

/** Adversarial review of #326, round 3: credit = min(|gross|, cap − what the days left cost on the new plan). */
class Adv326Round3Test extends TestCase
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

        return $product->fresh();
    }

    private function change(Service $service, Product $to): void
    {
        DB::table('product_upgrades')->insertOrIgnore(['product_id' => $service->fresh()->product_id, 'upgrade_id' => $to->id]);
        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service->fresh()])
            ->set('upgrade', $to->id)
            ->call('doUpgrade');
    }

    /** Upgrades $service to $to and pays the upgrade's invoice; returns what was paid. */
    private function upgradeAndPay(Service $service, Product $to): float
    {
        $this->change($service, $to);
        $upgrade = ServiceUpgrade::query()->where('service_id', $service->id)->latest('id')->firstOrFail();
        $this->assertNotNull($upgrade->invoice, 'the upgrade has an invoice to pay');
        $due = (float) $upgrade->invoice->items()->sum('line_total');
        $this->assertGreaterThan(0.0, $due);
        (new RecordPaymentService)->handle($upgrade->invoice, null, $due, transactionId: 'adv326-r3-' . $upgrade->id);
        $this->assertSame((int) $to->id, (int) $service->fresh()->product_id, 'the upgrade applied on payment');

        return $due;
    }

    private function credit(): float
    {
        return round((float) Credit::query()->where('client_id', $this->user->client()->id)->sum('amount'), 2);
    }

    /**
     * H1 (medium): a month free by a coupon, upgraded and the upgrade paid, then put back the same day. The cap returns
     * 0 before it looks at upgrades (no paid period line and a coupon running), so the upgrade's money is lost.
     */
    public function test_h1_an_upgrade_paid_on_a_free_month_comes_back_when_it_is_undone(): void
    {
        $base = $this->product('10.00');
        $big = $this->product('20.00');
        $coupon = Coupon::create(['code' => 'FREE1', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 100, 'recurring' => null]);
        (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($base, $base->plans()->firstOrFail())], $coupon);
        $service = Service::query()->latest('id')->firstOrFail();
        $service->forceFill(['status' => 'active'])->save();
        Carbon::setTestNow('2026-03-11 10:00:00');

        $paid = $this->upgradeAndPay($service, $big);
        $this->change($service, $base);

        $this->assertSame((int) $base->id, (int) $service->fresh()->product_id, 'the downgrade happened');
        $this->assertEqualsWithDelta($paid, $this->credit(), 0.02, "paid {$paid} for the upgrade, undone at once");
    }

    /**
     * H2 (medium): half a month paid (a 50% coupon on the 10.00 plan), upgraded to 20.00 and the 6.67 paid, then put
     * back to 10.00 the same day. The days left are netted at the new plan's full price (6.67), not at what the period
     * was paid at, and the upgrade's money is in the same pot: 3.33 of the 6.67 just paid is kept.
     */
    public function test_h2_an_upgrade_undone_at_once_gives_back_what_it_cost_on_a_discounted_period(): void
    {
        $base = $this->product('10.00');
        $big = $this->product('20.00');
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'product_id' => $base->id, 'plan_id' => $base->plans()->first()->id,
            'status' => 'active', 'price' => '10.00', 'currency_code' => 'USD', 'expires_at' => '2026-03-21',
        ]);
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'First month, half off', 'price' => '5.00', 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => '2026-02-19', 'period_end' => '2026-03-21']);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);

        $paid = $this->upgradeAndPay($service, $big);
        $this->change($service, $base);

        $this->assertSame((int) $base->id, (int) $service->fresh()->product_id, 'the downgrade happened');
        $this->assertEqualsWithDelta($paid, $this->credit(), 0.02, "paid {$paid} for the upgrade, undone at once");
    }
}
