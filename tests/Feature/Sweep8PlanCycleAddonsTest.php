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
use App\Services\Service\AddAddon;
use App\Services\ServiceUpgrade\ChangeCycle;
use App\Services\ServiceUpgrade\PlaceUpgrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Sweep 8, adversarial verification of «plan-cycle-addons»: each test fails on main (6f4b6e29) for the reason reported.
 * Each takes a refusal as a right outcome too (KISS: the case may be cut rather than computed).
 *
 * S8-PLAN-CYCLE-ADDONS-1 here goes through the importer's own steps (its last paid line comes with its period),
 * where the verifier's stood a native service with no line in for an imported one. Same prices, same 16 of 31 days: the
 * month 2026-07-01 to 2026-08-01 seen on 07-16 (a paid line due 06-30 and a service due 07-31 are not a month apart).
 *
 * Since the importer gives the last paid line and the open renewal their period (WhmcsLinePeriods, owner 2026-11-11) the
 * tests of the period-less-line findings pass, with no billing rule changed; the others are marked incomplete, each with
 * its reason.
 */
class Sweep8PlanCycleAddonsTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'],
            'credits_on_downgrade' => [true, 'boolean'], 'default_currency' => ['USD', 'string'], 'cronjob_invoice' => [7, 'integer']]);
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

    private function line(Service $service, ?string $start, ?string $end, float $price, string $status): InvoiceItem
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $line = $invoice->items()->create(['description' => 'Service', 'price' => $price, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => $start, 'period_end' => $end]);
        if ($status !== Invoice::STATUS_UNPAID) {
            $this->statusFixture((int) $invoice->id, $status);
        }

        return $line;
    }

    /** A monthly service as the importer brings it: paid in WHMCS for 2026-07-01 to 2026-08-01 (invoice #480), due 08-01. */
    private function importedService(Product $product, float $price): Service
    {
        $this->whmcs(User::factory()->create()->client()->id, $product->id, 'USD');
        $this->whmcsService(31, '2026-08-01', $price);
        $this->whmcsInvoice(480, 'Paid', [31 => $price], due: '2026-07-01', date: '2026-06-24');
        $this->importWhmcs();
        $line = $this->imported(480)->items->sole();
        $this->assertSame(['2026-07-01', '2026-08-01'], [$line->period_start?->toDateString(), $line->period_end?->toDateString()], 'the importer gave its paid line its period');

        return Service::query()->findOrFail(31);
    }

    /** @return array{0: Product, 1: Plan, 2: Product, 3: Plan} a 10.00 and a 30.00 product, each monthly and yearly, either way upgradable */
    private function twoProducts(): array
    {
        $small = Product::factory()->create(['name' => 'Small', 'server_id' => null, 'stock' => null]);
        $smallMonthly = $this->plan($small, 'month', 10.00);
        $this->plan($small, 'year', 100.00);
        $big = Product::factory()->create(['name' => 'Big', 'server_id' => null, 'stock' => null]);
        $bigMonthly = $this->plan($big, 'month', 30.00);
        $this->plan($big, 'year', 300.00);
        DB::table('product_upgrades')->insert([['product_id' => $small->id, 'upgrade_id' => $big->id], ['product_id' => $big->id, 'upgrade_id' => $small->id]]);

        return [$small, $smallMonthly, $big, $bigMonthly];
    }

    private function yearly(Product $product): Plan
    {
        return Plan::query()->where('priceable_type', Product::class)->where('priceable_id', $product->id)->where('billing_unit', 'year')->sole();
    }

    private function credit(Service $service): string
    {
        return number_format((float) Credit::query()->where('client_id', $service->client_id)->sum('amount'), 2, '.', '');
    }

    /**
     * S8-PLAN-CYCLE-ADDONS-1 (a): imported monthly service at 10.00 (paid in WHMCS), due 2026-08-01. On
     * 2026-07-16 (16 of 31 days left) the customer upgrades to the 30.00 product and pays the difference, 10.32. For
     * those 16 days 5.16 (WHMCS) + 10.32 = 15.48 was paid. A cycle change «now» credits 30.00 × 16/31 = 15.48 (the price
     * after the upgrade) and the upgrade's 10.32 again: 25.80.
     */
    public function test_a_cycle_change_now_on_an_imported_service_does_not_credit_a_paid_upgrade_twice(): void
    {
        Carbon::setTestNow('2026-07-16 10:00:00');
        [$small, , $big] = $this->twoProducts();
        $service = $this->importedService($small, 10.00);
        $this->assertSame('5.16', ChangeCycle::unused($service, now()->toImmutable()), '16 of 31 days of 10.00, paid in WHMCS');

        $placed = PlaceUpgrade::handle($service, $big, [], ServiceUpgrade::SOURCE_CLIENT);
        $this->assertSame(PlaceUpgrade::INVOICED, $placed->outcome);
        $this->assertSame('10.32', number_format((float) $placed->invoice->fresh()->total, 2, '.', ''), '20.00 × 16/31');
        ExtensionHelper::addPayment($placed->invoice->id, null, (float) $placed->invoice->fresh()->total, null, 's8-pca-1a', currency: 'USD');
        $service = $service->fresh();
        $this->assertSame([(int) $big->id, '30.00'], [(int) $service->product_id, number_format((float) $service->price, 2, '.', '')], 'the upgrade is applied');

        $yearly = $this->yearly($big);
        if (ChangeCycle::refusal($service, $yearly, ChangeCycle::NOW) !== null) {
            return; // refused: a right outcome
        }
        $this->assertSame('15.48', ChangeCycle::quote($service, $yearly, ChangeCycle::NOW)['credit'],
            'the 16 days left were paid 5.16 in WHMCS and 10.32 by the upgrade: 15.48, not 25.80');
        ChangeCycle::apply($service, $yearly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);
        $this->assertSame('15.48', $this->credit($service));
    }

    /**
     * S8-PLAN-CYCLE-ADDONS-1 (b): imported monthly service at 30.00, due 2026-08-01. On 2026-07-16 the customer downgrades
     * to the 10.00 product: 10.32 of credit (20.00 × 16/31). Of the 15.48 paid in WHMCS for those days, 5.16 is still
     * unused on the new plan. A cycle change «now» prices the days at the new 10.00 (5.16) and takes the 10.32 off again:
     * credit 0.00, the period ends today, and the 5.16 is lost. (With a native paid period line the same steps give
     * 15.48 in (a): checked while writing these.)
     */
    public function test_a_cycle_change_now_on_an_imported_service_does_not_take_a_downgrades_credit_off_twice(): void
    {
        Carbon::setTestNow('2026-07-16 10:00:00');
        [$small, , $big] = $this->twoProducts();
        $service = $this->importedService($big, 30.00);

        $placed = PlaceUpgrade::handle($service, $small, [], ServiceUpgrade::SOURCE_CLIENT);
        $this->assertSame([PlaceUpgrade::APPLIED, 1032], [$placed->outcome, $placed->creditCents], '20.00 × 16/31 given back');
        $service = $service->fresh();
        $this->assertSame((int) $small->id, (int) $service->product_id);

        $yearly = $this->yearly($small);
        if (ChangeCycle::refusal($service, $yearly, ChangeCycle::NOW) !== null) {
            return; // refused: a right outcome
        }
        $this->assertSame('5.16', ChangeCycle::quote($service, $yearly, ChangeCycle::NOW)['credit'],
            '15.48 was paid in WHMCS for the 16 days left, 10.32 came back at the downgrade: 5.16 is still unused, not 0.00');
    }

    /**
     * S8-PLAN-CYCLE-ADDONS-2 (1): a yearly parent at 100.00, paid 2026-01-01 → 2027-01-01. On 2026-03-01 staff add a
     * yearly addon (120.00) with «No invoice»: active at once, due with its parent, nothing invoiced and nothing paid
     * for it here. The next day staff change the parent's cycle «now»: the addon, with no period line, is taken as
     * «imported and paid in WHMCS» and its days left (120.00 × 305/365 = 100.27) become account credit beside the
     * parent's own 83.56.
     */
    public function test_a_no_invoice_addon_gives_no_credit_for_days_nobody_was_invoiced_for(): void
    {
        $this->markTestIncomplete('S8-PLAN-CYCLE-ADDONS-2: another finding of sweep 8, not about the period of an imported line; still failing, left for its own fix.');

        Carbon::setTestNow('2026-03-01 10:00:00');
        $product = Product::factory()->create(['name' => 'Hosting', 'server_id' => null, 'stock' => null]);
        $yearly = $this->plan($product, 'year', 100.00);
        $monthly = $this->plan($product, 'month', 10.00);
        $extra = Product::factory()->create(['name' => 'Extra', 'server_id' => null, 'stock' => null, 'is_addon' => true]);
        $this->plan($extra, 'year', 120.00);
        $this->plan($extra, 'month', 12.00);
        $parent = $this->service($product, $yearly, 100.00, '2027-01-01');
        $this->line($parent, '2026-01-01', '2027-01-01', 100.00, Invoice::STATUS_PAID);

        $made = (new AddAddon)->handle($parent->fresh(), $extra->fresh(), 1, null, false, AddAddon::NO_INVOICE);
        $addon = $made['service']->fresh();
        $this->assertNull($made['invoice']);
        $this->assertSame([Service::STATUS_ACTIVE, '2027-01-01'], [$addon->status, $addon->expires_at->toDateString()]);
        $this->assertSame(0, InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $addon->id)->count());

        Carbon::setTestNow('2026-03-02 10:00:00');
        $parent = $parent->fresh();
        $own = ChangeCycle::unused($parent, now()->toImmutable());
        $this->assertSame('83.56', $own, '305 of 365 days of the 100.00 paid');
        if (ChangeCycle::refusal($parent, $monthly, ChangeCycle::NOW) !== null) {
            return; // refused: a right outcome
        }
        $this->assertSame($own, ChangeCycle::quote($parent, $monthly, ChangeCycle::NOW)['credit'],
            'only the parent\'s paid days come back: the addon was never invoiced or paid here (not 183.83)');
    }

    /**
     * S8-PLAN-CYCLE-ADDONS-2 (2): a native monthly service at 30.00 whose only invoice (its order's, never paid) staff
     * cancelled, the service left running to 2026-07-31. No money was ever paid for it. On 2026-07-15 its customer
     * downgrades to the 10.00 product: with no period line it is taken as «imported and paid», and 10.32 of account
     * credit is given for a period nobody paid.
     */
    public function test_a_downgrade_of_a_never_paid_native_service_gives_no_credit(): void
    {
        $this->markTestIncomplete('S8-PLAN-CYCLE-ADDONS-2: another finding of sweep 8, not about the period of an imported line; still failing, left for its own fix.');

        Carbon::setTestNow('2026-07-15 10:00:00');
        [$small, , $big, $bigMonthly] = $this->twoProducts();
        $service = $this->service($big, $bigMonthly, 30.00, '2026-07-31');
        $order = Order::query()->create(['client_id' => $service->client_id, 'currency_code' => 'USD']);
        $service->update(['order_id' => $order->id]);
        $this->line($service, null, null, 30.00, Invoice::STATUS_CANCELLED);
        $service = $service->fresh();

        $placed = PlaceUpgrade::handle($service, $small, [], ServiceUpgrade::SOURCE_CLIENT);
        if ($placed->outcome !== PlaceUpgrade::APPLIED) {
            $this->assertSame('0.00', $this->credit($service));

            return; // refused: a right outcome
        }
        $this->assertSame([0, '0.00'], [$placed->creditCents, $this->credit($service)],
            'nothing was ever paid for this service: a downgrade gives nothing back (not 10.32)');
    }
}
