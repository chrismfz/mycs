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
 * The fixes of the upgrades sweep (2026-11-07) beyond its own tests (SweepUpgradesTest): what else they changed.
 */
class SweepUpgradesFixTest extends TestCase
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

    private function product(string $name, ?int $stock = null): Product
    {
        return Product::factory()->create(['name' => $name, 'server_id' => null, 'stock' => $stock]);
    }

    private function plan(Product $product, string $unit, string $price): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => ucfirst($unit) . 'ly',
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $plan->fresh();
    }

    private function service(Product $product, Plan $plan, string $price, string $expires, array $attributes = []): Service
    {
        return Service::factory()->create($attributes + ['client_id' => User::factory()->create()->client()->id,
            'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => $price, 'quantity' => 1, 'currency_code' => 'USD',
            'status' => Service::STATUS_ACTIVE, 'expires_at' => $expires]);
    }

    private function paidLine(Service $service, string $price, string $start, string $end): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Line', 'price' => $price, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => $start, 'period_end' => $end]);
        $this->statusFixture((int) $invoice->id, Invoice::STATUS_PAID);

        return $invoice;
    }

    private function pay(Invoice $invoice, string $id): void
    {
        (new RecordPaymentService)->handle($invoice->fresh(), null, number_format((float) $invoice->fresh()->total, 2, '.', ''), transactionId: $id);
    }

    /**
     * UPGRADES-1, after the change: an upgrade paid on 06-01 is credited by a cycle change «now» on 07-02 (its period line
     * ends that day). A later downgrade, in the new period, counts nothing of it again.
     */
    public function test_an_upgrade_credited_by_a_cycle_change_is_not_counted_again_after_it(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');
        $a = $this->product('A');
        $aYear = $this->plan($a, 'year', '120.00');
        $c = $this->product('C');
        $this->plan($c, 'year', '240.00');
        $cMonth = $this->plan($c, 'month', '20.00');
        $service = $this->service($a, $aYear, '120.00', '2027-01-01');
        $this->paidLine($service, '120.00', '2026-01-01', '2027-01-01');
        $up = PlaceUpgrade::handle($service->fresh(), $c->fresh(), [], ServiceUpgrade::SOURCE_STAFF);
        $this->pay($up->invoice, 'fix-up-1');

        Carbon::setTestNow('2026-07-02 10:00:00');
        [$upgrades] = DowngradeCredit::withinPeriod($service->fresh(), CarbonImmutable::today());
        $this->assertTrue($upgrades->isPositive(), 'counted while its period runs');
        $quote = ChangeCycle::quote($service->fresh(), $cMonth, ChangeCycle::NOW);
        $this->assertSame((string) $upgrades->plus('60.16'), $quote['credit'], 'the period line\'s 60.16 and the upgrade\'s share');
        ChangeCycle::apply($service->fresh(), $cMonth, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        Carbon::setTestNow('2026-07-10 10:00:00');
        [$upgrades, $given] = DowngradeCredit::withinPeriod($service->fresh(), CarbonImmutable::today());
        $this->assertSame(['0.00', '0.00'], [(string) $upgrades->toScale(2), (string) $given->toScale(2)], 'credited once, by the cycle change');
    }

    /** UPGRADES-5: a cycle change prices from the catalog, now or from the next renewal: the staff lock goes. */
    public function test_a_cycle_change_now_clears_the_price_lock(): void
    {
        Carbon::setTestNow('2026-07-02 10:00:00');
        $a = $this->product('A');
        $aYear = $this->plan($a, 'year', '120.00');
        $aMonth = $this->plan($a, 'month', '12.00');
        $service = $this->service($a, $aYear, '100.00', '2027-01-01', ['price_locked' => true]);
        $this->paidLine($service, '100.00', '2026-01-01', '2027-01-01');

        ChangeCycle::apply($service->fresh(), $aMonth, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $service = $service->fresh();
        $this->assertSame('12.00', number_format((float) $service->price, 2, '.', ''));
        $this->assertFalse((bool) $service->price_locked);
    }

    public function test_a_cycle_change_from_the_next_renewal_clears_the_price_lock_when_the_cron_applies_it(): void
    {
        Carbon::setTestNow('2026-07-02 10:00:00');
        $this->storeSettings(['cronjob_invoice' => 7]);
        config(['settings.cronjob_invoice' => 7]);
        $a = $this->product('A');
        $aYear = $this->plan($a, 'year', '120.00');
        $aMonth = $this->plan($a, 'month', '12.00');
        $service = $this->service($a, $aYear, '100.00', '2027-01-01', ['price_locked' => true]);
        $this->paidLine($service, '100.00', '2026-01-01', '2027-01-01');
        ChangeCycle::apply($service->fresh(), $aMonth, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF);
        $this->assertTrue((bool) $service->fresh()->price_locked, 'kept until the change is applied');

        Carbon::setTestNow('2026-12-28 10:00:00');
        $this->artisan('app:cron-job');

        $service = $service->fresh();
        $this->assertSame([(int) $aMonth->id, '12.00'], [(int) $service->plan_id, number_format((float) $service->price, 2, '.', '')]);
        $this->assertFalse((bool) $service->price_locked);
    }

    /** UPGRADES-3: with «keep overdue invoices» on, an overdue shortfall invoice stays as a debt, as the service's own do. */
    public function test_an_overdue_shortfall_invoice_is_kept_as_a_debt_when_the_setting_says_so(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        $small = $this->product('Small');
        $smallMonth = $this->plan($small, 'month', '10.00');
        $large = $this->product('Large');
        $this->plan($large, 'month', '30.00');
        $service = $this->service($small, $smallMonth, '10.00', '2026-07-31');
        $this->paidLine($service, '10.00', '2026-06-30', '2026-07-31');
        $up = PlaceUpgrade::handle($service->fresh(), $large->fresh(), [], ServiceUpgrade::SOURCE_STAFF);
        $this->paidLine($service, '10.00', '2026-07-31', '2026-08-31');
        DB::table('services')->where('id', $service->id)->update(['expires_at' => '2026-08-31']);
        $this->pay($up->invoice, 'fix-up-3');
        $shortfall = Invoice::query()->whereKeyNot($up->invoice->id)->where('status', Invoice::STATUS_UNPAID)
            ->whereHas('items', fn ($q) => $q->where('reference_type', ServiceUpgrade::class))->sole();

        Carbon::setTestNow('2026-08-20 10:00:00');
        $this->storeSettings(['cronjob_end_keeps_overdue' => true, 'cronjob_end_keeps_overdue_days' => 5]);
        config(['settings.cronjob_end_keeps_overdue' => true, 'settings.cronjob_end_keeps_overdue_days' => 5]);
        $service->fresh()->markTerminated();

        $shortfall = $shortfall->fresh();
        $this->assertSame(Invoice::STATUS_UNPAID, $shortfall->status, 'overdue more than the days set: a debt');
        $this->assertNotNull($shortfall->kept_as_debt_at);
    }

    /** UPGRADES-6: a product retired after the page listed it is not placed (it was, and applied once paid). */
    public function test_an_upgrade_to_a_product_retired_meanwhile_is_refused(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        $small = $this->product('Small');
        $smallMonth = $this->plan($small, 'month', '10.00');
        $large = $this->product('Large');
        $this->plan($large, 'month', '30.00');
        $service = $this->service($small, $smallMonth, '10.00', '2026-07-31');
        $this->paidLine($service, '10.00', '2026-06-30', '2026-07-31');
        $listed = $large->fresh();
        DB::table('products')->where('id', $large->id)->update(['retired' => true]);

        $placed = PlaceUpgrade::handle($service->fresh(), $listed, [], ServiceUpgrade::SOURCE_STAFF);

        $this->assertSame(PlaceUpgrade::INVALID, $placed->outcome);
        $this->assertSame(0, ServiceUpgrade::query()->count());
    }

    /** UPGRADES-6: the last one in stock is free again once the pending upgrade holding it is given up. */
    public function test_a_cancelled_pending_upgrade_holds_no_stock(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        $small = $this->product('Small');
        $smallMonth = $this->plan($small, 'month', '10.00');
        $large = $this->product('Large', 1);
        $this->plan($large, 'month', '30.00');
        $one = $this->service($small, $smallMonth, '10.00', '2026-07-31');
        $this->paidLine($one, '10.00', '2026-06-30', '2026-07-31');
        $two = $this->service($small, $smallMonth, '10.00', '2026-07-31');
        $this->paidLine($two, '10.00', '2026-06-30', '2026-07-31');

        $first = PlaceUpgrade::handle($one->fresh(), $large->fresh(), [], ServiceUpgrade::SOURCE_STAFF);
        $this->assertSame(PlaceUpgrade::INVOICED, $first->outcome);
        $this->assertSame(PlaceUpgrade::OUT_OF_STOCK, PlaceUpgrade::handle($two->fresh(), $large->fresh(), [], ServiceUpgrade::SOURCE_STAFF)->outcome);
        $first->upgrade->update(['status' => ServiceUpgrade::STATUS_CANCELLED]);

        $this->assertSame(PlaceUpgrade::INVOICED, PlaceUpgrade::handle($two->fresh(), $large->fresh(), [], ServiceUpgrade::SOURCE_STAFF)->outcome);
    }
}
