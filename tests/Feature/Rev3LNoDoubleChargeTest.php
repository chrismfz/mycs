<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Livewire\Invoices\Show;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail as MailFacade;
use MyCS\Extensions\Gateways\R3Checkout\R3Checkout;
use MyCS\Extensions\Gateways\R3Saved\R3Saved;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Sweep 3, lens «no double charge»: independent review of the fixes (L-NO-DOUBLE-CHARGE-1, -2). Each test fails on the fix
 * branch for the gap it names.
 */
#[Group('adversarial')]
class Rev3LNoDoubleChargeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.credits_enabled' => true, 'settings.system_email_address' => 'staff@example.test',
            'settings.mail_disable' => false, 'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '',
            'settings.autocharge_max_failures' => 0, 'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true]);
        foreach (['R3Checkout', 'R3Saved'] as $name) {
            if (!class_exists("MyCS\\Extensions\\Gateways\\{$name}\\{$name}")) {
                eval("namespace MyCS\\Extensions\\Gateways\\{$name}; class {$name} {
                    /** @var list<string> */
                    public static array \$calls = [];
                    public \$explicitConfig = false;
                    public function __construct(\$config = []) {}
                    public function getConfig(\$values = []) { return []; }
                    public function config(\$key) { return null; }
                    public function supportsBillingAgreements() { return true; }
                    public function supportsPaymentGroups() { return true; }
                    public function cancelBillingAgreement(\$agreement): bool { return true; }
                    public function pay(\$invoice, \$total) { self::\$calls[] = 'pay:' . \$total; return 'https://{$name}.test/checkout'; }
                    public function charge(\$invoice, \$amount, \$agreement) {
                        self::\$calls[] = 'charge:' . \$amount;
                        \\App\\Helpers\\ExtensionHelper::addPayment(\$invoice->id, '{$name}', \$amount, null, 'ch_saved_' . count(self::\$calls), currency: 'USD');
                        return true;
                    }
                }");
            }
        }
        R3Checkout::$calls = [];
        R3Saved::$calls = [];
        MailFacade::fake();
        Carbon::setTestNow('2026-11-10 08:00:00');
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An open renewal invoice of an active service, due today, for 10.00 USD. */
    private function invoice(): Invoice
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-11-10']);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-10']);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id]);

        return $invoice->fresh();
    }

    /**
     * REV3-NDC-1 (L-NO-DOUBLE-CHARGE-2 only narrowed). The hold the fix writes lasts CHECKOUT_HOLD_MINUTES (60) and the
     * hourly cron charges right after it. A checkout that is still open then (a Stripe Elements form: its PaymentIntent
     * never expires and is confirmed in the browser; a tab left open over a coffee) still takes the same debt a second
     * time when the customer completes it 61+ minutes later. Nothing at the cron's charge closes or cancels the checkout,
     * and nothing at the checkout's payment refuses or refunds it: the double charge of the finding stays, only later.
     */
    public function test_rev3_ndc_1_a_checkout_open_past_the_hold_still_takes_the_debt_twice(): void
    {
        $checkout = Gateway::query()->create(['extension' => 'R3Checkout', 'name' => 'Card checkout', 'type' => 'gateway', 'enabled' => true]);
        $saved = Gateway::query()->create(['extension' => 'R3Saved', 'name' => 'Saved card', 'type' => 'gateway', 'enabled' => true]);
        $agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $saved->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $this->client->refresh();
        $invoice = $this->invoice();
        $this->assertSame($agreement->id, AutoCharge::agreementFor($invoice)?->id, 'precondition: the cron charges the saved card today');

        // 08:00: the customer opens the card checkout on the invoice page, for the full 10.00, and leaves the tab open.
        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'gateway-' . $checkout->id)->call('processPayment');
        $this->assertSame(['pay:10'], R3Checkout::$calls, 'precondition: the checkout was opened');

        // 09:01: the hourly run, past the 60-minute hold, charges the saved card.
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00:00')->addMinutes(SavedMethodCharge::CHECKOUT_HOLD_MINUTES + 1));
        (new AutoCharge)->run();

        // 09:05: the customer comes back to the open tab and completes the checkout; its gateway reports the money.
        Carbon::setTestNow('2026-11-10 09:05:00');
        ExtensionHelper::addPayment($invoice->id, 'R3Checkout', 10, null, 'chk_late', currency: 'USD');

        $taken = InvoiceTransaction::query()->where('invoice_id', $invoice->id)->where('is_credit_transaction', false)
            ->where('status', InvoiceTransactionStatus::Succeeded)->whereNotNull('gateway_id')->sum('amount');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertEquals(10.0, (float) $taken, 'one invoice of 10.00, yet the customer was charged ' . $taken . ' (checkout open 65 minutes)');
    }
}
