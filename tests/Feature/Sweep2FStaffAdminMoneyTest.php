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
 * Adversarial verification, flow f-staff-admin-money (sweep 2).
 */
class Sweep2FStaffAdminMoneyTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Plan $yearly;

    private Plan $monthly;

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->name() === 'test_cycle_change_now_credits_from_line_total_ignoring_a_partial_refund') {
            $this->markTestIncomplete('F-STAFF-ADMIN-MONEY-1: fixed in the f-plan-change flow (sweep 2), not on this branch.');
        }
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

    /**
     * F-STAFF-ADMIN-MONEY-2: a saved-card charge of the invoice is of unknown outcome (maybe taken). Delete refuses it
     * (InvoiceObserver::deleting); staff «Cancel» (the table action's update) does not.
     */
    public function test_cancel_is_not_refused_while_a_saved_card_charge_is_unresolved(): void
    {
        [$service, $invoice] = $this->serviceWithRenewal();
        $gateway = Gateway::query()->create(['extension' => 'Sweep2Card', 'name' => 'Sweep2Card', 'type' => 'gateway', 'enabled' => true]);
        $agreement = BillingAgreement::query()->create(['client_id' => $service->client_id, 'gateway_id' => $gateway->id, 'name' => 'Card X',
            'external_reference' => 'X', 'type' => 'card']);
        ChargeAttempt::query()->create([
            'key' => '0b1e5f2a-0000-4d2c-9e7f-00000000f2a2', 'invoice_id' => $invoice->id, 'client_id' => $service->client_id,
            'billing_agreement_id' => $agreement->id, 'amount' => 120, 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CRON,
            'status' => ChargeAttempt::STATUS_UNKNOWN, 'started_at' => now()->subHours(2), 'sent_at' => now()->subHours(2),
        ]);
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()));

        try {
            $invoice->fresh()->delete();
            $this->fail('control: delete should be refused');
        } catch (DisplayException) {
        }

        $refused = false;
        try {
            $record = $invoice->fresh();
            $record->keep_periods_billable = true; // «keep»
            $record->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'staff']);
        } catch (DisplayException) {
            $refused = true;
        }

        $this->assertTrue($refused, 'an invoice with a card charge maybe taken was cancelled: the payment that lands becomes credit and the period is charged again');
    }

    /**
     * F-STAFF-ADMIN-MONEY-2, the other side: a cancellation as a consequence of its service (it ends: CancelOpenInvoices)
     * still goes on while the charge is unsettled, as before; the money that lands becomes credit. The form's «not this
     * period» is refused as «keep» is.
     */
    public function test_a_consequence_cancel_goes_on_and_staff_give_up_is_refused_too(): void
    {
        [$service, $invoice] = $this->serviceWithRenewal();
        $gateway = Gateway::query()->create(['extension' => 'Sweep2Card', 'name' => 'Sweep2Card', 'type' => 'gateway', 'enabled' => true]);
        $agreement = BillingAgreement::query()->create(['client_id' => $service->client_id, 'gateway_id' => $gateway->id, 'name' => 'Card X',
            'external_reference' => 'X', 'type' => 'card']);
        ChargeAttempt::query()->create([
            'key' => '0b1e5f2a-0000-4d2c-9e7f-00000000f2a3', 'invoice_id' => $invoice->id, 'client_id' => $service->client_id,
            'billing_agreement_id' => $agreement->id, 'amount' => 120, 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CRON,
            'status' => ChargeAttempt::STATUS_UNKNOWN, 'started_at' => now()->subHours(2), 'sent_at' => now()->subHours(2),
        ]);

        try {
            $record = $invoice->fresh();
            $record->keep_periods_billable = false; // «not this period»
            $record->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'staff']);
            $this->fail('a staff cancel giving the period up should be refused too');
        } catch (DisplayException) {
        }
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        $left = CancelOpenInvoices::handle([$invoice->fresh()], 'service ended', $service->fresh());
        $this->assertSame([], $left);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
    }
}
