<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\PlaceUpgrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use MyCS\Extensions\Gateways\AcPay\AcPay;
use Tests\TestCase;

/**
 * Adversarial area review 2 of 6: billing in the daily cron (renewals, auto-charge, addons, upgrades, cancellations,
 * credit, reminders). Each test goes through the real cron (app:cron-job) or the service it calls, and fails now.
 * Fake data only.
 */
class AdvArea2Test extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private BillingAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0,
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true, 'settings.cronjob_invoice' => 7]);
        if (!class_exists(AcPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\AcPay; class AcPay {
                /** @var list<array{invoice: int, amount: float, key: ?string}> */
                public static array $charges = [];
                /** @var \\Closure|null */
                public static $answer = null;
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) {
                    self::$charges[] = ["invoice" => $invoice->id, "amount" => (float) $amount, "key" => $invoice->chargeKey];
                    return self::$answer === null ? true : (self::$answer)($invoice, $amount);
                } }');
        }
        AcPay::$charges = [];
        AcPay::$answer = null;
        MailFacade::fake();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->client = User::factory()->create()->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcPay'], ['name' => 'AcPay', 'type' => 'gateway', 'enabled' => true]);
        $this->agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $this->client->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paysWhenCharged(): void
    {
        AcPay::$answer = function ($invoice, $amount) {
            ExtensionHelper::addPayment($invoice->id, 'AcPay', $amount, null, 'ch_' . count(AcPay::$charges), currency: 'USD');

            return true;
        };
    }

    /** A monthly service at 10.00 expiring on $expires. */
    private function service(string $expires, array $extra = []): Service
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);

        return Service::factory()->create($extra + ['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => $expires]);
    }

    private function cronAt(string $at): void
    {
        Carbon::setTestNow($at);
        $this->artisan('app:cron-job');
    }

    /**
     * ADV-A2-1. The customer asks to cancel at the end of the period. Its renewal invoice, already part paid by a bank
     * transfer, cannot be cancelled (CancelOpenInvoices: «it holds money a gateway or a transfer paid», left for staff to
     * refund). On the due date the daily run charges the saved card for the rest (AutoCharge never looks at a cancellation
     * request, though CreditAutoApply::eligible refuses exactly this invoice), before cancellations_processed: the invoice is
     * paid, the service renews for a period the customer cancelled, and the cancellation no longer ends it («a later period
     * is already paid»). The card must not be charged for a service that has a cancellation request.
     */
    public function test_a_service_with_an_end_of_period_cancellation_is_not_charged_on_the_card(): void
    {
        $service = $this->service('2026-11-17');
        $this->cronAt('2026-11-10 09:00:00'); // the renewal is billed 7 days before
        $invoice = Invoice::query()->whereHas('items', fn ($q) => $q->where('reference_type', Service::class)->where('reference_id', $service->id))->sole();
        $this->assertSame('10.00', number_format((float) $invoice->remaining, 2), 'precondition: billed for the next month');
        // Part paid by a bank transfer staff recorded.
        ExtensionHelper::addPayment($invoice->id, null, '4.00', null, 'bank-1', currency: 'USD');
        // Then the customer asks to cancel at the end of the period (the queued listener runs: the invoice stays open).
        ServiceCancellation::create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'moving away']);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'precondition: left open for staff (it holds transfer money)');

        $this->paysWhenCharged();
        $this->cronAt('2026-11-17 09:00:00');

        $this->assertSame([], AcPay::$charges, 'the card of a customer who cancelled is not charged for the period after it');
        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status, 'ended on its date, as the customer asked');
    }

    /**
     * ADV-A2-2. Credit pays part of a renewal as the cron bills it (decision 5, MT-1e: also in part). Three days before the
     * expiry the customer upgrades 10.00 → 20.00 a month: the upgrade's invoice charges the difference for the 3 days left
     * (1.00), and once it is paid ServiceUpgradeService::refreshOpenPeriods would reprice the open renewal to the new
     * amount, but ChangeOpenLine keeps the line of an invoice with a payment on it (the credit). Paid, the next whole month
     * of the bigger package costs the old 10.00; the shortfall check only looks at a renewal paid before the upgrade. Nobody
     * is told (only a PaymentsLog warning). The month after the upgrade must be billed at the new amount (the renewal line,
     * or the rest on an invoice of its own, as billShortfall does).
     */
    public function test_an_upgrade_reprices_a_renewal_that_credit_part_paid(): void
    {
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
        [$small, $large] = [$this->product(10), $this->product(20)];
        DB::table('product_upgrades')->insert(['product_id' => $small->id, 'upgrade_id' => $large->id]);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $small->id, 'plan_id' => $small->plans()->first()->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-11-17']);
        (new CreditLedger)->add($this->client->id, 'USD', 400, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'goodwill');
        $this->cronAt('2026-11-10 09:00:00');
        $renewal = Invoice::query()->whereHas('items', fn ($q) => $q->where('reference_type', Service::class)->where('reference_id', $service->id))->sole();
        $this->assertSame('6.00', number_format((float) $renewal->remaining, 2), 'precondition: credit paid 4.00 of the 10.00 renewal');

        Carbon::setTestNow('2026-11-14 12:00:00');
        $placed = PlaceUpgrade::handle($service->fresh(), $large, [], ServiceUpgrade::SOURCE_CLIENT);
        $this->assertSame(PlaceUpgrade::INVOICED, $placed->outcome);
        ExtensionHelper::addPayment($placed->invoice->id, null, $placed->invoice->fresh()->remaining, null, 'bank-up', currency: 'USD');
        $this->assertSame('20.00', number_format((float) $service->fresh()->price, 2), 'precondition: the upgrade is applied');

        // Fixed as decided (owner, after review 2 of the fix): the part-paid line keeps its price (R9, UpgradeLifecycleTest)
        // and nothing is billed apart (never bill wrongly: an invoice of its own outlived a cancellation or a downgrade);
        // staff are told the exact difference to settle, instead of only a PaymentsLog warning.
        $owed = Invoice::query()->where('client_id', $this->client->id)->where('status', Invoice::STATUS_UNPAID)->get()
            ->sum(fn (Invoice $invoice) => (float) $invoice->remaining);
        $this->assertSame('6.00', number_format($owed, 2), 'nothing billed on top of the part-paid renewal');
        $notice = EmailLog::query()->where('to', 'staff@example.test')->where('subject', 'like', '%kept its billed price%')->sole();
        $this->assertStringContainsString("invoice #{$renewal->id}, period 17/11/2026 - 17/12/2026: 10.00 USD", $notice->body,
            'the month 11-17 → 12-17 of the 20.00 package is 10.00 short: staff are told, with the invoice and the period');
    }

    private function product(float $price): Product
    {
        $product = Product::factory()->create(['server_id' => null, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $product->fresh();
    }
}
