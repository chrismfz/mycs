<?php

namespace Tests\Feature;

use App\Livewire\Invoices\PayTogether;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\PaymentGroup;
use App\Models\User;
use App\Services\Invoice\MassPay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A Mass Pay group's page on the invoice page's panel, by provider (docs/pay-flow-ux.md, PR 4): the same ways as before
 * (only gateways that carry a group, no balance), and a payment on its way told as such, not as «changed».
 */
class PayTogetherPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Gateway $card;

    private Gateway $bank;

    protected function setUp(): void
    {
        parent::setUp();
        // PtCard carries a group and saves methods; PtBank does neither (a bank transfer: not offered here).
        if (!class_exists('MyCS\\Extensions\\Gateways\\PtCard\\PtCard')) {
            eval('namespace MyCS\\Extensions\\Gateways\\PtCard; class PtCard { public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; } public function cancelBillingAgreement($agreement): bool { return true; }
                public function supportsPaymentGroups() { return true; }
                public function pay($invoice, $total) { return "https://pay.example.test/group/" . $invoice->payingGroup; } }');
            eval('namespace MyCS\\Extensions\\Gateways\\PtBank; class PtBank { public function __construct($config = []) {}
                public function pay($invoice, $total) { return "https://pay.example.test/bank"; } }');
        }
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        $this->card = Gateway::query()->create(['extension' => 'PtCard', 'name' => 'CardCo', 'type' => 'gateway', 'enabled' => true]);
        $this->bank = Gateway::query()->create(['extension' => 'PtBank', 'name' => 'Bank transfer', 'type' => 'gateway', 'enabled' => true]);
    }

    private function saved(string $name): BillingAgreement
    {
        return BillingAgreement::query()->create(['ulid' => (string) Str::ulid(), 'client_id' => $this->client->id, 'gateway_id' => $this->card->id,
            'name' => $name, 'type' => 'visa', 'external_reference' => 'pm_' . Str::random(8)]);
    }

    private function group(): PaymentGroup
    {
        $ids = [];
        foreach (['2026-11-11', '2026-11-12'] as $due) {
            $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
            $invoice->items()->create(['description' => 'Setup', 'price' => '10.00', 'quantity' => 1]);
            $ids[] = $invoice->id;
        }

        return (new MassPay)->open($this->client, $ids, $this->user);
    }

    public function test_the_group_is_paid_on_the_panel_with_the_same_ways_as_before(): void
    {
        config(['settings.credits_enabled' => true]);
        Credit::query()->create(['client_id' => $this->client->id, 'currency_code' => 'USD', 'amount' => '50.00']);
        $visa = $this->saved('Visa 4242');
        $group = $this->group();

        $page = $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group]);
        $page->assertSeeHtml('id="pay-title"')->assertSeeInOrder(['CardCo', 'Visa 4242', __('invoices.pay_panel.once')])
            ->assertSee(__('invoices.mass_pay_not_here'))
            ->assertDontSee('Bank transfer')->assertDontSee(__('invoices.account_credits'));
        $html = $page->html();
        $this->assertSame(2, substr_count($html, 'type="radio"'), 'the saved card and the gateway once: no balance, no bank transfer');
        $this->assertSame(1, substr_count($html, 'value="' . $visa->ulid . '"'));
        $this->assertSame(1, substr_count($html, 'value="gateway-' . $this->card->id . '"'));

        // The button names the way and the group's total; a gateway is opened for the group, as before.
        $page->set('selectedMethod', $visa->ulid)->assertSee(__('invoices.pay_panel.pay_with', ['amount' => '20.00 USD', 'method' => 'Visa 4242']));
        $page->set('selectedMethod', 'gateway-' . $this->card->id)
            ->assertSee(__('invoices.pay_panel.continue_with', ['gateway' => 'CardCo', 'amount' => '20.00 USD']))
            ->call('processPayment')->assertRedirect('https://pay.example.test/group/' . $group->ulid);
    }

    public function test_the_accounts_automatic_method_is_chosen_to_start_with_and_marked(): void
    {
        $this->saved('Mastercard 5100');
        $visa = $this->saved('Visa 4242');
        $this->client->forceFill(['default_billing_agreement_id' => $visa->id, 'auto_charge' => true])->save();

        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $this->group()])
            ->assertSet('selectedMethod', $visa->ulid)->assertSee(__('invoices.pay_panel.automatic'))
            ->assertSeeInOrder(['Visa 4242', 'Mastercard 5100']);

        // Automatic payments off: nothing chosen, nothing marked.
        $this->client->update(['auto_charge' => false]);
        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $this->group()])
            ->assertSet('selectedMethod', null)->assertDontSee(__('invoices.pay_panel.automatic'));
    }

    public function test_a_payment_on_its_way_is_told_as_such_and_offers_nothing(): void
    {
        $visa = $this->saved('Visa 4242');
        $group = $this->group();
        ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $group->invoice_id, 'client_id' => $this->client->id,
            'billing_agreement_id' => $visa->id, 'payment_group_id' => $group->id, 'amount' => '20.00', 'currency_code' => 'USD',
            'source' => ChargeAttempt::SOURCE_GROUP, 'status' => ChargeAttempt::STATUS_UNKNOWN, 'started_at' => now(), 'sent_at' => now()]);

        $page = $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group->fresh()]);
        $page->assertSee(__('invoices.mass_pay_held_title'))->assertDontSeeHtml('id="pay-title"')->assertDontSeeHtml('type="radio"')
            ->assertDontSee(__('One of these invoices changed: choose them again from your invoices.'));
        // The server refuses as before.
        $page->set('selectedMethod', 'gateway-' . $this->card->id)->call('processPayment')->assertNoRedirect();
    }

    public function test_an_invoice_changed_meanwhile_asks_to_choose_again(): void
    {
        $group = $this->group();
        $group->invoices()->first()->items()->create(['description' => 'Late fee', 'price' => '5.00', 'quantity' => 1]);

        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group->fresh()])
            ->assertSee(__('One of these invoices changed: choose them again from your invoices.'))
            ->assertDontSee(__('invoices.mass_pay_held_title'))->assertDontSeeHtml('type="radio"');
    }

    public function test_a_crafted_choice_names_nothing_and_pays_nothing(): void
    {
        $this->saved('Visa 4242');
        $group = $this->group();

        $page = $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group])->set('selectedMethod', true);
        $this->assertSame(__('invoices.pay_amount', ['amount' => '20.00 USD']), $page->get('payLabel'));
        $page->call('processPayment')->assertNoRedirect();
        $this->assertSame(0, ChargeAttempt::query()->count());
    }
}
