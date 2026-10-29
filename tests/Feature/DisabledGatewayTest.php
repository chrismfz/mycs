<?php

namespace Tests\Feature;

use App\Admin\Resources\GatewayResource\Pages\EditGateway;
use App\Admin\Resources\GatewayResource\Pages\ListGateways;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Client\Credits;
use App\Livewire\Client\PaymentMethods;
use App\Livewire\Invoices\PayTogether;
use App\Livewire\Invoices\Show;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\MassPay;
use App\Services\Money\BankTransferDetails;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\SavedMethodCharge;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MyCS\Extensions\Gateways\DgOff\DgOff;
use MyCS\Extensions\Gateways\DgOn\DgOn;
use Tests\TestCase;

/**
 * A gateway staff disabled (`extensions.enabled`, the WHMCS importer installs them so) is not offered to customers:
 * checkout, the invoice, Add Funds, Mass Pay, saving a payment method, the cron's charge, the bank accounts on invoices.
 * It is still booted, so its webhooks, refunds and the payments already on their way are recorded.
 */
class DisabledGatewayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Gateway $on;

    private Gateway $off;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['DgOn', 'DgOff'] as $name) {
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
                    public function createBillingAgreement(\$client) { self::\$calls[] = 'setup'; return 'https://{$name}.test/setup'; }
                    public function pay(\$invoice, \$total) { self::\$calls[] = 'pay'; return 'https://{$name}.test/pay'; }
                    public function charge(\$invoice, \$amount, \$agreement) { self::\$calls[] = 'charge'; return false; }
                }");
            }
        }
        DgOn::$calls = [];
        DgOff::$calls = [];
        $this->on = Gateway::query()->create(['extension' => 'DgOn', 'name' => 'DgOn', 'type' => 'gateway', 'enabled' => true]);
        $this->off = Gateway::query()->create(['extension' => 'DgOff', 'name' => 'DgOff', 'type' => 'gateway', 'enabled' => false]);
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        config(['settings.credits_auto_use' => false]);
    }

    private function invoice(float $price = 50): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function agreement(Gateway $gateway, string $ref): BillingAgreement
    {
        return BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id, 'name' => 'Card ' . $ref,
            'external_reference' => $ref, 'type' => 'card']);
    }

    /** @param  array<int, Gateway>|iterable<Gateway>  $gateways @return list<int> */
    private function ids(iterable $gateways): array
    {
        return collect($gateways)->map(fn (Gateway $gateway) => (int) $gateway->id)->values()->all();
    }

    public function test_the_helpers_offer_only_enabled_gateways(): void
    {
        $this->assertSame([$this->on->id], $this->ids(ExtensionHelper::getCheckoutGateways(50, 'USD', 'cart')), 'checkout');
        $this->assertSame([$this->on->id], $this->ids(ExtensionHelper::getCheckoutGateways(50, 'USD', 'invoice')), 'an invoice');
        $this->assertSame([$this->on->id], $this->ids(ExtensionHelper::getCheckoutGateways(50, 'USD', 'credits')), 'Add Funds');
        $this->assertSame([$this->on->id], $this->ids(ExtensionHelper::getBillingAgreementGateways()), 'saving a method');
    }

    public function test_the_invoice_neither_offers_nor_opens_a_disabled_gateway(): void
    {
        $invoice = $this->invoice();
        $page = $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice]);

        $this->assertSame([$this->on->id], $this->ids($page->instance()->gateways()));
        $page->set('selectedMethod', 'gateway-' . $this->off->id)->call('processPayment')->assertNoRedirect();
        $this->assertSame([], DgOff::$calls, 'a tampered request does not open it');

        $page->set('selectedMethod', 'gateway-' . $this->on->id)->call('processPayment')->assertRedirect('https://DgOn.test/pay');
    }

    public function test_the_invoice_neither_lists_nor_charges_a_saved_method_of_a_disabled_gateway(): void
    {
        $invoice = $this->invoice();
        $kept = $this->agreement($this->on, 'ON');
        $hidden = $this->agreement($this->off, 'OFF');
        $page = $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice]);

        $this->assertSame([$kept->id], $page->instance()->savedPaymentMethods()->pluck('id')->all());
        $page->set('selectedMethod', $hidden->ulid)->call('processPayment');

        $this->assertSame([], DgOff::$calls);
        $this->assertSame(0, ChargeAttempt::query()->count(), 'no attempt written');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    public function test_add_funds_refuses_a_disabled_gateway(): void
    {
        $this->storeSettings(['credits_enabled' => true, 'credits_minimum_deposit' => 5, 'credits_maximum_deposit' => 500, 'credits_maximum_credit' => 1000]);
        $page = $this->livewireAs($this->user)->test(Credits::class);

        $this->assertSame([$this->on->id], $this->ids($page->get('gateways')));
        $page->set('amount', 20)->set('gateway', $this->off->id)->call('addCredit')->assertHasErrors('gateway');
        $this->assertSame([], DgOff::$calls);
        $this->assertSame(0, Invoice::query()->where('client_id', $this->client->id)->count(), 'no credit invoice made');
    }

    public function test_a_payment_method_is_not_saved_through_a_disabled_gateway(): void
    {
        $page = $this->livewireAs($this->user)->test(PaymentMethods::class);

        $page->set('gateway', $this->off->id)->call('createBillingAgreement')->assertHasErrors('gateway');
        $this->assertSame([], DgOff::$calls);
    }

    public function test_mass_pay_neither_offers_nor_opens_a_disabled_gateway_or_its_saved_methods(): void
    {
        $group = (new MassPay)->open($this->client, [$this->invoice(10)->id, $this->invoice(20)->id], $this->user);
        $hidden = $this->agreement($this->off, 'OFF');
        $kept = $this->agreement($this->on, 'ON');
        $page = $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group]);

        $this->assertSame([$this->on->id], $this->ids($page->instance()->gateways()));
        $this->assertSame([$kept->id], $page->instance()->savedPaymentMethods()->pluck('id')->all());

        $page->set('selectedMethod', 'gateway-' . $this->off->id)->call('processPayment')->assertNoRedirect();
        $page->set('selectedMethod', $hidden->ulid)->call('processPayment');
        $this->assertSame([], DgOff::$calls);
        $this->assertSame(0, ChargeAttempt::query()->count());
    }

    public function test_a_saved_method_of_a_disabled_gateway_is_charged_from_nowhere(): void
    {
        $invoice = $this->invoice();
        $agreement = $this->agreement($this->off, 'OFF');

        try {
            (new SavedMethodCharge)->charge($invoice, $agreement, ChargeAttempt::SOURCE_CRON);
            $this->fail('charged through a disabled gateway');
        } catch (DisplayException) {
        }
        $this->assertSame([], DgOff::$calls);
        $this->assertSame(0, ChargeAttempt::query()->count(), 'refused before an attempt is written');
    }

    public function test_the_cron_does_not_pick_a_default_method_of_a_disabled_gateway(): void
    {
        $agreement = $this->agreement($this->off, 'OFF');
        $this->client->forceFill(['auto_charge' => true, 'default_billing_agreement_id' => $agreement->id])->save();
        $invoice = $this->invoice();

        $this->assertNull(AutoCharge::agreementFor($invoice->fresh()));

        $this->off->update(['enabled' => true]);
        $this->assertSame($agreement->id, AutoCharge::agreementFor($invoice->fresh())?->id, 'enabled again, charged again');
    }

    public function test_a_payment_already_on_its_way_is_still_recorded_and_refunded(): void
    {
        $invoice = $this->invoice();

        // The gateway's webhook after staff disabled it: the payment the customer made is the customer's.
        ExtensionHelper::addPayment($invoice->id, 'DgOff', 50, null, 'tx_1', currency: 'USD');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $payment = InvoiceTransaction::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame($this->off->id, (int) $payment->gateway_id);

        $this->assertTrue(ExtensionHelper::refundedAtGateway('DgOff', 'tx_1', 're_1', 50.0, 'USD'), 'a refund its webhook reports');
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
    }

    public function test_a_disabled_gateway_is_still_booted(): void
    {
        $this->assertContains($this->off->id, ExtensionHelper::bootable()->pluck('id')->all(), 'its webhooks and listeners keep working');
    }

    public function test_a_disabled_bank_transfer_shows_no_accounts_on_invoices(): void
    {
        $bank = Gateway::query()->create(['extension' => 'BankTransfer', 'name' => 'Bank transfer', 'type' => 'gateway', 'enabled' => true]);
        $bank->settings()->create(['key' => 'accounts', 'value' => "Example Bank\nIBAN: GR00 0000"]);
        $invoice = $this->invoice();
        $this->assertNotNull(BankTransferDetails::for($invoice));

        $bank->update(['enabled' => false]);
        $this->assertNull(BankTransferDetails::for($invoice));
    }

    public function test_staff_turn_a_gateway_on_and_off_on_its_page(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Staff ' . uniqid(), 'permissions' => ['*']])->id]);
        $bank = Gateway::query()->create(['extension' => 'BankTransfer', 'name' => 'Bank transfer', 'type' => 'gateway', 'enabled' => false]);
        $bank->settings()->create(['key' => 'accounts', 'value' => 'Example Bank']);

        $this->livewireAs($staff)->test(EditGateway::class, ['record' => $bank->getRouteKey()])
            ->assertSet('data.enabled', false)
            ->set('data.enabled', true)->call('save')->assertHasNoErrors();
        $this->assertTrue((bool) $bank->fresh()->enabled);
        $this->assertSame('Example Bank', $bank->fresh()->settings()->where('key', 'accounts')->value('value'), 'its settings kept');

        $this->livewireAs($staff)->test(EditGateway::class, ['record' => $bank->getRouteKey()])
            ->set('data.enabled', false)->call('save')->assertHasNoErrors();
        $this->assertFalse((bool) $bank->fresh()->enabled);

        $this->livewireAs($staff)->test(ListGateways::class)->assertCanSeeTableRecords([$bank, $this->off]);
    }

    /** Code review #540 (2, 3): the service page, renewals and cycle changes read it to tell the customer it pays itself. */
    public function test_a_disabled_gateways_default_method_is_not_the_accounts_auto_charge_method(): void
    {
        $agreement = $this->agreement($this->off, 'OFF');
        $this->client->forceFill(['auto_charge' => true, 'default_billing_agreement_id' => $agreement->id])->save();

        $this->assertNull($this->client->fresh()->autoChargeAgreement());
        $this->assertSame($agreement->id, $this->client->fresh()->autoChargeMethod()?->id, 'kept: enabled again, it is charged again');

        $this->off->update(['enabled' => true]);
        $this->assertSame($agreement->id, $this->client->fresh()->autoChargeAgreement()?->id);
    }

    /** Code review #540 (2): the Payment Methods page marks it unavailable, neither «used for» nor «use for» automatic payments. */
    public function test_payment_methods_marks_a_disabled_gateways_method_unavailable(): void
    {
        $this->agreement($this->off, 'OFF'); // the first: the account's default
        $this->agreement($this->on, 'ON');

        $this->livewireAs($this->user)->test(PaymentMethods::class)
            ->assertSeeText(__('account.payment_method_unavailable'))
            ->assertDontSeeText(__('account.default_payment_method'))
            ->assertSeeText(__('account.make_default_payment_method'));
    }
}
