<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\ServiceUpgrade\ChangeCycle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sweep 4, lens e-payment-credit, the reviewer's tests of the fixes (E-PAYMENT-CREDIT-1/2). Before: the fixer's tests: the cycle change «now» side of E-PAYMENT-CREDIT-1, the «imported
 * without its lines» fallback it must keep, and the affiliate reward once per invoice (E-PAYMENT-CREDIT-2) that still
 * rewards each invoice of its own.
 */
class Rev4EPaymentCreditTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Plan $yearly;

    private Plan $monthly;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-02 10:00:00');
        Queue::fake();
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        $this->product = Product::factory()->create(['name' => 'Hosting']);
        $this->yearly = $this->plan('Yearly', 'year', 120.00);
        $this->monthly = $this->plan('Monthly', 'month', 12.00);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(string $name, string $unit, float $price): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $this->product->id, 'priceable_type' => Product::class, 'name' => $name,
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $plan->fresh();
    }

    /** A yearly service to 2027-01-01, with no period line. */
    private function service(): Service
    {
        return Service::factory()->create(['client_id' => User::factory()->create()->client()->id,
            'product_id' => $this->product->id, 'plan_id' => $this->yearly->id, 'price' => 120.00, 'quantity' => 1,
            'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2027-01-01'])->fresh();
    }

    private function periodLine(Service $service, string $start, string $end, string $status): InvoiceItem
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $line = $invoice->items()->create(['description' => 'Period', 'price' => 120.00, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => $start, 'period_end' => $end]);
        $this->statusFixture((int) $invoice->id, $status);

        return $line;
    }

    /**
     * REV4-E-1: the fix of E-PAYMENT-CREDIT-1 is all or nothing. Right after the cut-over every service is imported without
     * its lines (its period paid in WHMCS). Its first renewal here is paid early (expires_at moves on to 2028-01-01) and
     * then refunded in full (expires_at stays). The days from today to 2027-01-01 were still paid, in WHMCS; only
     * 2027-01-01..2028-01-01 was refunded. givenUpLeft() sees the refunded line start before the expiry and gives 0 for all
     * of it: the customer loses the credit for the days paid in WHMCS (before the fix they got credit for the refunded year as well).
     */
    public function test_imported_service_with_a_refunded_renewal_ahead_still_credits_the_days_paid_in_whmcs(): void
    {
        $service = $this->service(); // imported, yearly to 2027-01-01, no line
        $this->assertSame('60.16', ChangeCycle::unused($service, CarbonImmutable::today()));

        // Its renewal here, paid early (the expiry moved on), then refunded in full (the expiry stays where it is).
        $this->periodLine($service, '2027-01-01', '2028-01-01', Invoice::STATUS_REFUNDED);
        $service->forceFill(['expires_at' => '2028-01-01'])->save();

        $this->assertSame('60.16', ChangeCycle::unused($service->fresh(), CarbonImmutable::today()),
            'the WHMCS-paid days to 2027-01-01 are still owed; only the refunded year is not');
    }
}
