<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\User;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\ChangeCycle;
use App\Services\ServiceUpgrade\PlaceUpgrade;
use App\Services\ServiceUpgrade\UpgradeQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sweep 7, adversarial verification of «plan-cycle-addons»: each test fails on main (44532e53) for the reason reported.
 * Each takes a refusal as a right outcome too (KISS: the case may be cut rather than computed).
 */
class Sweep7PlanCycleAddonsTest extends TestCase
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

    private function service(Product $product, Plan $plan, float $price, ?string $expires, array $attributes = []): Service
    {
        return Service::factory()->create($attributes + ['client_id' => User::factory()->create()->client()->id,
            'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => $price, 'quantity' => 1,
            'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => $expires])->fresh();
    }

    private function line(Service $service, ?string $start, ?string $end, float $price, string $status, array $more = []): InvoiceItem
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $line = $invoice->items()->create($more + ['description' => 'Service', 'price' => $price, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => $start, 'period_end' => $end]);
        if ($status !== Invoice::STATUS_UNPAID) {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status]);
        }

        return $line;
    }

    /**
     * S7-PLAN-CYCLE-ADDONS-1: a service set up before its order was paid (active, due on the order's day, its order's
     * line open: 120.00 for the year plus a 50.00 setup fee). The customer's change of cycle «from the next renewal»
     * cancels the order's invoice; the cron then bills a month at 12.00, and the setup fee is never billed again. An
     * upgrade is refused in this state (Service::upgradable, orderUnpaid); the change of cycle is not.
     */
    public function test_a_change_of_cycle_does_not_cancel_the_unpaid_order_invoice_with_its_setup_fee(): void
    {
        Carbon::setTestNow('2026-07-02 10:00:00');
        $product = Product::factory()->create(['name' => 'Hosting']);
        $yearly = $this->plan($product, 'year', 120.00);
        $monthly = $this->plan($product, 'month', 12.00);
        $service = $this->service($product, $yearly, 120.00, '2026-07-02');
        $order = Order::query()->create(['client_id' => $service->client_id, 'currency_code' => 'USD']);
        $service->update(['order_id' => $order->id]);
        $orderLine = $this->line($service, null, null, 170.00, Invoice::STATUS_UNPAID, ['setup_fee' => 50.00]);
        $service = $service->fresh();
        $this->assertTrue($service->orderUnpaid(), 'set up before its order was paid');

        // As the customer's page does (Livewire\Services\Show::changeCycle).
        $refusal = ChangeCycle::refusal($service, $monthly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);
        if ($refusal !== null) {
            $this->assertSame(Invoice::STATUS_UNPAID, $orderLine->invoice->fresh()->status);

            return; // refused: a right outcome
        }
        ChangeCycle::apply($service, $monthly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);

        $this->assertSame(Invoice::STATUS_UNPAID, $orderLine->invoice->fresh()->status,
            'the order\'s invoice (170.00, its 50.00 setup fee) is still owed');

        $this->artisan('app:cron-job');
        $owed = Invoice::query()->where('client_id', $service->client_id)->whereIn('status', Invoice::OPEN_STATUSES)->get()
            ->sum(fn (Invoice $invoice) => (float) $invoice->total);
        $this->assertGreaterThanOrEqual(50.00, $owed, 'the setup fee is still billed');
    }

    /**
     * S7-PLAN-CYCLE-ADDONS-2: monthly 12.00, paid 2026-06-10 → 2026-07-10; a change to yearly from the next renewal. The
     * cron bills the renewal on 2026-07-03 and puts the service on the yearly plan and price (120.00), its expiry still
     * 2026-07-10. An upgrade placed now is priced as the yearly difference (240 − 120) over the old monthly period's
     * days: 120 × 7/30 = 28.00 for 7 days, where the monthly difference gives 2.80 (it was 3.20 the day before, for 8).
     */
    public function test_an_upgrade_after_the_cron_applied_a_cycle_change_is_not_priced_by_the_new_cycle_over_the_old_period(): void
    {
        Carbon::setTestNow('2026-07-02 10:00:00');
        $product = Product::factory()->create(['name' => 'Hosting', 'server_id' => null, 'stock' => null]);
        $monthly = $this->plan($product, 'month', 12.00);
        $yearly = $this->plan($product, 'year', 120.00);
        $bigger = Product::factory()->create(['name' => 'Bigger', 'server_id' => null, 'stock' => null]);
        $biggerMonthly = $this->plan($bigger, 'month', 24.00);
        $this->plan($bigger, 'year', 240.00);
        DB::table('product_upgrades')->insert(['product_id' => $product->id, 'upgrade_id' => $bigger->id]);
        $service = $this->service($product, $monthly, 12.00, '2026-07-10');
        $this->line($service, '2026-06-10', '2026-07-10', 12.00, Invoice::STATUS_PAID);
        $service = $service->fresh();

        // Before the change: 8 of 30 days of 12.00.
        $before = new ServiceUpgrade(['service_id' => $service->id, 'product_id' => $bigger->id, 'plan_id' => $biggerMonthly->id]);
        $this->assertSame('3.20', UpgradeQuote::for($before->setRelation('service', $service))->dueNow);

        ChangeCycle::apply($service, $yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);
        Carbon::setTestNow('2026-07-03 10:00:00');
        $this->artisan('app:cron-job');
        $service = $service->fresh();
        $this->assertSame([$yearly->id, null, '2026-07-10'], [(int) $service->plan_id, $service->next_plan_id, $service->expires_at->toDateString()],
            'the cron applied the yearly plan when it billed the renewal; the monthly period paid still runs');

        $placed = PlaceUpgrade::handle($service, $bigger, [], ServiceUpgrade::SOURCE_CLIENT);
        if (!in_array($placed->outcome, [PlaceUpgrade::APPLIED, PlaceUpgrade::INVOICED], true)) {
            $this->assertSame(0, InvoiceItem::query()->where('reference_type', ServiceUpgrade::class)->count());

            return; // refused until the renewal is paid: a right outcome
        }
        $charged = (float) InvoiceItem::query()->where('reference_type', ServiceUpgrade::class)->where('reference_id', $placed->upgrade->id)->sum('price');
        $this->assertLessThanOrEqual(3.20, $charged,
            '7 days left of a month paid at 12.00: the dearer package costs 2.80 more for them, not the yearly difference × 7/30 (28.00)');
    }

    /**
     * S7-PLAN-CYCLE-ADDONS-3: an imported yearly service (no period line: paid in WHMCS), due 2026-08-01. A change of
     * cycle «now» credits its days left (9.86 on 2026-07-02). Once the cron has issued its first renewal here (an open
     * line from the expiry on), the same change credits 0.00: the days paid in WHMCS are ended today, billed again in the
     * new cycle, and nothing comes back.
     */
    public function test_an_imported_service_keeps_its_credit_for_days_paid_once_its_first_renewal_is_issued(): void
    {
        Carbon::setTestNow('2026-07-02 10:00:00');
        $product = Product::factory()->create(['name' => 'Hosting']);
        $yearly = $this->plan($product, 'year', 120.00);
        $monthly = $this->plan($product, 'month', 12.00);
        $service = $this->service($product, $yearly, 120.00, '2026-08-01');
        $this->assertSame('9.86', ChangeCycle::quote($service, $monthly, ChangeCycle::NOW)['credit'], '30 of 365 days of 120.00');

        Carbon::setTestNow('2026-07-26 10:00:00');
        $this->artisan('app:cron-job');
        $service = $service->fresh();
        $open = $service->livePeriodLines();
        $this->assertSame(['2026-08-01'], $open->map(fn (InvoiceItem $line) => $line->period_start->toDateString())->all(), 'its first renewal here, open');

        if (ChangeCycle::refusal($service, $monthly, ChangeCycle::NOW) !== null) {
            return; // refused until the renewal is settled: a right outcome
        }
        $this->assertSame('1.97', ChangeCycle::quote($service, $monthly, ChangeCycle::NOW)['credit'], '6 of 365 days of 120.00, paid in WHMCS');
        $invoice = ChangeCycle::apply($service, $monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);
        // Adapted with the fix (the assertions from here on never ran on main): the new period is billed, unpaid (no
        // credit auto-use, no saved method in this test), so the due date is today until it is paid; then a month on.
        $this->assertSame('2026-07-26', $service->fresh()->expires_at->toDateString());
        ExtensionHelper::addPayment($invoice->id, null, (float) $invoice->fresh()->total, null, 's7-pca-3', currency: 'USD');
        $this->assertSame('2026-08-26', $service->fresh()->expires_at->toDateString());
        $this->assertSame('1.97', number_format((float) Credit::query()->where('client_id', $service->client_id)->sum('amount'), 2, '.', ''));
    }
}
