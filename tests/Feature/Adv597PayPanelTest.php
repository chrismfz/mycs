<?php

namespace Tests\Feature;

use App\Livewire\Invoices\Show;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Payment\AutoCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Adversarial review of #597 (the ways to pay by provider, on the invoice's page): the choices offered against what
 * processPayment accepts, what the button and the renewals note say, and what another account's data the page shows.
 */
class Adv597PayPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Gateway $card;

    private Gateway $vault;

    private Gateway $novault;

    private Gateway $form;

    protected function setUp(): void
    {
        parent::setUp();
        // Card and Vault save methods; NoVault is PayPal with «billing agreements» off (its methods stay, it saves none);
        // Form opens its own form in the window (Stripe's), as a view.
        foreach (['AdvCard' => true, 'AdvVault' => true, 'AdvNoVault' => false, 'AdvForm' => false] as $name => $saves) {
            if (!class_exists("MyCS\\Extensions\\Gateways\\{$name}\\{$name}")) {
                $agreements = $saves ? 'public function supportsBillingAgreements() { return true; } public function cancelBillingAgreement($agreement): bool { return true; }'
                    : 'public function supportsBillingAgreements() { return false; }';
                $pay = $name === 'AdvForm' ? "return new \\Illuminate\\Support\\HtmlString('<form id=\"adv-gateway-form\">card fields</form>');"
                    : "return 'https://pay.example.test/{$name}';";
                eval("namespace MyCS\\Extensions\\Gateways\\{$name}; class {$name} { public function __construct(\$config = []) {} {$agreements}
                    public function pay(\$invoice, \$total) { {$pay} }
                    public function charge(\$invoice, \$total, \$agreement) { return true; } }");
            }
        }
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        $this->card = Gateway::query()->create(['extension' => 'AdvCard', 'name' => 'CardCo', 'type' => 'gateway', 'enabled' => true]);
        $this->vault = Gateway::query()->create(['extension' => 'AdvVault', 'name' => 'VaultCo', 'type' => 'gateway', 'enabled' => true]);
        $this->novault = Gateway::query()->create(['extension' => 'AdvNoVault', 'name' => 'NoVaultCo', 'type' => 'gateway', 'enabled' => true]);
        $this->form = Gateway::query()->create(['extension' => 'AdvForm', 'name' => 'FormCo', 'type' => 'gateway', 'enabled' => true]);
    }

    private function saved(Gateway $gateway, string $name, ?Client $client = null, string $type = 'visa'): BillingAgreement
    {
        return BillingAgreement::query()->create(['ulid' => (string) Str::ulid(), 'client_id' => ($client ?? $this->client)->id, 'gateway_id' => $gateway->id,
            'name' => $name, 'type' => $type, 'external_reference' => 'pm_' . Str::random(8)]);
    }

    private function invoice(bool $renewal = false, ?Gateway $serviceGateway = null, ?Client $client = null): Invoice
    {
        $client ??= $this->client;
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => now()->addDays(20)]);
        if (!$renewal) {
            $invoice->items()->create(['description' => 'Setup', 'price' => '20.00', 'quantity' => 1]);

            return $invoice->fresh();
        }
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 20, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 20, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE]);
        if ($serviceGateway !== null) {
            $service->forceFill(['payment_gateway_id' => $serviceGateway->id])->save(); // «Paid with» this gateway (WHMCS's paymentmethod)
        }
        $invoice->items()->create(['description' => 'Renewal', 'price' => '20.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);

        return $invoice->fresh();
    }

    /**
     * ADV-597-1 · Medium (pre-existing, kept by the PR's «same choices») · A saved method of a gateway that is enabled but
     * saves no methods now (PayPal with «billing agreements» off: the methods saved before stay on the account) is offered
     * in its provider's group, with «Pay $20.00 with PayPal …» on the button, and badged «Automatic payments» when it is
     * the default (the account's first saved method becomes it). processPayment refuses it: «This payment method cannot be used for this invoice»
     * (payWithSavedMethod checks paymentMethods, the billing-agreement gateways; savedPaymentMethods only «enabled»).
     */
    public function test_adv_597_1_a_saved_method_the_server_refuses_is_not_offered(): void
    {
        $paypal = $this->saved($this->novault, 'PayPal a@example.test', type: 'paypal');
        $invoice = $this->invoice();

        // The server refuses it: no charge is tried, nothing opens.
        $page = $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', $paypal->ulid)->call('processPayment')->assertNoRedirect();
        $this->assertSame(0, ChargeAttempt::query()->count(), 'processPayment refused it');
        $this->assertSame(0, $invoice->transactions()->count());

        // So the panel does not offer it.
        $page->assertDontSeeHtml('value="' . $paypal->ulid . '"');
    }

    /**
     * ADV-597-2 · Medium · An account pays its renewals with the default card (CardCo, «Auto-charge» on) and one service with
     * PayPal (services.payment_gateway_id, docs/payments.md «Τρόπος πληρωμής ανά υπηρεσία»: 8 of nixpal's 31). On that
     * service's renewal invoice the customer picks the card: the panel says «This method already pays your renewals
     * automatically», and the card wears «Automatic payments». This invoice, and that service's next renewals, are charged
     * by the PayPal method (AutoCharge::agreementFor, Client::autoChargeMethodFor), never by the card.
     */
    public function test_adv_597_2_already_pays_your_renewals_is_not_said_of_a_method_that_does_not_pay_this_renewal(): void
    {
        $visa = $this->saved($this->card, 'Visa 4242');
        $paypal = $this->saved($this->vault, 'PayPal a@example.test', type: 'paypal');
        $this->client->forceFill(['default_billing_agreement_id' => $visa->id, 'auto_charge' => true])->save();
        $invoice = $this->invoice(renewal: true, serviceGateway: $this->vault);
        $this->assertSame($paypal->id, AutoCharge::agreementFor($invoice->fresh())?->id, 'the PayPal method charges this renewal');

        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', $visa->ulid)
            ->assertDontSee(__('invoices.pay_panel.renewals_already'));
    }

    /**
     * ADV-597-3 · Medium · The default card has stopped at autocharge_max_failures (BillingAgreement::stopped()): the cron
     * no longer charges it (AutoCharge::agreementFor is null). Picking it, the
     * panel still says «This method already pays your renewals automatically» and badges it «Automatic payments»: the
     * customer is told the renewals are taken care of, and the services lapse.
     */
    public function test_adv_597_3_a_stopped_default_is_not_said_to_pay_the_renewals(): void
    {
        config(['settings.autocharge_max_failures' => 3]);
        $visa = $this->saved($this->card, 'Visa 4242');
        BillingAgreement::query()->whereKey($visa->id)->update(['failed_count' => 3]);
        $this->client->forceFill(['default_billing_agreement_id' => $visa->id, 'auto_charge' => true])->save();
        $invoice = $this->invoice(renewal: true);
        $this->assertNull(AutoCharge::agreementFor($invoice->fresh()), 'the cron charges nothing');

        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', $visa->ulid)
            ->assertDontSee(__('invoices.pay_panel.renewals_already'))
            ->assertDontSee(__('invoices.pay_panel.automatic'));
    }

    /**
     * ADV-597-4 · Low (pre-existing in the old window) · The customer pays part with the balance ($5 of $20): the credit row
     * is gone (no balance left), but `selectedMethod` stays «credit», a choice no longer shown. No radio is ticked, the
     * button is enabled and reads «Pay $15.00», and pressing it does nothing at all (payWithCredit finds no credit and
     * returns null: no notice).
     */
    public function test_adv_597_4_after_a_part_paid_with_the_balance_nothing_hidden_stays_chosen(): void
    {
        config(['settings.credits_enabled' => true]);
        Credit::query()->create(['client_id' => $this->client->id, 'currency_code' => 'USD', 'amount' => '5.00']);
        $invoice = $this->invoice();

        $page = $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'credit')->call('processPayment');
        $this->assertSame(15.0, (float) $invoice->fresh()->remaining, 'the balance paid $5');
        $page->assertDontSee(__('invoices.account_credits'))->assertSeeHtml('id="pay"');

        $page->assertNotSet('selectedMethod', 'credit');
    }

    /**
     * ADV-597-5 · Low · A crafted update sets `selectedMethod` to `true` (a JSON boolean). payLabel and renewalsChoice look
     * it up with Collection::firstWhere, a loose `==`: every ulid equals `true`, so the button reads «Pay $20.00 with Visa
     * 4242» and the renewals toggle appears, for a value no radio holds. (On SQLite processPayment refuses it; on MariaDB
     * `ulid = 1` compares as a number, and a ulid «01…» is 1: not proven here.)
     */
    public function test_adv_597_5_a_crafted_boolean_choice_names_no_method(): void
    {
        $this->saved($this->card, 'Visa 4242');
        $invoice = $this->invoice(renewal: true);

        $page = $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])->set('selectedMethod', true);
        $this->assertSame(__('invoices.pay_amount', ['amount' => $invoice->formattedRemaining]), $page->get('payLabel'));
        $page->assertSet('renewalsChoice', null);
    }

    /**
     * Pin (passes on the PR head) · Crafted values never name what is not offered: another account's saved method, a
     * disabled gateway and a disabled gateway's saved method read as the plain «Pay $20.00», no renewals toggle, and
     * processPayment refuses them all.
     */
    public function test_pin_597_crafted_choices_name_nothing_not_offered(): void
    {
        $other = User::factory()->create()->client();
        $theirs = $this->saved($this->card, 'Their Visa 1111', $other);
        $off = Gateway::query()->create(['extension' => 'AdvCard', 'name' => 'OffCo', 'type' => 'gateway', 'enabled' => false]);
        $offSaved = $this->saved($off, 'Off Visa 2222');
        $invoice = $this->invoice(renewal: true);
        $plain = __('invoices.pay_amount', ['amount' => $invoice->formattedRemaining]);

        $page = $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->assertDontSee('Their Visa 1111')->assertDontSee('OffCo')->assertDontSee('Off Visa 2222');
        foreach ([$theirs->ulid, 'gateway-' . $off->id, $offSaved->ulid, 'gateway-999999'] as $value) {
            $page->set('selectedMethod', $value)->assertSet('renewalsChoice', null)->assertDontSee('Their Visa 1111')->assertDontSee('OffCo');
            $this->assertSame($plain, $page->get('payLabel'), "the button for {$value}");
            $page->call('processPayment')->assertNoRedirect();
        }
        $this->assertSame(0, $invoice->transactions()->count());
    }

    /**
     * Pin (passes on the PR head) · A gateway that answers with its own form (Stripe's) still opens it in the window, and
     * its close button calls exitPay, which comes back to the page.
     */
    public function test_pin_597_a_gateways_own_form_opens_in_the_window(): void
    {
        $invoice = $this->invoice();

        $page = $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->assertDontSeeHtml('adv-gateway-form')
            ->set('selectedMethod', 'gateway-' . $this->form->id)->call('processPayment')
            ->assertSeeHtml('<form id="adv-gateway-form">')->assertSeeHtml('wire:click="exitPay"');
        $page->call('exitPay')->assertRedirect(route('invoices.show', $invoice));
        $this->assertSame($this->form->id, (int) $invoice->fresh()->preferred_gateway_id, 'the way chosen is kept');
    }

    /**
     * Pin (passes on the PR head) · A login with two accounts (docs/accounts.md, «Τρέχων λογαριασμός»), on its own account,
     * opens the other account's invoice: the panel shows that account's methods and balance, never the current account's.
     */
    public function test_pin_597_another_accounts_invoice_shows_only_that_accounts_methods_and_balance(): void
    {
        config(['settings.credits_enabled' => true]);
        $mine = $this->saved($this->card, 'My Visa 4242');
        Credit::query()->create(['client_id' => $this->client->id, 'currency_code' => 'USD', 'amount' => '7.77']);
        $this->client->forceFill(['default_billing_agreement_id' => $mine->id, 'auto_charge' => true])->save();

        $other = User::factory()->create()->client();
        ClientUser::query()->create(['client_id' => $other->id, 'user_id' => $this->user->id, 'permissions' => null, 'granted_at' => now()]);
        $theirs = $this->saved($this->vault, 'Their PayPal b@example.test', $other, 'paypal');
        Credit::query()->create(['client_id' => $other->id, 'currency_code' => 'USD', 'amount' => '3.33']);
        $other->forceFill(['auto_charge' => false])->save(); // its PayPal is its default (the first saved), automatic payments off
        $invoice = $this->invoice(renewal: true, client: $other);

        $page = $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->assertSee('Their PayPal b@example.test')->assertDontSee('My Visa 4242')
            ->assertSee('3.33')->assertDontSee('7.77')
            ->assertDontSee(__('invoices.pay_panel.automatic'));
        $this->assertSame(0, substr_count($page->html(), 'value="' . $mine->ulid . '"'));
        $page->set('selectedMethod', $mine->ulid)->assertSet('renewalsChoice', null)
            ->assertSet('payLabel', __('invoices.pay_amount', ['amount' => $invoice->formattedRemaining]));
        $page->set('selectedMethod', $theirs->ulid)->assertSet('renewalsChoice', 'toggle');
    }
}
