<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Review of the sweep 3 fix for L-ROUNDING-TAX-1 (Service::refreshOpenPeriods skips the order's own line).
 *
 * The skip is in refreshOpenPeriods, so it covers every caller, not only a staff plan_id change: ServiceUpgradeService
 * calls it with retax on a product change. A pro rata service that runs with its order invoice still open (staff
 * accepted the order before payment) and is upgraded to another product keeps its open order line at the old tax, and
 * nothing tells staff: the same order line of a non-pro-rata product (no period) is retaxed by the upgrade's
 * periodless-line step, and a kept line is reported by tellOfKeptPeriods (C1/C2 of #695).
 */
class Rev3LRoundingTaxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        $this->storeSettings(['credits_enabled' => true, 'credits_auto_use' => false, 'default_currency' => 'USD',
            'tax_enabled' => true, 'tax_type' => 'exclusive', 'eu_vat_enabled' => false]);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'GR']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_upgrade_to_an_untaxed_product_retaxes_or_reports_the_open_pro_rata_order_line(): void
    {
        $user = User::factory()->create();
        $user->client()->primaryContact->update(['country' => 'GR']);
        /** @var Product $product */
        $product = $this->createProduct(['prorata_day' => 1, 'prorata_next_after_day' => 20])->product;
        $plan = $product->plans()->firstOrFail();
        Price::query()->where('plan_id', $plan->id)->update(['price' => 30.00, 'setup_fee' => 0]);

        $untaxed = Product::factory()->create(['name' => 'Untaxed', 'server_id' => null, 'stock' => null, 'taxable' => false]);
        $untaxedPlan = Plan::factory()->create(['priceable_id' => $untaxed->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $untaxedPlan->id, 'price' => 30.00, 'setup_fee' => 0, 'currency_code' => 'USD']);

        Carbon::setTestNow('2026-01-10 10:00:00');
        $placed = (new PlaceOrderService)->place($user->client(), 'USD', [new OrderLine($product, $plan)]);
        $line = $placed->invoice->fresh('items')->items->sole();
        $this->assertSame('24.0000', (string) $line->tax_rate);
        $this->assertNotNull($line->period_end, 'a pro rata order line has its period');

        // Staff accept the order before it is paid: it runs, its order invoice still open.
        $service = Service::query()->sole();
        $service->forceFill(['status' => Service::STATUS_ACTIVE])->save();

        $upgrade = (new ServiceUpgrade)->forceFill(['service_id' => $service->id, 'product_id' => $untaxed->id,
            'plan_id' => $untaxedPlan->id, 'status' => ServiceUpgrade::STATUS_PENDING]);
        $upgrade->save();
        $this->assertTrue((new ServiceUpgradeService)->handle($upgrade));

        $line->refresh();
        $this->assertSame(Invoice::STATUS_UNPAID, $line->invoice->status);
        $this->assertSame('0.0000', number_format((float) $line->tax_rate, 4, '.', ''),
            'the open order line of a service moved to an untaxed product keeps 24% VAT, silently (no staff report)');
    }

    /**
     * Fixer: an upgrade at another price keeps the stub (its price and period, as a plan change by staff does), and staff
     * are told of the stub's price at the new recurring amount, with the invoice; the line is retaxed only on a product change.
     */
    public function test_fix_an_upgrade_at_another_price_keeps_the_order_stub_and_tells_staff(): void
    {
        $this->storeSettings(['system_email_address' => 'staff@example.test', 'mail_disable' => false]);
        $user = User::factory()->create();
        $user->client()->primaryContact->update(['country' => 'GR']);
        /** @var Product $product */
        $product = $this->createProduct(['prorata_day' => 1, 'prorata_next_after_day' => 20])->product;
        $plan = $product->plans()->firstOrFail();
        Price::query()->where('plan_id', $plan->id)->update(['price' => 30.00, 'setup_fee' => 0]);
        $pricier = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Pricier',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $pricier->id, 'price' => 40.00, 'setup_fee' => 0, 'currency_code' => 'USD']);

        Carbon::setTestNow('2026-01-10 10:00:00');
        $placed = (new PlaceOrderService)->place($user->client(), 'USD', [new OrderLine($product, $plan)]);
        $line = $placed->invoice->fresh('items')->items->sole();
        // 22 of 31 days: 21.29 of 30.00, 28.39 of 40.00.
        $this->assertSame(['21.29', '24.0000'], [number_format((float) $line->price, 2, '.', ''), number_format((float) $line->tax_rate, 4, '.', '')]);
        $service = Service::query()->sole();
        $service->forceFill(['status' => Service::STATUS_ACTIVE])->save();

        $upgrade = (new ServiceUpgrade)->forceFill(['service_id' => $service->id, 'product_id' => $product->id,
            'plan_id' => $pricier->id, 'status' => ServiceUpgrade::STATUS_PENDING]);
        $upgrade->save();
        $this->assertTrue((new ServiceUpgradeService)->handle($upgrade));

        $line->refresh();
        $this->assertSame(['21.29', '2026-02-01', '24.0000'],
            [number_format((float) $line->price, 2, '.', ''), $line->period_end->toDateString(), number_format((float) $line->tax_rate, 4, '.', '')]);
        $told = EmailLog::query()->where('subject', 'like', '%needs staff attention%')->get();
        $this->assertCount(1, $told);
        $this->assertStringContainsString("invoice #{$placed->invoice->id}, period 10/01/2026 - 01/02/2026: 7.10 USD", (string) $told->sole()->body);
    }
}
