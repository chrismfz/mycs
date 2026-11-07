<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyTrail;
use App\Services\Payment\SavedMethodCharge;
use App\Services\ServiceUpgrade\ChangeCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Review of the f-staff-admin-money fixes (sweep 2).
 */
class Rev2FStaffAdminMoneyTest extends TestCase
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
     * REV2-SAM-1: the new guard lets every CancelOpenInvoices cancel through while a card charge is unsettled, «changes
     * cycle» included. There the service goes on: the renewal is cancelled, the period is billed again on the new
     * cycle and charged to the card again, while the charge that lands becomes credit (auto-use off): the double charge
     * of F-STAFF-ADMIN-MONEY-2, reached by a cycle change (the customer's own from the client area, Livewire\Services\Show) instead of «Cancel».
     * ChangeCycle::refusal only looks at hasPayments (a transaction), not at an unresolved ChargeAttempt.
     */
    public function test_cycle_change_is_refused_while_a_saved_card_charge_of_its_renewal_is_unsettled(): void
    {
        [$service, $invoice] = $this->serviceWithRenewal();
        $gateway = Gateway::query()->create(['extension' => 'Rev2Card', 'name' => 'Rev2Card', 'type' => 'gateway', 'enabled' => true]);
        $agreement = BillingAgreement::query()->create(['client_id' => $service->client_id, 'gateway_id' => $gateway->id, 'name' => 'Card R',
            'external_reference' => 'R', 'type' => 'card']);
        ChargeAttempt::query()->create([
            'key' => '0b1e5f2a-0000-4d2c-9e7f-0000000a2e51', 'invoice_id' => $invoice->id, 'client_id' => $service->client_id,
            'billing_agreement_id' => $agreement->id, 'amount' => 120, 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CRON,
            'status' => ChargeAttempt::STATUS_UNKNOWN, 'started_at' => now()->subHours(2), 'sent_at' => now()->subHours(2),
        ]);
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()));

        $refused = false;
        try {
            ChangeCycle::apply($service->fresh(), $this->monthly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);
        } catch (DisplayException) {
            $refused = true;
        }

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status,
            'a cycle change cancelled a renewal whose card charge may have been taken: the period is billed and charged again');
        $this->assertTrue($refused);
    }

    /**
     * REV2-SAM-2: F-STAFF-ADMIN-MONEY-1 is not fixed on this branch; its test was muted (markTestIncomplete) as «fixed in
     * the f-plan-change flow», but F-PLAN-CHANGE-1 is the setup fee in the first line, not a refund or a credit note on
     * the paid invoice. Same scenario, not muted: paid 120.00, 100.00 refunded, cycle change «now» halfway.
     */
    public function test_cycle_change_now_credit_after_a_partial_refund_is_still_from_line_total(): void
    {
        [$service, $invoice] = $this->serviceWithRenewal();
        $payment = (new RecordPaymentService)->recordByStaff($invoice, null, 120.00, 'TX-R1');
        ExtensionHelper::refundLocally($payment->fresh(), 100.00, notify: false);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        Carbon::setTestNow('2026-07-02 10:00:00');
        ChangeCycle::apply($service->fresh(), $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $credit = $this->creditCents($service);
        $this->assertLessThanOrEqual(2000, $credit, "the customer kept 20.00, yet the cycle change credited {$credit} cents");
    }
}
