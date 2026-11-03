<?php

namespace Tests\Feature;

use App\Livewire\Invoices\Show;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Support\PayOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The ways to pay by provider, on the invoice's page (docs/pay-flow-ux.md, PR 3): each gateway once, its saved methods
 * inside it and its one-time payment after them; the same choices as the old window, so processPayment is unchanged.
 */
class InvoicePayPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Gateway $card;

    private Gateway $vault;

    private Gateway $bank;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['PpCard' => true, 'PpVault' => true, 'PpBank' => false] as $name => $saves) {
            if (!class_exists("MyCS\\Extensions\\Gateways\\{$name}\\{$name}")) {
                $agreements = $saves ? 'public function supportsBillingAgreements() { return true; } public function cancelBillingAgreement($agreement): bool { return true; }' : '';
                eval("namespace MyCS\\Extensions\\Gateways\\{$name}; class {$name} { public function __construct(\$config = []) {} {$agreements}
                    public function pay(\$invoice, \$total) { return 'https://pay.example.test/{$name}'; } }");
            }
        }
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        $this->card = Gateway::query()->create(['extension' => 'PpCard', 'name' => 'CardCo', 'type' => 'gateway', 'enabled' => true]);
        $this->vault = Gateway::query()->create(['extension' => 'PpVault', 'name' => 'VaultCo', 'type' => 'gateway', 'enabled' => true]);
        $this->bank = Gateway::query()->create(['extension' => 'PpBank', 'name' => 'Bank transfer', 'type' => 'gateway', 'enabled' => true]);
    }

    private function saved(Gateway $gateway, string $name, string $type = 'visa'): BillingAgreement
    {
        return BillingAgreement::query()->create(['ulid' => (string) Str::ulid(), 'client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => $name, 'type' => $type, 'external_reference' => 'pm_' . Str::random(8)]);
    }

    private function invoice(bool $renewal = false): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        if (!$renewal) {
            $invoice->items()->create(['description' => 'Setup', 'price' => '20.00', 'quantity' => 1]);

            return $invoice->fresh();
        }
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 20, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 20, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE]);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '20.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);

        return $invoice->fresh();
    }

    public function test_each_provider_once_with_its_saved_methods_inside_it(): void
    {
        $mc = $this->saved($this->card, 'Mastercard 5100', 'mastercard');
        $visa = $this->saved($this->card, 'Visa 4242');
        $paypal = $this->saved($this->vault, 'PayPal a@example.test', 'paypal');

        $groups = PayOptions::byProvider(collect([$mc, $visa, $paypal])->each->load('gateway'), [$this->bank, $this->vault, $this->card], $visa->id);

        $this->assertSame([$this->card->id, $this->vault->id, $this->bank->id], array_map(fn ($group) => $group['gateway']->id, $groups),
            'the default\'s provider first, then saved methods, then the rest in their order');
        $this->assertSame([$visa->id, $mc->id], array_map(fn ($method) => $method->id, $groups[0]['saved']), 'the default first');
        $this->assertTrue($groups[0]['oneTime']);
        $this->assertSame([], $groups[2]['saved']);
        // A saved method's provider that takes no one-time payment keeps its methods, without a «pay once» row.
        $groups = PayOptions::byProvider(collect([$paypal])->each->load('gateway'), [$this->bank]);
        $this->assertFalse($groups[0]['oneTime']);
    }

    public function test_the_page_shows_the_ways_on_it_with_the_same_choices_as_before(): void
    {
        $visa = $this->saved($this->card, 'Visa 4242');
        $this->saved($this->vault, 'PayPal a@example.test', 'paypal');
        $invoice = $this->invoice();

        $page = $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice]);
        $page->assertSeeHtml('id="pay"')->assertSeeInOrder(['CardCo', 'Visa 4242', __('invoices.pay_panel.once'), 'VaultCo', 'PayPal a@example.test', 'Bank transfer'])
            ->assertDontSee('Or pay with a one-time method');
        foreach ([$visa->ulid, 'gateway-' . $this->card->id, 'gateway-' . $this->vault->id, 'gateway-' . $this->bank->id] as $value) {
            $this->assertSame(1, substr_count($page->html(), 'value="' . $value . '"'), "one choice for {$value}");
        }

        // The button names what it does.
        $page->set('selectedMethod', $visa->ulid)->assertSee(__('invoices.pay_panel.pay_with', ['amount' => $invoice->formattedRemaining, 'method' => 'Visa 4242']));
        $page->set('selectedMethod', 'gateway-' . $this->bank->id)
            ->assertSee(__('invoices.pay_panel.continue_with', ['gateway' => 'Bank transfer', 'amount' => $invoice->formattedRemaining]));
        // And chooses as before: a gateway opens its own page.
        $page->call('processPayment')->assertRedirect('https://pay.example.test/PpBank');
    }

    public function test_the_balance_is_offered_as_before(): void
    {
        config(['settings.credits_enabled' => true]);
        Credit::query()->create(['client_id' => $this->client->id, 'currency_code' => 'USD', 'amount' => '5.00']);
        $invoice = $this->invoice();

        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->assertSee(__('invoices.account_credits'))
            ->set('selectedMethod', 'credit')->assertSee(__('invoices.apply_credit_and_continue', ['amount' => Credit::query()->first()->formattedAmount]));

        config(['settings.credits_enabled' => false]);
        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])->assertDontSee(__('invoices.account_credits'));
    }

    public function test_the_renewals_toggle_shows_only_when_it_would_change_something(): void
    {
        $visa = $this->saved($this->card, 'Visa 4242');
        $mc = $this->saved($this->card, 'Mastercard 5100', 'mastercard');
        $this->client->forceFill(['default_billing_agreement_id' => $visa->id, 'auto_charge' => true])->save();
        $invoice = $this->invoice(renewal: true);

        $page = $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice]);
        $page->set('selectedMethod', $mc->ulid)->assertSet('renewalsChoice', 'toggle')->assertSee(__('invoices.use_for_recurring'));
        $page->set('selectedMethod', $visa->ulid)->assertSet('renewalsChoice', 'already')->assertSee(__('invoices.pay_panel.renewals_already'))
            ->assertDontSee(__('invoices.use_for_recurring'));
        // Automatic payments off: the default method would still turn them on.
        $this->client->update(['auto_charge' => false]);
        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice->fresh()])->set('selectedMethod', $visa->ulid)->assertSet('renewalsChoice', 'toggle');
        // A one-time payment, or an invoice with no renewal: none.
        $page->set('selectedMethod', 'gateway-' . $this->card->id)->assertSet('renewalsChoice', null);
        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $this->invoice()])->set('selectedMethod', $visa->ulid)->assertSet('renewalsChoice', null);
    }

    public function test_an_emails_pay_link_brings_the_customer_to_the_ways_and_a_held_invoice_shows_none(): void
    {
        $invoice = $this->invoice();
        // (Livewire's test manager keeps query params for the next test() of the method: the plain page first.)
        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])->assertSeeHtml('id="pay"')->assertDontSeeHtml('scrollIntoView');
        $this->livewireAs($this->user)->withQueryParams(['pay' => 1])->test(Show::class, ['invoice' => $invoice])
            ->assertSeeHtml('id="pay"')->assertSeeHtml('scrollIntoView');

        $visa = $this->saved($this->card, 'Visa 4242');
        ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $invoice->id, 'client_id' => $this->client->id,
            'billing_agreement_id' => $visa->id, 'amount' => '20.00', 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CLIENT,
            'status' => ChargeAttempt::STATUS_UNKNOWN, 'started_at' => now(), 'sent_at' => now()]);
        $this->livewireAs($this->user)->withQueryParams(['pay' => 1])->test(Show::class, ['invoice' => $invoice->fresh()])
            ->assertDontSeeHtml('id="pay"')->assertDontSee(__('invoices.pay_panel.title'));
    }
}
