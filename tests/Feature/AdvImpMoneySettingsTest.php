<?php

namespace Tests\Feature;

use App\Livewire\Client\Account;
use App\Livewire\Client\Credits;
use App\Livewire\Client\PaymentMethods;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use MyCS\Extensions\Gateways\CpmPay\CpmPay;
use Tests\TestCase;

/**
 * Adversarial review of #337 (SA-5): staff in «Login as client» without the admin permission still change how the
 * customer's money is spent (auto-charge of the saved card, which card pays, credit auto-apply) and the login's own name.
 */
class AdvImpMoneySettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['first_name' => 'Original', 'last_name' => 'Owner']);
        $this->client = $this->owner->client();
    }

    /** @param  list<string>  $permissions */
    private function impersonate(array $permissions): void
    {
        session(['impersonating' => $this->owner->id, CurrentClient::SESSION_KEY => $this->client->id, CurrentClient::IMPERSONATING_KEY => $this->client->id]);
        Auth::guard('staff')->setUser(Staff::factory()->withPermissions(array_merge(['admin.users.impersonate'], $permissions))->create());
        Auth::guard('web')->setUser($this->owner);
        app(CurrentClient::class)->forget();
    }

    private function agreement(string $ref): BillingAgreement
    {
        if (!class_exists(CpmPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\CpmPay; class CpmPay { public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; } public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) { return false; } }');
        }
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'CpmPay'], ['name' => 'CpmPay', 'type' => 'gateway']);

        return BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id, 'name' => 'PayPal ' . $ref,
            'external_reference' => $ref, 'type' => 'paypal']);
    }

    public function test_staff_without_the_money_permission_do_not_turn_on_auto_charge_of_the_saved_card(): void
    {
        $this->agreement('A');
        $this->client->forceFill(['auto_charge' => false])->save();
        $this->impersonate([]);

        Livewire::test(PaymentMethods::class)->set('autoCharge', true);

        $this->assertFalse((bool) $this->client->fresh()->auto_charge, 'auto-charge of the customer\'s card is the money permission\'s (SA-5)');
    }

    public function test_staff_without_the_money_permission_do_not_choose_which_saved_card_pays(): void
    {
        $first = $this->agreement('A');
        $second = $this->agreement('B');
        $this->assertSame($first->id, $this->client->fresh()->default_billing_agreement_id);
        $this->impersonate([]);

        Livewire::test(PaymentMethods::class)->call('makeDefault', $second->ulid);

        $this->assertSame($first->id, $this->client->fresh()->default_billing_agreement_id, 'the card that pays renewals is the money permission\'s (SA-5)');
    }

    public function test_staff_without_the_money_permission_do_not_switch_credit_auto_apply(): void
    {
        Setting::query()->updateOrCreate(['key' => 'credits_enabled', 'settingable_type' => null], ['value' => true, 'type' => 'boolean']);
        $this->client->forceFill(['credit_auto_apply' => false])->save();
        $this->impersonate([]);

        Livewire::test(Credits::class)->set('autoApply', true);

        $this->assertFalse((bool) $this->client->fresh()->credit_auto_apply, 'spending the customer\'s balance on its own is the money permission\'s (SA-5)');
    }

    public function test_staff_without_the_clients_permission_do_not_rename_the_login(): void
    {
        $this->impersonate([]);

        Livewire::test(Account::class)->set('first_name', 'Renamed')->set('last_name', 'ByStaff')->call('submit');

        $this->assertSame('Original', $this->owner->fresh()->first_name, 'the account\'s details are admin.clients.update\'s (SA-5)');
    }

    public function test_staff_with_the_money_permission_still_switch_auto_charge(): void
    {
        $this->agreement('A');
        $this->client->forceFill(['auto_charge' => false])->save();
        $this->impersonate(['admin.invoices.update']);

        Livewire::test(PaymentMethods::class)->set('autoCharge', true);

        $this->assertTrue((bool) $this->client->fresh()->auto_charge);
    }
}
