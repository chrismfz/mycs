<?php

namespace Tests\Feature;

use App\Livewire\Client\PaymentMethods;
use App\Models\BillingAgreement;
use App\Models\Gateway;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MyCS\Extensions\Gateways\ExpiryPay\ExpiryPay;
use Tests\TestCase;

/** A saved card says when it expires (owner's live test, 2026-11-03: «Expires» with no date, a lang key written twice). */
class CardExpiryShownTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_saved_card_shows_its_expiry(): void
    {
        if (!class_exists(ExpiryPay::class)) {
            eval('namespace MyCS\Extensions\Gateways\ExpiryPay; class ExpiryPay { public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; } public function cancelBillingAgreement($agreement): bool { return true; } }');
        }
        $user = User::factory()->create();
        $gateway = Gateway::query()->create(['extension' => 'ExpiryPay', 'name' => 'ExpiryPay', 'type' => 'gateway', 'enabled' => true]);
        BillingAgreement::query()->create(['client_id' => $user->client()->id, 'gateway_id' => $gateway->id, 'name' => 'Visa **** 8885',
            'type' => 'visa', 'external_reference' => 'pm_expiry', 'expiry' => '2028-11-30']);

        $this->livewireAs($user)->test(PaymentMethods::class)->assertSee('Visa **** 8885')->assertSee('Expires 11/2028');
    }
}
