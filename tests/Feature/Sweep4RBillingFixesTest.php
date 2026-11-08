<?php

namespace Tests\Feature;

use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use App\Services\Payment\AutoCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\AcPay\AcPay;
use Tests\TestCase;

/**
 * Sweep 4, lens r-billing-fixes (adversarial verifier): regressions of #723 (an addon suspended with its parent is billed)
 * and #709 (UPGRADES-3, a completed upgrade's shortfall goes with its service).
 */
class Sweep4RBillingFixesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '',
            'settings.autocharge_max_failures' => 0, 'settings.cronjob_invoice' => 7]);
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
        Carbon::setTestNow('2026-11-20 03:00:00');
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(string $expires, array $attributes = [], float $price = 10.00): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create([
            'client_id' => $this->client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => $price, 'quantity' => 1, ...$attributes,
        ]);
    }

    private function bill(Service ...$services): void
    {
        $billing = new RenewalBilling;
        foreach ($services as $service) {
            \DB::transaction(fn () => $billing->billRenewal($service->id, now()->addDays(7)));
        }
        $billing->finishRenewalInvoices();
    }

    /**
     * R-BILLING-FIXES-1: an addon suspended with its parent (staff suspended the parent) is billed since #723, its line
     * lands on the same grouped invoice as an active service due the same day, and AutoCharge::due() then leaves the whole
     * invoice out: the active service's renewal is never charged to the saved card.
     */
    public function test_an_addon_suspended_with_its_parent_does_not_keep_another_services_renewal_from_the_card(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcPay'], ['name' => 'AcPay', 'type' => 'gateway', 'enabled' => true]);
        BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $this->client->forceFill(['auto_charge' => true])->save();
        Queue::fake(); // the parent's server suspension

        $parent = $this->service('2027-01-01');
        $addon = $this->service('2026-11-25', ['parent_service_id' => $parent->id], 3.00);
        $other = $this->service('2026-11-25');
        $parent->applyStaffChanges(['status' => Service::STATUS_SUSPENDED]);
        $addon->refresh();
        $this->assertSame([Service::STATUS_SUSPENDED, Service::SUSPEND_REASON_PARENT, null],
            [$addon->status, $addon->suspend_reason, $addon->dunning_suspended_at], 'precondition: suspended with its parent only');

        $this->bill($addon, $other);

        $line = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $other->id)->sole();
        $invoice = $line->invoice;
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);

        Carbon::setTestNow('2026-11-25 09:00:00');
        (new AutoCharge)->run();

        $this->assertContains($invoice->id, array_column(AcPay::$charges, 'invoice'),
            'the active service\'s renewal is charged to the saved card; its invoice also holds the suspended addon\'s line ('
            . InvoiceItem::query()->where('invoice_id', $invoice->id)->count() . ' lines), so AutoCharge::due() skips it');
    }

    /**
     * R-BILLING-FIXES-2: an end-of-period request cancels the open shortfall invoice of a completed upgrade, though the
     * service runs on the upgraded product until its paid-ahead expiry, the very days that shortfall bills.
     */
    public function test_an_end_of_period_request_keeps_the_shortfall_of_the_days_the_service_still_runs(): void
    {
        $service = $this->service('2026-12-25'); // renewed ahead: paid until next month
        $bigger = $this->createProduct();
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id, 'plan_id' => $bigger->plan->id, 'product_id' => $bigger->product->id,
            'status' => ServiceUpgrade::STATUS_COMPLETED,
        ]);
        $service->forceFill(['product_id' => $bigger->product->id, 'plan_id' => $bigger->plan->id])->save();
        // As billShortfall makes it: the upgrade's price for the days of the renewal paid meanwhile, due in 7 days.
        $shortfall = Invoice::query()->create(['currency_code' => 'USD', 'status' => Invoice::STATUS_UNPAID,
            'due_at' => now()->addDays(7), 'client_id' => $this->client->id]);
        $shortfall->items()->create(['description' => 'Upgrade: the days of a renewal paid meanwhile', 'price' => '6.00',
            'quantity' => 1, 'reference_id' => $upgrade->id, 'reference_type' => ServiceUpgrade::class]);

        ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'Leaving later']);

        $service->refresh();
        $this->assertSame(Service::STATUS_ACTIVE, $service->status, 'precondition: it runs on until 2026-12-25');
        $this->assertSame('2026-12-25', $service->expires_at->toDateString());
        $this->assertSame(Invoice::STATUS_UNPAID, $shortfall->fresh()->status,
            'the shortfall bills the upgraded days up to its paid-ahead expiry, which it still runs: not cancelled at the request');
    }

    /**
     * R-BILLING-FIXES-3: an addon suspended with a parent suspended for non-payment is billed since #723, and credit pays
     * that period at once, though the addon runs none of it while its parent is suspended.
     */
    public function test_credit_is_not_spent_on_an_addons_period_while_its_parent_is_suspended_for_non_payment(): void
    {
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
        (new CreditLedger)->add($this->client->id, 'USD', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');

        $parent = $this->service('2026-11-01', ['status' => 'suspended', 'suspend_reason' => 'Overdue on Payment',
            'dunning_suspended_at' => now()->subDays(10)]);
        $addon = $this->service('2026-11-25', ['parent_service_id' => $parent->id, 'status' => 'suspended',
            'suspend_reason' => Service::SUSPEND_REASON_PARENT], 3.00);

        $this->bill($parent, $addon);

        $this->assertFalse(InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $parent->id)->exists(),
            'precondition: the parent, suspended for non-payment, is not billed again');
        $this->assertSame('50.00', number_format((float) Credit::query()->where('client_id', $this->client->id)->value('amount'), 2, '.', ''),
            'credit spent on the next period of an addon suspended with its dunned parent (addon now paid until '
            . $addon->fresh()->expires_at->toDateString() . ', still ' . $addon->fresh()->status . ')');
    }
}
