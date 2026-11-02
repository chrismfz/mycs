<?php

namespace Tests\Feature;

use App\Admin\Resources\GatewayResource\Pages\EditGateway;
use App\Livewire\Client\PaymentMethods;
use App\Livewire\Invoices\Show;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\PaymentReminders;
use App\Services\Money\BankTransferDetails;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\ItemPaymentMethod;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Gateways\SweepCardPay\SweepCardPay;
use Tests\TestCase;

/**
 * The payment-methods sweep of 2026-11-02: the Stripe webhook made again for new keys (SW-1), «charged on its own» only
 * where the cron charges (SW-2), a saved method of a deleted gateway removed (SW-3), a name with an apostrophe.
 */
class PaymentSweepTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Gateway $cardGateway;

    private Gateway $bank;

    private BillingAgreement $card;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '',
            'settings.autocharge_max_failures' => 0, 'settings.cronjob_order_suspend' => 8]);
        if (!class_exists(SweepCardPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\SweepCardPay; class SweepCardPay {
                public static array $charges = [];
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) { self::$charges[] = $invoice->id; return true; } }');
        }
        SweepCardPay::$charges = [];
        Mail::fake();
        Carbon::setTestNow('2026-11-01 09:00:00');
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        $this->cardGateway = Gateway::query()->create(['name' => 'Card', 'extension' => 'SweepCardPay', 'type' => 'gateway', 'enabled' => true]);
        $this->bank = Gateway::query()->create(['extension' => 'BankTransfer', 'name' => 'Bank transfer', 'type' => 'gateway', 'enabled' => true]);
        $this->bank->settings()->create(['key' => 'accounts', 'value' => "Example Bank\nIBAN: GR00 0000 0000 0000 0000 0000 000"]);
        $this->card = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->cardGateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'visa']);
        $this->client->forceFill(['auto_charge' => true])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): Service
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);

        return Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => 10,
            'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-11-01']);
    }

    private function invoice(string $due, ?string $kind = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Line', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $this->service()->id, 'kind' => $kind]);

        return $invoice->fresh();
    }

    /** SW-1: keys changed from sandbox to live make the webhook again on the live account; the same key does not. */
    public function test_the_stripe_webhook_is_made_again_for_new_keys(): void
    {
        $made = 0;
        Http::fake(['api.stripe.com/v1/webhook_endpoints*' => function (Request $request) use (&$made) {
            if ($request->method() === 'GET') {
                return Http::response(['data' => []]);
            }
            $made++;

            return Http::response(['id' => 'we_' . $made, 'secret' => 'whsec_live_' . $made]);
        }, 'api.stripe.com/*' => Http::response(['object' => 'balance', 'available' => [], 'pending' => []])]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);
        $stripe = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $stripe->settings()->create(['key' => 'stripe_secret_key', 'value' => 'sk_test_sandbox', 'encrypted' => true]);
        $stripe->settings()->create(['key' => 'stripe_publishable_key', 'value' => 'pk_test_sandbox']);
        $stripe->settings()->create(['key' => 'stripe_webhook_secret', 'value' => 'whsec_sandbox', 'encrypted' => true]);

        $this->livewireAs($staff)->test(EditGateway::class, ['record' => $stripe->getRouteKey()])
            ->set('data.settings.stripe_secret_key', 'sk_live_new')->set('data.settings.stripe_publishable_key', 'pk_live_new')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('whsec_live_1', $stripe->fresh()->settings()->where('key', 'stripe_webhook_secret')->first()?->value, 'the live account\'s');
        $this->livewireAs($staff)->test(EditGateway::class, ['record' => $stripe->getRouteKey()])->call('save')->assertHasNoErrors();
        $this->assertSame(1, $made, 'the same key: not made again');
    }

    /** SW-2a: an upgrade's charge alone is never charged by the cron: the customer is told to pay it, with the bank details. */
    public function test_an_invoice_the_cron_leaves_is_not_said_to_be_charged(): void
    {
        $invoice = $this->invoice('2026-11-01', InvoiceItem::KIND_CHARGE);
        (new AutoCharge)->run();
        $this->assertSame([], SweepCardPay::$charges, 'precondition: never charged');

        $this->assertFalse(PaymentReminders::chargedOnItsOwn($invoice));
        $this->assertNotNull(BankTransferDetails::for($invoice->fresh()), 'the bank details on its PDF and mail');
    }

    /** SW-2b: overdue past the retry window (still active): left by the cron, so not «it will be paid automatically». */
    public function test_an_invoice_past_its_window_is_not_said_to_be_charged(): void
    {
        $invoice = $this->invoice('2026-09-01');
        (new AutoCharge)->run();
        $this->assertSame([], SweepCardPay::$charges, 'precondition: past its window');

        $this->assertFalse(PaymentReminders::chargedOnItsOwn($invoice));
        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'gateway-' . $this->bank->id)->call('processPayment')
            ->assertDontSee(__('invoices.bank_transfer_charged', ['date' => '01/09/2026']), false)
            ->assertSee('IBAN: GR00');
        $service = Service::query()->find($invoice->items()->value('reference_id'));
        $this->assertSame(__('services.auto_charge_status.open_manual', ['invoice' => $invoice->id]), ItemPaymentMethod::autoChargeStatus($service));
    }

    /** The control: due today, a renewal, charged: still «on its own». */
    public function test_an_invoice_the_cron_charges_is_still_said_to_be_charged(): void
    {
        $invoice = $this->invoice('2026-11-03');

        $this->assertTrue(PaymentReminders::chargedOnItsOwn($invoice), 'due in two days: the cron will');
        $this->assertNull(BankTransferDetails::for($invoice));
    }

    /** SW-3: a saved method whose gateway staff deleted is removed here (nothing left to cancel there). */
    public function test_a_method_of_a_deleted_gateway_is_removed(): void
    {
        $this->cardGateway->delete();
        $this->assertNull($this->card->fresh()->gateway, 'precondition');

        $this->livewireAs($this->user)->test(PaymentMethods::class)->call('removePaymentMethod', $this->card->ulid);

        $this->assertSoftDeleted($this->card);
    }

    /** A PayPal email with an apostrophe: the remove button's texts are JS strings, the handler intact. */
    public function test_a_name_with_an_apostrophe_keeps_the_remove_button(): void
    {
        $this->card->forceFill(['name' => "PayPal o'brien@example.test"])->save();

        $this->livewireAs($this->user)->test(PaymentMethods::class)
            ->assertDontSee("o'brien@example.test')", false)
            ->assertSee('o' . chr(92) . 'u0027brien@example.test', false); // Js::from writes the apostrophe as \u0027
    }
}
