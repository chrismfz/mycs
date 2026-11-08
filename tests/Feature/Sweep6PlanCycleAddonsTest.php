<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\ChangeCycle;
use App\Services\ServiceUpgrade\PlaceUpgrade;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sweep 6, adversarial verification of «plan-cycle-addons»: change of cycle, upgrades and addons, each test failing
 * for the reason reported.
 */
class Sweep6PlanCycleAddonsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'],
            'default_currency' => ['USD', 'string'], 'cronjob_invoice' => [7, 'integer']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(array $values): void
    {
        foreach ($values as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
    }

    private function plan(Product $product, string $unit, float $price): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => ucfirst($unit) . 'ly',
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $plan->fresh();
    }

    private function service(Product $product, Plan $plan, float $price, string $start, string $end, array $attributes = []): Service
    {
        $service = Service::factory()->create($attributes + ['client_id' => User::factory()->create()->client()->id,
            'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => $price, 'quantity' => 1,
            'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => $end]);
        $this->periodLine($service, $start, $end, $price, Invoice::STATUS_PAID);

        return $service->fresh();
    }

    private function periodLine(Service $service, string $start, string $end, float $price, string $status): InvoiceItem
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $line = $invoice->items()->create(['description' => 'Renewal', 'price' => $price, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => $start, 'period_end' => $end]);
        if ($status !== Invoice::STATUS_UNPAID) {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status]);
        }

        return $line;
    }

    private function pay(Invoice $invoice, string $ref): void
    {
        $invoice->refresh();
        (new RecordPaymentService)->handle($invoice, null, (float) $invoice->remaining, transactionId: $ref);
    }

    private function upgradeLine(ServiceUpgrade $upgrade): InvoiceItem
    {
        return InvoiceItem::query()->where('reference_type', ServiceUpgrade::class)->where('reference_id', $upgrade->id)
            ->where('invoice_id', $upgrade->invoice_id)->sole();
    }

    /**
     * S6-PLAN-CYCLE-ADDONS-1: an upgrade placed while a change of cycle «from the next renewal» is set. The cron applies
     * the yearly plan when it bills the renewal; the pending upgrade (priced for the monthly cycle) then reads as a
     * downgrade, its line falls to 0.00, and its payment puts the service back on a monthly plan.
     */
    public function test_an_upgrade_after_a_cycle_change_set_for_the_next_renewal_is_not_given_away(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        $a = Product::factory()->create(['name' => 'Basic']);
        $aMonthly = $this->plan($a, 'month', 10.00);
        $aYearly = $this->plan($a, 'year', 120.00);
        $b = Product::factory()->create(['name' => 'Pro']);
        $this->plan($b, 'month', 20.00);
        $this->plan($b, 'year', 240.00);
        $service = $this->service($a, $aMonthly, 10.00, '2026-11-01', '2026-12-01');

        // The customer's yearly choice, from the next renewal.
        ChangeCycle::apply($service, $aYearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);
        $this->assertSame($aYearly->id, (int) $service->fresh()->next_plan_id);

        // Then an upgrade to Pro: nothing refuses it.
        $placed = PlaceUpgrade::handle($service->fresh(), $b, [], ServiceUpgrade::SOURCE_STAFF);
        if ($placed->outcome !== PlaceUpgrade::INVOICED) {
            $this->markTestSkipped("The upgrade was not invoiced ({$placed->outcome}): refused as it should be.");
        }
        $upgrade = $placed->upgrade->fresh();
        $this->assertTrue(BigDecimal::of((string) $this->upgradeLine($upgrade)->price)->isPositive());

        // The cron bills the renewal 7 days ahead (the yearly plan applied), and follows the upgrade's price.
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->artisan('app:cron-job');

        $service->refresh();
        $line = $this->upgradeLine($upgrade->fresh());
        // Still owed: Pro over Basic for the 6 days left (20 - 10 a month), not 0.00 (an upgrade for free).
        $this->assertTrue(BigDecimal::of((string) $line->price)->isPositive(),
            "The pending upgrade's line was repriced to {$line->price}: the upgrade's days are given away once the yearly plan is applied.");
    }

    /** S6-PLAN-CYCLE-ADDONS-1, its payment: the service goes back to a monthly plan, the customer's yearly choice undone. */
    public function test_paying_that_upgrade_keeps_the_yearly_cycle_the_renewal_applied(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        $a = Product::factory()->create(['name' => 'Basic']);
        $aMonthly = $this->plan($a, 'month', 10.00);
        $aYearly = $this->plan($a, 'year', 120.00);
        $b = Product::factory()->create(['name' => 'Pro']);
        $this->plan($b, 'month', 20.00);
        $this->plan($b, 'year', 240.00);
        $service = $this->service($a, $aMonthly, 10.00, '2026-11-01', '2026-12-01');
        ChangeCycle::apply($service, $aYearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);
        $placed = PlaceUpgrade::handle($service->fresh(), $b, [], ServiceUpgrade::SOURCE_STAFF);
        if ($placed->outcome !== PlaceUpgrade::INVOICED) {
            $this->markTestSkipped("The upgrade was not invoiced ({$placed->outcome}): refused as it should be.");
        }
        $upgrade = $placed->upgrade->fresh();

        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->artisan('app:cron-job');
        $this->assertSame($aYearly->id, (int) $service->fresh()->plan_id, 'the renewal applied the yearly plan');
        $invoice = $upgrade->fresh()->invoice;
        // Its line at 0.00 now: closed as paid by staff («Close as paid», settle) or by the customer paying nothing.
        $this->assertSame('0.00', number_format((float) $invoice->total, 2, '.', ''));
        $this->assertTrue((new RecordPaymentService)->settle($invoice));
        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);

        $plan = $service->fresh()->plan;
        $this->assertSame('year', $plan->billing_unit,
            "Paid, the upgrade put the service on a {$plan->billing_unit}ly plan: the yearly cycle the customer chose is undone.");
    }

    /**
     * S6-PLAN-CYCLE-ADDONS-2: a completed upgrade's shortfall invoice (the days of a renewal paid before the upgrade) is
     * not seen by a change of cycle «now»: it stays open, billing the upgraded days that change credited and billed again.
     */
    public function test_a_cycle_change_now_does_not_leave_an_upgrade_shortfall_open(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        $a = Product::factory()->create(['name' => 'Basic']);
        $aMonthly = $this->plan($a, 'month', 10.00);
        $this->plan($a, 'year', 120.00);
        $b = Product::factory()->create(['name' => 'Pro']);
        $this->plan($b, 'month', 20.00);
        $bYearly = $this->plan($b, 'year', 240.00);
        $service = $this->service($a, $aMonthly, 10.00, '2026-11-01', '2026-12-01');

        $placed = PlaceUpgrade::handle($service, $b, [], ServiceUpgrade::SOURCE_STAFF);
        $this->assertSame(PlaceUpgrade::INVOICED, $placed->outcome);
        $upgrade = $placed->upgrade;

        // Nov 25: the cron bills the December renewal (Basic, 10) and reprices the upgrade line; the customer pays the
        // renewal, then the upgrade: the December days at Pro are billed apart (the shortfall).
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->artisan('app:cron-job');
        $renewal = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)
            ->whereDate('period_start', '2026-12-01')->sole()->invoice;
        $this->pay($renewal, 'renewal');
        $this->assertSame('2027-01-01', $service->fresh()->expires_at->toDateString());
        $this->pay($upgrade->fresh()->invoice, 'upgrade');
        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);
        $shortfall = Invoice::query()->whereKeyNot($upgrade->invoice_id)
            ->whereHas('items', fn ($q) => $q->where('reference_type', ServiceUpgrade::class)->where('reference_id', $upgrade->id))->sole();
        $this->assertSame(Invoice::STATUS_UNPAID, $shortfall->status);

        // Dec 5: staff change the cycle «now» to Pro yearly: the December period is credited and a new year billed.
        Carbon::setTestNow('2026-12-05 10:00:00');
        try {
            ChangeCycle::apply($service->fresh(), $bYearly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);
        } catch (DisplayException) {
            $this->assertTrue(true, 'refused while the shortfall is open: fine');

            return;
        }

        $this->assertNotContains($shortfall->fresh()->status, Invoice::OPEN_STATUSES,
            'The upgrade\'s shortfall (Pro over Basic, Dec 1 - Jan 1) stays open after a new yearly Pro period from Dec 5 was billed in full.');
    }

    /**
     * S6-PLAN-CYCLE-ADDONS-3: an upgrade's money is spread from the day it was placed, though the cron repriced its line
     * to the days left when it was paid: a cycle change «now» credits too little of it.
     */
    public function test_a_cycle_change_now_credits_an_upgrade_from_the_days_its_payment_covered(): void
    {
        Carbon::setTestNow('2026-09-04 10:00:00');
        $a = Product::factory()->create(['name' => 'Basic']);
        $aYearly = $this->plan($a, 'year', 120.00);
        $b = Product::factory()->create(['name' => 'Pro']);
        $bMonthly = $this->plan($b, 'month', 20.00);
        $this->plan($b, 'year', 240.00);
        $service = $this->service($a, $aYearly, 120.00, '2026-07-01', '2027-07-01');

        $placed = PlaceUpgrade::handle($service, $b, [], ServiceUpgrade::SOURCE_STAFF);
        $this->assertSame(PlaceUpgrade::INVOICED, $placed->outcome);
        $upgrade = $placed->upgrade;

        // 60 days later the cron reprices the line to the 240 days left, and it is paid.
        Carbon::setTestNow('2026-11-03 10:00:00');
        $this->artisan('app:cron-job');
        $paid = BigDecimal::of((string) $this->upgradeLine($upgrade->fresh())->price);
        $this->assertSame('78.90', (string) $paid->toScale(2), '120 a year more for 240 of 365 days');
        $this->pay($upgrade->fresh()->invoice, 'upgrade');
        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);

        // 10 days on (230 days left), staff change the cycle «now».
        Carbon::setTestNow('2026-11-13 10:00:00');
        $today = CarbonImmutable::today();
        $quote = ChangeCycle::quote($service->fresh(), $bMonthly, ChangeCycle::NOW);
        $period = BigDecimal::of(ChangeCycle::unused($service->fresh(), $today));
        $upgradePart = BigDecimal::of($quote['credit'])->minus($period);

        // What was paid for 240 days, 230 of them left: 75.61, not 78.90 x 230 / 300 = 60.49.
        $this->assertEqualsWithDelta(75.61, (float) (string) $upgradePart, 0.02,
            "The upgrade's part of the credit is {$upgradePart}: its money is spread from the day it was placed, not from the days it paid for.");
    }

    /**
     * S6-PLAN-CYCLE-ADDONS-4: an addon's change of cycle «from the next renewal» moves it off its parent's due date (the
     * owner's rule: an addon has its parent's expiry).
     */
    public function test_an_addons_cycle_change_from_the_next_renewal_keeps_its_parents_due_date(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        $host = Product::factory()->create(['name' => 'Server']);
        $hostYearly = $this->plan($host, 'year', 600.00);
        $extra = Product::factory()->create(['name' => 'Extra IPv4', 'is_addon' => true]);
        $extraMonthly = $this->plan($extra, 'month', 5.00);
        $extraYearly = $this->plan($extra, 'year', 50.00);
        $parent = $this->service($host, $hostYearly, 600.00, '2026-03-01', '2027-03-01');
        // A monthly addon on the parent's billing day (AddAddon::alignedEnd): due Dec 1.
        $addon = $this->service($extra, $extraMonthly, 5.00, '2026-11-01', '2026-12-01',
            ['client_id' => $parent->client_id, 'parent_service_id' => $parent->id]);

        ChangeCycle::apply($addon, $extraYearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);

        // The cron bills its renewal, in the yearly plan.
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->artisan('app:cron-job');

        $line = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $addon->id)
            ->whereDate('period_start', '2026-12-01')->sole();
        $this->assertSame($extraYearly->id, (int) $addon->fresh()->plan_id);
        $this->assertSame('2027-03-01', $line->period_end->toDateString(),
            "The addon's yearly period runs to {$line->period_end->toDateString()}, past its parent's due date 2027-03-01.");
    }
}
