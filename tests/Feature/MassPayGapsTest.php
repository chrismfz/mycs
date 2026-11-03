<?php

namespace Tests\Feature;

use App\Exceptions\ChargeNeedsAction;
use App\Exceptions\DisplayException;
use App\Livewire\Invoices\PayTogether;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\PaymentGroup;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\MassPay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use MyCS\Extensions\Gateways\MpgCard\MpgCard;
use Tests\TestCase;

/**
 * Three Mass Pay gaps found by the adversarial review of #599: an invoice a gateway subscription pays, Mass Pay switched
 * off with a group open, and a saved method the bank asked to confirm (3-D Secure).
 */
class MassPayGapsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Gateway $card;

    protected function setUp(): void
    {
        parent::setUp();
        // MpgCard carries a group and saves methods; its charge answers as told.
        if (!class_exists('MyCS\\Extensions\\Gateways\\MpgCard\\MpgCard')) {
            eval('namespace MyCS\\Extensions\\Gateways\\MpgCard; class MpgCard { public static $answer = null; public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; } public function cancelBillingAgreement($agreement): bool { return true; }
                public function supportsPaymentGroups() { return true; }
                public function charge($invoice, $amount, $agreement) { return (self::$answer)(); }
                public function pay($invoice, $total) { return "https://pay.example.test/group/" . $invoice->payingGroup; } }');
        }
        MpgCard::$answer = fn () => true;
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        $this->card = Gateway::query()->create(['extension' => 'MpgCard', 'name' => 'CardCo', 'type' => 'gateway', 'enabled' => true]);
    }

    private function saved(string $name): BillingAgreement
    {
        return BillingAgreement::query()->create(['ulid' => (string) Str::ulid(), 'client_id' => $this->client->id, 'gateway_id' => $this->card->id,
            'name' => $name, 'type' => 'visa', 'external_reference' => 'pm_' . Str::random(8)]);
    }

    /** @return list<Invoice> two unpaid invoices of the account, the first due first */
    private function invoices(): array
    {
        $invoices = [];
        foreach (['2026-11-11', '2026-11-12'] as $due) {
            $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
            $invoice->items()->create(['description' => 'Setup', 'price' => '10.00', 'quantity' => 1]);
            $invoices[] = $invoice;
        }

        return $invoices;
    }

    /** @param  list<Invoice>  $invoices */
    private function group(array $invoices): PaymentGroup
    {
        return (new MassPay)->open($this->client, array_map(fn (Invoice $invoice) => $invoice->id, $invoices), $this->user);
    }

    private function renews(Invoice $invoice, ?string $subscription): Service
    {
        $service = Service::factory()->create(['client_id' => $this->client->id, 'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD',
            'subscription_id' => $subscription]);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '0.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id]);

        return $service;
    }

    /** Gap 1: the subscription pays it on its own; paid in a group too, its second payment would become credit. */
    public function test_an_invoice_a_subscription_pays_is_not_paid_together(): void
    {
        [$first, $second] = $this->invoices();
        $this->renews($second, 'I-SUB1');
        $refusal = __('Invoice :invoice is paid by a subscription: it is paid on its own.', ['invoice' => '#' . ($second->number ?: $second->id)]);

        $this->assertSame($refusal, MassPay::refusal($second->fresh()));
        $this->assertNull(MassPay::refusal($first->fresh()));
        try {
            $this->group([$first, $second]);
            $this->fail('a group with a subscription invoice was opened');
        } catch (DisplayException $e) {
            $this->assertSame($refusal, $e->getMessage());
        }
        $this->assertSame(0, PaymentGroup::query()->count());

        // Subscribed after the group was chosen: the group is stale, nothing is taken.
        [$third, $fourth] = $this->invoices();
        $service = $this->renews($fourth, null);
        $group = $this->group([$third, $fourth]);
        $this->assertNull(MassPay::staleness($group->fresh()));
        $service->update(['subscription_id' => 'I-SUB2']);
        $this->assertNotNull(MassPay::staleness($group->fresh()));
        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group->fresh()])
            ->assertDontSeeHtml('type="radio"')
            ->set('selectedMethod', 'gateway-' . $this->card->id)->call('processPayment')->assertNoRedirect();

        // An empty subscription id (WHMCS imports) is none.
        $service->update(['subscription_id' => '']);
        $this->assertNull(MassPay::refusal($fourth->fresh()));
    }

    /** Gap 2: switched off by staff, a group already open is not paid; the page says so. */
    public function test_an_open_group_is_not_paid_once_mass_pay_is_switched_off(): void
    {
        $visa = $this->saved('Visa 4242');
        $group = $this->group($this->invoices());
        $this->storeSettings(['mass_pay_enabled' => false]);

        $this->assertSame(__('Paying several invoices at once is not available.'), MassPay::staleness($group->fresh()));
        $page = $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group->fresh()]);
        $page->assertSee(__('Paying several invoices at once is not available.'))->assertDontSeeHtml('type="radio"');
        $page->set('selectedMethod', 'gateway-' . $this->card->id)->call('processPayment')->assertNoRedirect()
            ->assertDispatched('notify', fn ($name, $params) => ($params[0]['message'] ?? null) === __('Paying several invoices at once is not available.'));
        $page->set('selectedMethod', $visa->ulid)->call('processPayment');
        $this->assertSame(0, ChargeAttempt::query()->count(), 'the saved method is not charged');

        // Switched on again, the same group is paid.
        $this->storeSettings(['mass_pay_enabled' => true]);
        $this->assertNull(MassPay::staleness($group->fresh()));
    }

    /** Gap 3: the automatic method is not chosen to start with when the bank asked to confirm it for one of the invoices. */
    public function test_a_method_the_bank_asked_to_confirm_is_not_chosen_to_start_with(): void
    {
        $visa = $this->saved('Visa 4242');
        $this->client->forceFill(['default_billing_agreement_id' => $visa->id, 'auto_charge' => true])->save();
        [$first, $second] = $this->invoices();
        $group = $this->group([$first, $second]);
        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group->fresh()])->assertSet('selectedMethod', $visa->ulid);

        // The cron's charge of the second invoice: the bank asked the customer to confirm.
        $attempt = ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $second->id, 'client_id' => $this->client->id,
            'billing_agreement_id' => $visa->id, 'amount' => '10.00', 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CRON,
            'status' => ChargeAttempt::STATUS_ACTION_REQUIRED, 'started_at' => now()->subDay(), 'sent_at' => now()->subDay(), 'finished_at' => now()->subDay()]);
        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group->fresh()])
            ->assertSet('selectedMethod', null)->assertSee('Visa 4242');

        // Asked for another method only: this one is still chosen.
        $other = $this->saved('Mastercard 5100');
        $attempt->update(['billing_agreement_id' => $other->id]);
        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group->fresh()])->assertSet('selectedMethod', $visa->ulid);
    }

    /** Gap 3: a group charge the bank asks to confirm tells the customer so, not «Could not process payment». */
    public function test_a_group_charge_the_bank_asks_to_confirm_says_so(): void
    {
        $visa = $this->saved('Visa 4242');
        $group = $this->group($this->invoices());
        MpgCard::$answer = fn () => throw new ChargeNeedsAction('3-D Secure');

        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group->fresh()])
            ->set('selectedMethod', $visa->ulid)->call('processPayment')->assertNoRedirect()
            ->assertDispatched('notify', fn ($name, $params) => ($params[0]['message'] ?? null) === __('Your bank asks you to confirm this payment: please pay these invoices with your card instead.')
                && ($params[0]['type'] ?? null) === 'error');
        $this->assertSame(ChargeAttempt::STATUS_ACTION_REQUIRED, ChargeAttempt::query()->sole()->status);

        // The page opened again: that method is not chosen for the group either (the attempt sits on its first invoice).
        $this->client->forceFill(['default_billing_agreement_id' => $visa->id, 'auto_charge' => true])->save();
        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group->fresh()])->assertSet('selectedMethod', null);
    }
}
