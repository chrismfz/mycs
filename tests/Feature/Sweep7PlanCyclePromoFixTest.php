<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Services\Cancel;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\User;
use App\Services\Money\MoneyTrail;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Services\ServiceUpgrade\ChangeCycle;
use App\Services\ServiceUpgrade\PlaceUpgrade;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Sweep 7, the fixes of «plan-cycle-addons» and «client-portal» (S7-PLAN-CYCLE-ADDONS-1..3, S7-CLIENT-PORTAL-1): each
 * rule, and that staff can still do what the customer is refused. Fake data only.
 */
class Sweep7PlanCyclePromoFixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'],
            'default_currency' => ['USD', 'string'], 'cronjob_invoice' => [7, 'integer']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    /** A yearly service set up before its order was paid: active, due on the order's day, the order's line (with its setup fee) open. */
    private function setUpUnpaid(): array
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
        $this->assertTrue($service->orderUnpaid());

        return [$service, $monthly, $orderLine];
    }

    // --- S7-PLAN-CYCLE-ADDONS-1 ---

    public function test_the_customer_is_told_to_pay_the_first_invoice_before_a_change_of_cycle(): void
    {
        [$service, $monthly, $orderLine] = $this->setUpUnpaid();

        $this->assertSame(__('services.change_cycle_order_unpaid'), ChangeCycle::refusal($service, $monthly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM));
        try {
            ChangeCycle::apply($service, $monthly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);
            $this->fail('refused for the customer');
        } catch (DisplayException $e) {
            $this->assertSame(__('services.change_cycle_order_unpaid'), $e->getMessage());
        }
        $this->assertNull($service->fresh()->next_plan_id);
        $this->assertSame(Invoice::STATUS_UNPAID, $orderLine->invoice->fresh()->status);
        foreach (['en', 'el'] as $locale) {
            $this->assertNotSame('services.change_cycle_order_unpaid', __('services.change_cycle_order_unpaid', [], $locale));
        }
    }

    public function test_staff_change_the_cycle_of_a_service_with_its_order_unpaid_and_the_orders_invoice_stays(): void
    {
        [$service, $monthly, $orderLine] = $this->setUpUnpaid();

        $this->assertNull(ChangeCycle::refusal($service, $monthly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF));
        ChangeCycle::apply($service, $monthly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF);

        $this->assertSame($monthly->id, (int) $service->fresh()->next_plan_id, 'staff set the change');
        $invoice = $orderLine->invoice->fresh();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status, 'the order\'s invoice is not a renewal: never cancelled by a change of cycle');
        $this->assertSame('170.00', number_format((float) $invoice->total, 2, '.', ''), 'its setup fee still on it');
        $this->assertSame('50.00', number_format((float) $orderLine->fresh()->setup_fee, 2, '.', ''));
    }

    public function test_a_change_of_cycle_cancels_the_open_renewal_and_leaves_an_open_charge_of_the_service(): void
    {
        Carbon::setTestNow('2026-07-05 10:00:00');
        $product = Product::factory()->create(['name' => 'Hosting']);
        $yearly = $this->plan($product, 'year', 120.00);
        $monthly = $this->plan($product, 'month', 12.00);
        $service = $this->service($product, $monthly, 12.00, '2026-07-10');
        $this->line($service, '2026-06-10', '2026-07-10', 12.00, Invoice::STATUS_PAID);
        $renewal = $this->line($service, '2026-07-10', '2026-08-10', 12.00, Invoice::STATUS_UNPAID);
        // A charge of the service on its own invoice (an imported setup fee, #507): no period, not billed again.
        $charge = $this->line($service, null, null, 30.00, Invoice::STATUS_UNPAID, ['kind' => InvoiceItem::KIND_CHARGE]);

        ChangeCycle::apply($service->fresh(), $yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);

        $this->assertSame(Invoice::STATUS_CANCELLED, $renewal->invoice->fresh()->status, 'the renewal of the old cycle goes: the cron bills it again');
        $this->assertSame(Invoice::STATUS_UNPAID, $charge->invoice->fresh()->status, 'the charge is still owed');
        $this->assertSame($yearly->id, (int) $service->fresh()->next_plan_id);
    }
}
