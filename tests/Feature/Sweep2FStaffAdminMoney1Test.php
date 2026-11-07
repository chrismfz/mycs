<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\ChangeCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial verification, flow f-staff-admin-money (sweep 2): F-STAFF-ADMIN-MONEY-1, fixed with f-plan-change (the same
 * credit code). Its F-STAFF-ADMIN-MONEY-2 test stays with that flow.
 */
class Sweep2FStaffAdminMoney1Test extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Plan $yearly;

    private Plan $monthly;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-01-01 10:00:00');
        Queue::fake();
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']]);
        $this->product = Product::factory()->create(['name' => 'Hosting']);
        $this->yearly = $this->plan('Yearly', 'year', 120.00);
        $this->monthly = $this->plan('Monthly', 'month', 12.00);
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

    private function plan(string $name, string $unit, float $price): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $this->product->id, 'priceable_type' => Product::class, 'name' => $name,
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $plan->fresh();
    }

    /** A yearly service and its unpaid renewal 2026-01-01 -> 2027-01-01 (120.00). */
    private function serviceWithRenewal(): array
    {
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id,
            'product_id' => $this->product->id, 'plan_id' => $this->yearly->id, 'price' => 120.00, 'quantity' => 1,
            'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-01-01']);
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Renewal', 'price' => 120.00, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => '2026-01-01', 'period_end' => '2027-01-01']);

        return [$service->fresh(), $invoice->fresh()];
    }

    private function creditCents(Service $service): int
    {
        return RecordPaymentService::toCents((float) (Credit::query()->where('client_id', $service->client_id)->value('amount') ?? 0));
    }

    /**
     * F-STAFF-ADMIN-MONEY-1: the renewal is paid 120.00, staff refund 100.00 of it, then change the cycle «now» halfway
     * through the year. The customer kept 20.00 for the year; the unused half of it is about 10.00. The credit is worked
     * out from the line's 120.00 instead: about 60.00, more than the customer kept at all.
     */
    public function test_cycle_change_now_credits_from_line_total_ignoring_a_partial_refund(): void
    {
        [$service, $invoice] = $this->serviceWithRenewal();
        $payment = (new RecordPaymentService)->recordByStaff($invoice, null, 120.00, 'TX-1');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('2027-01-01', $service->fresh()->expires_at->toDateString());

        ExtensionHelper::refundLocally($payment->fresh(), 100.00, notify: false);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'a partial refund leaves it paid');

        Carbon::setTestNow('2026-07-02 10:00:00');
        ChangeCycle::apply($service->fresh(), $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $credit = $this->creditCents($service);
        $this->assertLessThanOrEqual(2000, $credit,
            "the customer kept 20.00 of the payment, yet a cycle change gave {$credit} cents of credit for the unused days");
    }
}
