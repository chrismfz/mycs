<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\ChangeCycle;
use App\Services\ServiceUpgrade\DowngradeCredit;
use App\Services\ServiceUpgrade\PlaceUpgrade;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Independent review of the upgrades sweep fixes (2026-11-07).
 *
 * REV-UPG-1: ChangeCycle::apply(NOW) ends the old paid line on today 00:00 and bills a new line from today 00:00. An
 * upgrade (or downgrade) made earlier the same day has its moment inside that NEW line, so DowngradeCredit::withinPeriod
 * spreads its money (or its credit) to the new line's end: what the cycle change already credited (or already took off)
 * is counted again by any later cycle change «now» or downgrade.
 */
class RevUpgradesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        $this->storeSettings(['credits_enabled' => true, 'credits_on_downgrade' => true, 'credits_auto_use' => false,
            'tax_enabled' => false, 'default_currency' => 'USD']);
        config(['settings.credits_enabled' => true, 'settings.credits_on_downgrade' => true, 'settings.credits_auto_use' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name): Product
    {
        return Product::factory()->create(['name' => $name, 'server_id' => null, 'stock' => null]);
    }

    private function plan(Product $product, string $unit, string $price): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => ucfirst($unit) . 'ly',
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $plan->fresh();
    }

    private function service(Product $product, Plan $plan, string $price, string $expires): Service
    {
        return Service::factory()->create(['client_id' => User::factory()->create()->client()->id,
            'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => $price, 'quantity' => 1, 'currency_code' => 'USD',
            'status' => Service::STATUS_ACTIVE, 'expires_at' => $expires]);
    }

    private function paidLine(Service $service, string $price, string $start, string $end): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Line', 'price' => $price, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => $start, 'period_end' => $end]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
    }

    /** An upgrade paid on 07-02 and a cycle change «now» the same day: the change credits it; nothing of it is left after. */
    public function test_rev_upg_1a_an_upgrade_credited_by_a_same_day_cycle_change_is_not_counted_again(): void
    {
        Carbon::setTestNow('2026-07-02 10:00:00');
        $a = $this->product('A');
        $aYear = $this->plan($a, 'year', '120.00');
        $c = $this->product('C');
        $cYear = $this->plan($c, 'year', '240.00');
        $cMonth = $this->plan($c, 'month', '20.00');
        $service = $this->service($a, $aYear, '120.00', '2027-01-01');
        $this->paidLine($service, '120.00', '2026-01-01', '2027-01-01');
        $up = PlaceUpgrade::handle($service->fresh(), $c->fresh(), [], ServiceUpgrade::SOURCE_STAFF);
        $this->assertSame(PlaceUpgrade::INVOICED, $up->outcome);
        (new RecordPaymentService)->handle($up->invoice->fresh(), null, number_format((float) $up->invoice->fresh()->total, 2, '.', ''), transactionId: 'rev-up-1a');
        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $up->upgrade->fresh()->status);

        Carbon::setTestNow('2026-07-02 11:00:00');
        $this->assertSame('120.32', ChangeCycle::quote($service->fresh(), $cMonth, ChangeCycle::NOW)['credit'], 'line 60.16 + upgrade 60.16');
        ChangeCycle::apply($service->fresh(), $cMonth, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        Carbon::setTestNow('2026-07-10 10:00:00');
        $service = $service->fresh();
        [$upgrades, $given] = DowngradeCredit::withinPeriod($service, CarbonImmutable::today());
        $this->assertSame(['0.00', '0.00'], [(string) $upgrades->toScale(2), (string) $given->toScale(2)],
            'the upgrade\'s 60.16 was credited by the cycle change; it is spread again over the new monthly line');
        $this->assertSame(ChangeCycle::unused($service, CarbonImmutable::today()), ChangeCycle::quote($service, $cYear, ChangeCycle::NOW)['credit'],
            'a second cycle change credits the upgrade again');
    }

    /** A downgrade on 07-02 and a cycle change «now» the same day: its credit is taken off once, not again later. */
    public function test_rev_upg_1b_a_downgrade_credit_taken_off_by_a_same_day_cycle_change_is_not_taken_off_again(): void
    {
        Carbon::setTestNow('2026-07-02 10:00:00');
        $a = $this->product('A');
        $this->plan($a, 'year', '120.00');
        $aMonth = $this->plan($a, 'month', '10.00');
        $aYear = Plan::query()->where('priceable_id', $a->id)->where('billing_unit', 'year')->first();
        $c = $this->product('C');
        $cYear = $this->plan($c, 'year', '240.00');
        $service = $this->service($c, $cYear, '240.00', '2027-01-01');
        $this->paidLine($service, '240.00', '2026-01-01', '2027-01-01');
        $down = PlaceUpgrade::handle($service->fresh(), $a->fresh(), [], ServiceUpgrade::SOURCE_STAFF);
        $this->assertSame(PlaceUpgrade::APPLIED, $down->outcome);
        $this->assertGreaterThan(0, $down->creditCents);

        Carbon::setTestNow('2026-07-02 11:00:00');
        ChangeCycle::apply($service->fresh(), $aMonth, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        Carbon::setTestNow('2026-07-10 10:00:00');
        $service = $service->fresh();
        [$upgrades, $given] = DowngradeCredit::withinPeriod($service, CarbonImmutable::today());
        $this->assertSame(['0.00', '0.00'], [(string) $upgrades->toScale(2), (string) $given->toScale(2)],
            'the downgrade\'s credit was taken off by the cycle change; it is taken off again in the new monthly period');
        $this->assertSame(ChangeCycle::unused($service, CarbonImmutable::today()), ChangeCycle::quote($service, $aYear, ChangeCycle::NOW)['credit'],
            'a second cycle change takes the downgrade\'s credit off again');
    }
}
