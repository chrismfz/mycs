<?php

namespace Tests\Feature;

use App\Livewire\Client\PaymentMethods;
use App\Livewire\Invoices\Show as InvoiceShow;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use MyCS\Extensions\Gateways\CpmPay\CpmPay;
use Tests\TestCase;

/**
 * PR C2 (decision 6): the saved method that pays renewals is the account's (WHMCS, Blesta), with «Auto-charge» per account
 * (WHMCS `disableautocc`). The customer sets both on «Payment methods»; paying an invoice can set the method too.
 */
class ClientPaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    private function agreementOf(Client $client, string $ref): BillingAgreement
    {
        if (!class_exists(CpmPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\CpmPay; class CpmPay { public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; } public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) { return false; } }');
        }
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'CpmPay'], ['name' => 'CpmPay', 'type' => 'gateway', 'enabled' => true]);

        return BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => 'PayPal ' . $ref,
            'external_reference' => $ref, 'type' => 'paypal']);
    }

    public function test_the_first_method_is_the_default_and_a_later_one_is_not(): void
    {
        $client = User::factory()->create()->client();
        $first = $this->agreementOf($client, 'A');
        $this->agreementOf($client, 'B');

        $this->assertSame($first->id, $client->fresh()->autoChargeAgreement()?->id);
    }

    public function test_removing_the_default_leaves_none_and_the_next_card_added_is_the_default(): void
    {
        $client = User::factory()->create()->client();
        $first = $this->agreementOf($client, 'A');
        $this->agreementOf($client, 'B');

        $first->delete();
        $this->assertNull($client->fresh()->default_billing_agreement_id, 'not another card the customer did not choose');

        $next = $this->agreementOf($client, 'C');
        $this->assertSame($next->id, (int) $client->fresh()->default_billing_agreement_id);
    }

    public function test_a_page_opened_for_one_account_does_not_change_another(): void
    {
        $user = User::factory()->create();
        $a = $user->client();
        $b = Client::factory()->ownedBy()->create();
        ClientUser::query()->create(['client_id' => $b->id, 'user_id' => $user->id, 'granted_at' => now()]);
        $this->actingAs($user);
        $page = $this->livewireAs($user)->test(PaymentMethods::class);

        $this->assertTrue(app(CurrentClient::class)->switchTo($b->id)); // another tab switched account
        $page->set('autoCharge', false);

        $this->assertTrue($a->fresh()->auto_charge);
        $this->assertTrue($b->fresh()->auto_charge);
    }

    public function test_a_login_without_invoices_cannot_change_the_method(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $this->agreementOf($client, 'A');
        $second = $this->agreementOf($client, 'B');
        $member = User::factory()->create();
        ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $member->id, 'granted_at' => now(), 'permissions' => ['tickets']]);
        $this->actingAs($member);
        $this->assertTrue(app(CurrentClient::class)->switchTo($client->id));

        try {
            $this->livewireAs($member)->test(PaymentMethods::class)->call('makeDefault', $second->ulid);
        } catch (\Throwable) {
        }

        $this->assertNotSame($second->id, (int) $client->fresh()->default_billing_agreement_id);
    }

    public function test_the_customer_picks_the_method_and_turns_auto_charge_off(): void
    {
        $user = User::factory()->create();
        $client = $user->client();
        $this->agreementOf($client, 'A');
        $second = $this->agreementOf($client, 'B');
        $this->actingAs($user);

        $page = $this->livewireAs($user)->test(PaymentMethods::class)->call('makeDefault', $second->ulid);
        $this->assertSame($second->id, (int) $client->fresh()->default_billing_agreement_id);

        $page->set('autoCharge', false);
        $this->assertFalse($client->fresh()->auto_charge);
        $this->assertNull($client->fresh()->autoChargeAgreement(), 'auto-charge off: nothing is charged');
    }

    public function test_paying_an_invoice_with_set_as_default_sets_the_accounts_method(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $client = $user->client();
        $this->agreementOf($client, 'A');
        $second = $this->agreementOf($client, 'B');
        $client->update(['auto_charge' => false]);
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'price' => 10, 'quantity' => 1, 'description' => 'x']);
        $this->actingAs($user);

        try {
            $this->livewireAs($user)->test(InvoiceShow::class, ['invoice' => $invoice])
                ->set('selectedMethod', $second->ulid)->set('setAsDefault', true)->call('processPayment');
        } catch (\Throwable) {
            // the charge is declined: a method that can pay is set all the same, as the customer asked
        }

        $client->refresh();
        $this->assertSame($second->id, (int) $client->default_billing_agreement_id);
        $this->assertTrue($client->auto_charge, 'asked to use it for the next payments');
    }
}
