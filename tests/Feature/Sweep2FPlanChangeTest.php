<?php

namespace Tests\Feature;

use App\Livewire\Services\Upgrade;
use App\Models\AdjustmentNote;
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
use App\Services\Service\AddAddon;
use App\Services\ServiceUpgrade\ChangeCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial verification of flow f-plan-change (sweep 2): a cycle change «now», a downgrade's credit, and the
 * upgrade's shortfall. Each test fails on main for the reason reported. Fake data only.
 */
class Sweep2FPlanChangeTest extends TestCase
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

    private function upgradeInvoice(Service $service): Invoice
    {
        return ServiceUpgrade::query()->where('service_id', $service->id)->latest('id')->firstOrFail()->invoice()->firstOrFail();
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
     * F-PLAN-CHANGE-1: the order's line holds the first year and the setup fee. Paid, it is the period's line; a cycle
     * change «now» pays back the days left of the whole line, setup fee included.
     */
    public function test_a_cycle_change_now_does_not_pay_back_the_setup_fee(): void
    {
        $product = Product::factory()->create(['name' => 'Hosting', 'server_id' => null, 'stock' => null]);
        $yearly = $this->plan($product, 'year', '120.00', '60.00');
        $monthly = $this->plan($product, 'month', '12.00');

        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product->fresh(), $yearly->load('prices'))]);
        $this->assertSame('180.00', number_format((float) $placed->invoice->fresh()->total, 2, '.', ''), 'precondition: year + setup fee on the order');
        $this->pay($placed->invoice, 'order-1');

        $service = $placed->order->services()->sole()->fresh();
        $line = $service->invoiceItems()->sole();
        $this->assertSame('2026-01-01', $line->period_start->toDateString(), 'precondition: the order line is the paid period');
        $this->assertSame('2027-01-01', $line->period_end->toDateString());

        Carbon::setTestNow('2026-02-01 10:00:00');
        $quote = ChangeCycle::quote($service->fresh(), $monthly, ChangeCycle::NOW);

        // 334 of 365 days left of the 120.00 year: 109.81. The 60.00 setup fee is not money for the days left.
        $this->assertSame('109.81', $quote['credit'], 'the one-time setup fee comes back as credit (164.71 = 180 × 334/365)');
    }

    /**
     * F-PLAN-CHANGE-2: the upgrade is charged to the expiry, a renewal paid ahead included, but its money is spread only to
     * the end of the period it was placed in. A downgrade in the renewal gives nothing back for the days paid at the
     * bigger price.
     */
    public function test_a_downgrade_in_a_renewal_paid_ahead_gives_back_the_upgrade_paid_for_it(): void
    {
        $p10 = $this->monthlyProduct('10.00');
        $p20 = $this->monthlyProduct('20.00');
        DB::table('product_upgrades')->insert([['product_id' => $p10->id, 'upgrade_id' => $p20->id], ['product_id' => $p20->id, 'upgrade_id' => $p10->id]]);

        Carbon::setTestNow('2026-11-25 10:00:00');
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'product_id' => $p10->id, 'plan_id' => $p10->plans()->first()->id,
            'status' => 'active', 'price' => '10.00', 'quantity' => 1, 'currency_code' => 'USD', 'expires_at' => '2027-01-01',
        ]);
        $this->paidLine($service, '10.00', '2026-11-01', '2026-12-01');
        $this->paidLine($service, '10.00', '2026-12-01', '2027-01-01'); // the renewal, paid ahead

        $this->change($service, $p20);
        $invoice = $this->upgradeInvoice($service);
        $paid = (float) $invoice->fresh()->total;
        $this->assertEqualsWithDelta(11.94, $paid, 0.02, 'precondition: the upgrade charged Nov 25 → Jan 1 (37 days)');
        $this->pay($invoice, 'upg-1');
        $this->assertSame((int) $p20->id, (int) $service->fresh()->product_id, 'precondition: upgraded');

        Carbon::setTestNow('2026-12-15 10:00:00');
        $this->change($service, $p10);
        $this->assertSame((int) $p10->id, (int) $service->fresh()->product_id, 'precondition: downgraded');

        // 17 days of Dec 15 → Jan 1 were paid at 20 (10 of the renewal, 10 × 17/31 of the upgrade): 5.48 comes back.
        $this->assertEqualsWithDelta(5.48, $this->credit(), 0.02, 'the upgrade money for the renewal\'s days left is lost');
    }

    /**
     * F-PLAN-CHANGE-3: staff lower the price of the upgrade's line on its unpaid invoice; the customer pays it the same day.
     * The payment bills the difference again, as «the days of a renewal paid meanwhile», though no renewal was paid.
     */
    public function test_a_staff_lowered_upgrade_line_is_not_billed_back_as_a_shortfall(): void
    {
        $p10 = $this->monthlyProduct('10.00');
        $p20 = $this->monthlyProduct('20.00');
        DB::table('product_upgrades')->insert(['product_id' => $p10->id, 'upgrade_id' => $p20->id]);

        Carbon::setTestNow('2026-11-16 10:00:00');
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'product_id' => $p10->id, 'plan_id' => $p10->plans()->first()->id,
            'status' => 'active', 'price' => '10.00', 'quantity' => 1, 'currency_code' => 'USD', 'expires_at' => '2026-12-01',
        ]);
        $this->paidLine($service, '10.00', '2026-11-01', '2026-12-01');

        $this->change($service, $p20);
        $invoice = $this->upgradeInvoice($service);
        $line = $invoice->items()->where('reference_type', ServiceUpgrade::class)->sole();
        $this->assertEqualsWithDelta(5.00, (float) $line->price, 0.01, 'precondition: 15 of 30 days at +10');

        // Staff lower it on the unpaid invoice (the price field of its lines), as a gesture.
        $line->update(['price' => '3.00']);
        $this->assertSame('3.00', number_format((float) $invoice->fresh()->total, 2, '.', ''), 'precondition: the invoice asks 3.00');
        $before = Invoice::query()->count();

        $this->pay($invoice, 'upg-1');

        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, ServiceUpgrade::query()->where('service_id', $service->id)->sole()->status);
        $billed = InvoiceItem::query()->where('description', 'like', '%renewal paid meanwhile%')->pluck('price')->all();
        $this->assertSame([], $billed, 'no renewal was paid: nothing more to bill');
        $this->assertSame($before, Invoice::query()->count());
    }

    // ------------------------------------------------------------------ the fixer's tests (what else the fixes changed)

    private function upgraded(Service $service, Product $to): ServiceUpgrade
    {
        $this->change($service, $to);

        return ServiceUpgrade::query()->where('service_id', $service->id)->latest('id')->firstOrFail();
    }

    private function monthlyService(Product $product, string $expires): Service
    {
        return Service::factory()->create([
            'client_id' => $this->user->client()->id, 'product_id' => $product->id, 'plan_id' => $product->plans()->first()->id,
            'status' => 'active', 'price' => '10.00', 'quantity' => 1, 'currency_code' => 'USD', 'expires_at' => $expires,
        ]);
    }

    /** F-PLAN-CHANGE-3, the cron half: a line staff priced stays theirs; one at the price the system put on it follows the days. */
    public function test_the_cron_leaves_a_staff_priced_upgrade_line_and_reprices_its_own(): void
    {
        $p10 = $this->monthlyProduct('10.00');
        $p20 = $this->monthlyProduct('20.00');
        DB::table('product_upgrades')->insert(['product_id' => $p10->id, 'upgrade_id' => $p20->id]);
        Carbon::setTestNow('2026-11-16 10:00:00');
        $mine = $this->monthlyService($p10, '2026-12-01');
        $theirs = $this->monthlyService($p10, '2026-12-01');
        $own = $this->upgraded($mine, $p20);
        $staff = $this->upgraded($theirs, $p20);
        $this->assertSame('5.00', (string) $own->charged, 'the price put on its line is kept with it');
        $staffLine = $staff->invoice->items()->where('reference_type', ServiceUpgrade::class)->sole();
        $staffLine->update(['price' => '3.00']);

        Carbon::setTestNow('2026-11-21 03:00:00');
        $this->artisan('app:cron-job');

        $ownLine = $own->invoice->items()->where('reference_type', ServiceUpgrade::class)->sole();
        $this->assertLessThan(5.00, (float) $ownLine->price, 'its own line follows the days left');
        $this->assertSame((string) $ownLine->price, (string) $own->fresh()->charged, 'and what it was put at follows it');
        $this->assertSame('3.00', (string) $staffLine->fresh()->price, 'a staff price is never raised back');
        $this->assertSame('5.00', (string) $staff->fresh()->charged);
    }

    /** F-PLAN-CHANGE-3: with a staff-lowered line, a renewal really paid meanwhile still bills its days, and only them. */
    public function test_a_renewal_paid_meanwhile_still_bills_its_days_after_a_staff_lowered_line(): void
    {
        $p10 = $this->monthlyProduct('10.00');
        $p20 = $this->monthlyProduct('20.00');
        DB::table('product_upgrades')->insert(['product_id' => $p10->id, 'upgrade_id' => $p20->id]);
        Carbon::setTestNow('2026-11-16 10:00:00');
        $service = $this->monthlyService($p10, '2026-12-01');
        $this->paidLine($service, '10.00', '2026-11-01', '2026-12-01');
        $upgrade = $this->upgraded($service, $p20);
        $upgrade->invoice->items()->where('reference_type', ServiceUpgrade::class)->sole()->update(['price' => '3.00']);
        // The renewal Dec 1 → Jan 1 paid before the upgrade's invoice (its 10.00): the expiry moves on.
        $this->paidLine($service, '10.00', '2026-12-01', '2027-01-01');
        DB::table('services')->where('id', $service->id)->update(['expires_at' => '2027-01-01']);

        $this->pay($upgrade->invoice()->firstOrFail(), 'upg-2');

        $billed = InvoiceItem::query()->where('description', 'like', '%renewal paid meanwhile%')->pluck('price')->all();
        $this->assertCount(1, $billed, 'the renewal paid meanwhile is billed');
        // December at about +10 (the days counted as the upgrade counts them), never with the 2.00 staff took off November.
        $this->assertEqualsWithDelta(10.00, (float) $billed[0], 0.25);
        $this->assertSame('2027-01-01', $upgrade->fresh()->paid_until->toDateString(), 'its money paid up to the expiry then');
    }

    /** F-PLAN-CHANGE-1: an addon's first line holds its setup fee too; it is kept apart from the period's price. */
    public function test_an_addons_first_line_keeps_its_setup_fee_apart(): void
    {
        $parent = $this->monthlyService($this->monthlyProduct('10.00'), '2026-12-01');
        $product = Product::factory()->create(['name' => 'Extra IPv4', 'is_addon' => true, 'server_id' => null, 'stock' => null]);
        $plan = $this->plan($product, 'month', '3.00', '2.00');

        $made = (new AddAddon)->handle($parent->fresh(), $product->fresh(), $plan, 1, null, false, AddAddon::OWN_DATE, Carbon::parse('2026-01-01'));

        $line = $made['invoice']->items()->sole();
        $this->assertSame(['5.00', '2.00'], [(string) $line->price, (string) $line->setup_fee]);
    }

    /** F-STAFF-ADMIN-MONEY-1, the downgrade half: a staff credit note on the period's invoice is not given back again. */
    public function test_a_downgrade_does_not_give_back_what_a_credit_note_took_off_the_period(): void
    {
        $p10 = $this->monthlyProduct('10.00');
        $p20 = $this->monthlyProduct('20.00');
        DB::table('product_upgrades')->insert(['product_id' => $p20->id, 'upgrade_id' => $p10->id]);
        Carbon::setTestNow('2026-11-16 10:00:00');
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'product_id' => $p20->id, 'plan_id' => $p20->plans()->first()->id,
            'status' => 'active', 'price' => '20.00', 'quantity' => 1, 'currency_code' => 'USD', 'expires_at' => '2026-12-01',
        ]);
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Period', 'price' => '20.00', 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => '2026-11-01', 'period_end' => '2026-12-01']);
        AdjustmentNote::query()->create(['invoice_id' => $invoice->id, 'type' => 'credit', 'amount' => '-10.00', 'description' => 'Goodwill']);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        $this->assertSame('10.00', number_format((float) $invoice->fresh()->total, 2, '.', ''), 'precondition: 10.00 paid for the month');

        $this->change($service, $p10);

        // 10.00 paid for 30 days: 5.00 left, and the 15 days kept cost 5.00 on the new plan. Nothing comes back.
        $this->assertSame((int) $p10->id, (int) $service->fresh()->product_id, 'precondition: downgraded');
        $this->assertSame(0.0, $this->credit());
    }
}
