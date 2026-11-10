<?php

namespace Tests\Feature;

use App\Livewire\Services\Upgrade;
use App\Models\Credit;
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
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Services\ServiceUpgrade\ChangeCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Independent review of the sweep 2 f-plan-change fixes. Each test fails on fix/sweep2-f-plan-change for the reason
 * given. Fake data only.
 */
class Rev2FPlanChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-01-01 10:00:00');
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        foreach (['credits_enabled', 'credits_on_downgrade'] as $key) {
            Setting::query()->whereNull('settingable_type')->updateOrCreate(['key' => $key], ['value' => '1', 'type' => 'boolean']);
        }
        Setting::query()->whereNull('settingable_type')->updateOrCreate(['key' => 'credits_auto_use'], ['value' => '0', 'type' => 'boolean']);
        config(['settings.credits_enabled' => true, 'settings.credits_on_downgrade' => true, 'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(Product $product, string $unit, string $price, string $setup = '0.00'): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => ucfirst($unit),
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => $setup, 'currency_code' => 'USD']);

        return $plan->fresh();
    }

    private function monthlyProduct(string $price): Product
    {
        $product = Product::factory()->create(['name' => 'P' . $price, 'server_id' => null, 'stock' => null]);
        $this->plan($product, 'month', $price);

        return $product->fresh();
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

    private function pay(Invoice $invoice, string $ref): void
    {
        (new RecordPaymentService)->handle($invoice->fresh(), null, (float) $invoice->fresh()->remaining, transactionId: $ref);
    }

    private function credit(): float
    {
        return round((float) Credit::query()->where('client_id', $this->user->client()->id)->sum('amount'), 2);
    }

    /**
     * REV2-PC-1: staff waive the setup fee on the unpaid order invoice by editing its line's price from 180.00 (year +
     * setup) to 120.00 (the year alone), the same price field F-PLAN-CHANGE-3 is about. `setup_fee` (60.00) stays on the
     * line, so PaidForPeriod counts only (120 - 60) / 120 of what was paid as money for the days: a cycle change «now»
     * gives back half of the unused year the customer paid for.
     */
    public function test_a_setup_fee_staff_waived_on_the_order_line_is_not_taken_off_the_unused_days(): void
    {
        $product = Product::factory()->create(['name' => 'Hosting', 'server_id' => null, 'stock' => null]);
        $yearly = $this->plan($product, 'year', '120.00', '60.00');
        $monthly = $this->plan($product, 'month', '12.00');

        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product->fresh(), $yearly->load('prices'))]);
        $line = $placed->invoice->items()->sole();
        $this->assertSame('180.00', number_format((float) $line->price, 2, '.', ''), 'precondition: year + setup fee on one line');

        // Staff waive the setup fee on the unpaid invoice (the price field of its lines).
        $line->update(['price' => '120.00']);
        $this->assertSame('120.00', number_format((float) $placed->invoice->fresh()->total, 2, '.', ''), 'precondition: 120.00 asked');
        $this->pay($placed->invoice, 'order-1');

        $service = $placed->order->services()->sole()->fresh();
        Carbon::setTestNow('2026-02-01 10:00:00');
        $quote = ChangeCycle::quote($service->fresh(), $monthly, ChangeCycle::NOW);

        // 120.00 paid for the year, no setup fee charged: 334 of 365 days left is 109.81.
        $this->assertSame('109.81', $quote['credit'], 'a setup fee that was never charged is still taken off the days left');
    }

    /**
     * REV2-PC-2: the upgrade is placed on Nov 16 (5.00 for Nov 16 → Dec 1), the renewal Dec 1 → Jan 1 is paid before it,
     * so its payment bills a shortfall for December (unpaid) and records paid_until = Jan 1. paidEnd now spreads the 5.00
     * that paid only Nov 16 → Dec 1 over Nov 16 → Jan 1 (46 days). A downgrade on Nov 25 gives back 5.00 × 37/46 = 4.02
     * of upgrade money where only 6 of its 15 paid days (2.00) are left: the shortfall that would pay December was never
     * paid, yet its days are credited out of November's money.
     */
    public function test_a_downgrade_after_an_unpaid_shortfall_was_cancelled_credits_only_the_days_the_upgrade_paid(): void
    {
        $p10 = $this->monthlyProduct('10.00');
        $p20 = $this->monthlyProduct('20.00');
        DB::table('product_upgrades')->insert([['product_id' => $p10->id, 'upgrade_id' => $p20->id], ['product_id' => $p20->id, 'upgrade_id' => $p10->id]]);
        Carbon::setTestNow('2026-11-16 10:00:00');
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'product_id' => $p10->id, 'plan_id' => $p10->plans()->first()->id,
            'status' => 'active', 'price' => '10.00', 'quantity' => 1, 'currency_code' => 'USD', 'expires_at' => '2026-12-01',
        ]);
        $this->paidLine($service, '10.00', '2026-11-01', '2026-12-01');
        $this->change($service, $p20);
        $upgrade = ServiceUpgrade::query()->where('service_id', $service->id)->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(5.00, (float) $upgrade->invoice->fresh()->total, 0.01, 'precondition: 15 of 30 days at +10');

        // The renewal Dec 1 → Jan 1, at the old price, paid before the upgrade's invoice.
        $this->paidLine($service, '10.00', '2026-12-01', '2027-01-01');
        DB::table('services')->where('id', $service->id)->update(['expires_at' => '2027-01-01']);
        $this->pay($upgrade->invoice()->firstOrFail(), 'upg-1');
        $shortfall = InvoiceItem::query()->where('description', 'like', '%renewal paid meanwhile%')->sole();
        $this->assertSame(Invoice::STATUS_UNPAID, $shortfall->invoice->status, 'precondition: December\'s shortfall is not paid');

        // Since 2026-11-10 (S6-PLAN-CYCLE-ADDONS-2) a downgrade is refused while that shortfall is open; staff cancel its
        // invoice, and the downgrade goes ahead: what it credits is still only the days the upgrade paid.
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->change($service, $p10);
        $this->assertSame((int) $p20->id, (int) $service->fresh()->product_id, 'refused while the shortfall is open');
        $this->staffCancelInvoice($shortfall->invoice, 'Downgraded instead');
        $this->change($service, $p10);
        $this->assertSame((int) $p10->id, (int) $service->fresh()->product_id, 'precondition: downgraded');

        // Paid at +10: Nov 16 → Dec 1 only (5.00). Left of it after Nov 25: 6 of 15 days, 2.00. December was paid at 10.
        $this->assertEqualsWithDelta(2.00, $this->credit(), 0.10, 'November\'s upgrade money is spread over December\'s unpaid days too');
    }
}
