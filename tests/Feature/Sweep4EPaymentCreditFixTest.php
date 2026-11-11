<?php

namespace Tests\Feature;

use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MoneyEvent;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\ChangeCycle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Others\Affiliates\Listeners\RewardAffiliate;
use MyCS\Extensions\Others\Affiliates\Models\Affiliate;
use MyCS\Extensions\Others\Affiliates\Models\AffiliateOrder;
use Tests\TestCase;

/**
 * Sweep 4, lens e-payment-credit, the fixer's tests: the cycle change «now» side of E-PAYMENT-CREDIT-1, the «imported
 * without its lines» fallback it must keep, and the affiliate reward once per invoice (E-PAYMENT-CREDIT-2) that still
 * rewards each invoice of its own.
 */
class Sweep4EPaymentCreditFixTest extends TestCase
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

    public function test_a_cycle_change_now_gives_no_credit_for_a_period_refunded_in_full(): void
    {
        $service = $this->service();
        $this->periodLine($service, '2026-01-01', '2027-01-01', Invoice::STATUS_REFUNDED);

        $this->assertSame('0.00', ChangeCycle::unused($service, CarbonImmutable::today()));
        $this->assertSame('0.00', ChangeCycle::quote($service, $this->monthly, ChangeCycle::NOW)['credit']);
    }

    public function test_a_cycle_change_now_gives_no_credit_for_a_period_on_a_cancelled_invoice(): void
    {
        $service = $this->service();
        $this->periodLine($service, '2026-01-01', '2027-01-01', Invoice::STATUS_CANCELLED);

        $this->assertSame('0.00', ChangeCycle::quote($service, $this->monthly, ChangeCycle::NOW)['credit']);
    }

    public function test_one_imported_without_its_lines_is_still_priced_as_it_renews(): void
    {
        $service = $this->service();
        $imported = ChangeCycle::unused($service, CarbonImmutable::today());
        $this->assertSame('60.16', $imported, '120.00 × 183 / 365, as before');

        // A renewal ahead given up on (from the expiry on) holds none of the days left: still the imported fallback.
        $this->periodLine($service, '2027-01-01', '2028-01-01', Invoice::STATUS_CANCELLED);
        $aheadGivenUp = ChangeCycle::unused($service->fresh(), CarbonImmutable::today());

        // A period given up on that ended before today holds none of them either.
        $this->periodLine($service, '2025-01-01', '2026-01-01', Invoice::STATUS_REFUNDED);
        $pastGivenUp = ChangeCycle::unused($service->fresh(), CarbonImmutable::today());
        $this->assertSame([$imported, $imported], [$aheadGivenUp, $pastGivenUp]);
    }

    public function test_a_refunded_period_with_a_paid_one_still_credits_the_paid_one(): void
    {
        $service = $this->service();
        $this->periodLine($service, '2026-01-01', '2027-01-01', Invoice::STATUS_REFUNDED);
        $this->periodLine($service, '2026-01-01', '2027-01-01', Invoice::STATUS_PAID); // billed again and paid

        $this->assertSame('60.16', ChangeCycle::unused($service->fresh(), CarbonImmutable::today()));
    }

    public function test_the_affiliate_is_rewarded_once_per_invoice_and_on_each_invoice(): void
    {
        Artisan::call('migrate', ['--path' => 'extensions/Others/Affiliates/database/migrations', '--force' => true]);
        $affiliateUser = User::factory()->create();
        $affiliate = Affiliate::query()->create(['client_id' => $affiliateUser->client()->id, 'code' => 'FX4', 'reward' => 10]);
        $clientId = User::factory()->create()->client()->id;
        $order = Order::query()->create(['client_id' => $clientId, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $clientId, 'order_id' => $order->id, 'product_id' => $this->product->id,
            'plan_id' => $this->monthly->id, 'status' => 'active', 'price' => 100.00, 'currency_code' => 'USD']);
        AffiliateOrder::query()->create(['order_id' => $order->id, 'affiliate_id' => $affiliate->id]);
        $paid = function () use ($clientId, $service): Invoice {
            $invoice = Invoice::factory()->create(['client_id' => $clientId, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
            $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100.00,
                'reference_type' => Service::class, 'reference_id' => $service->id]);
            $this->statusFixture((int) $invoice->id, Invoice::STATUS_PAID);

            return $invoice->fresh();
        };
        $rewards = fn () => MoneyEvent::query()->where('client_id', $affiliateUser->client()->id)
            ->where('cause', MoneyTrail::CAUSE_AFFILIATE_REWARD)->count();

        $first = $paid();
        (new RewardAffiliate)->handle((object) ['invoice' => $first]);
        (new RewardAffiliate)->handle((object) ['invoice' => $first]); // paid again: no new sale
        $this->assertSame(1, $rewards());

        (new RewardAffiliate)->handle((object) ['invoice' => $paid()]); // the next renewal: a sale of its own
        $this->assertSame(2, $rewards());
        $this->assertSame('20.00', number_format((float) Credit::query()->where('client_id', $affiliateUser->client()->id)->value('amount'), 2, '.', ''));
    }
}
