<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Livewire\Invoices\PayTogether;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentGroup;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\MassPay;
use App\Services\Invoice\PaymentReminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Tests\TestCase;

/**
 * Adversarial review of #599: a Mass Pay group's page on the invoice page's panel, the automatic-payments method chosen to
 * start with, and a payment on its way told as such. The pins (marked) hold rules the PR keeps; the others fail on its head.
 */
class Adv599PayTogetherTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    /** Carries a group and saves methods (as Stripe). */
    private Gateway $card;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('MyCS\\Extensions\\Gateways\\A599Card\\A599Card')) {
            // A599Card: saves methods, carries a group. A599NoGroup: saves methods, carries no group. A599NoVault: carries a
            // group, saves no methods now (PayPal with its vault turned off). A599Bank: neither (a bank transfer).
            eval('namespace MyCS\\Extensions\\Gateways\\A599Card; class A599Card { public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; } public function cancelBillingAgreement($agreement): bool { return true; }
                public function supportsPaymentGroups() { return true; }
                public function charge($invoice, $total, $agreement, $key = null) { return true; }
                public function pay($invoice, $total) { return "https://pay.example.test/group/" . $invoice->payingGroup; } }');
            eval('namespace MyCS\\Extensions\\Gateways\\A599NoGroup; class A599NoGroup { public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; } public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $total, $agreement, $key = null) { return true; }
                public function pay($invoice, $total) { return "https://pay.example.test/nogroup"; } }');
            eval('namespace MyCS\\Extensions\\Gateways\\A599NoVault; class A599NoVault { public function __construct($config = []) {}
                public function supportsBillingAgreements() { return false; } public function cancelBillingAgreement($agreement): bool { return true; }
                public function supportsPaymentGroups() { return true; }
                public function charge($invoice, $total, $agreement, $key = null) { return true; }
                public function pay($invoice, $total) { return "https://pay.example.test/novault/" . $invoice->payingGroup; } }');
            eval('namespace MyCS\\Extensions\\Gateways\\A599Bank; class A599Bank { public function __construct($config = []) {}
                public function pay($invoice, $total) { return "https://pay.example.test/bank"; } }');
        }
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        $this->card = Gateway::query()->create(['extension' => 'A599Card', 'name' => 'CardCo', 'type' => 'gateway', 'enabled' => true]);
    }

    private function saved(string $name, ?Gateway $gateway = null, ?Client $client = null): BillingAgreement
    {
        return BillingAgreement::query()->create(['ulid' => (string) Str::ulid(), 'client_id' => ($client ?? $this->client)->id,
            'gateway_id' => ($gateway ?? $this->card)->id, 'name' => $name, 'type' => 'visa', 'external_reference' => 'pm_' . Str::random(8)]);
    }

    private function invoice(string $due): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Setup', 'price' => '10.00', 'quantity' => 1]);

        return $invoice;
    }

    /** @param list<Invoice>|null $invoices */
    private function group(?array $invoices = null): PaymentGroup
    {
        $invoices ??= [$this->invoice('2026-11-11'), $this->invoice('2026-11-12')];

        return (new MassPay)->open($this->client, array_map(fn (Invoice $invoice) => $invoice->id, $invoices), $this->user);
    }

    private function attempt(PaymentGroup $group, BillingAgreement $method, string $status): ChargeAttempt
    {
        return ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $group->invoice_id, 'client_id' => $this->client->id,
            'billing_agreement_id' => $method->id, 'payment_group_id' => $group->id, 'amount' => (string) $group->total, 'currency_code' => 'USD',
            'source' => ChargeAttempt::SOURCE_GROUP, 'status' => $status, 'started_at' => now(), 'sent_at' => now(),
            'finished_at' => in_array($status, [ChargeAttempt::STATUS_STARTED], true) ? null : now()]);
    }

    private function automatic(BillingAgreement $method): void
    {
        // A query, not the model: the observer refuses another account's method, a row written another way (an import) is not.
        Client::query()->whereKey($this->client->id)->update(['default_billing_agreement_id' => $method->id, 'auto_charge' => true]);
        $this->client->refresh();
    }

    /**
     * ADV-599-1. Severity: Low (a rule of docs/payments.md, not money). «Ένα τιμολόγιο που πληρώνεται μόνο του (συνδρομή ή
     * αποθηκευμένη μέθοδος στη λήξη του) δεν προεπιλέγει τίποτα», and the invoice page keeps it (Show::preferredChoice,
     * PaymentReminders::chargedOnItsOwn). The group page chooses the automatic-payments method to start with whatever the
     * invoices are: two renewals the cron will charge on their own due date with that very card open with it chosen, the
     * button «Pay 20.00 USD with Visa 4242», one click from paying today what the cron pays on the due date. Each invoice's
     * page, for the same invoice, chooses nothing.
     */
    public function test_adv_599_1_a_group_of_invoices_paid_on_their_own_chooses_nothing_as_each_invoice_page_does(): void
    {
        $visa = $this->saved('Visa 4242');
        $this->automatic($visa);
        $product = $this->createProduct();
        $invoices = [];
        foreach ([now()->addDays(3), now()->addDays(4)] as $due) {
            $service = Service::factory()->create(['client_id' => $this->client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
                'status' => Service::STATUS_ACTIVE, 'expires_at' => $due, 'currency_code' => 'USD', 'price' => 10.00, 'subscription_id' => null]);
            $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
            $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);
            $invoices[] = $invoice->fresh();
        }
        foreach ($invoices as $invoice) {
            $this->assertTrue(PaymentReminders::chargedOnItsOwn($invoice), 'setup: the cron charges each one on its own');
        }

        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $this->group($invoices)])
            ->assertSet('selectedMethod', null);
    }

    /**
     * ADV-599-2. Severity: Low. The PR's goal: a payment on its way is told as such, «not as "changed", which the customer
     * could not fix by choosing again». `held` is read only for an open group, so a replaced one (the customer chose other
     * invoices since) with its own saved-method charge still unsettled says «These invoices were paid or chosen again:
     * choose them again» — and choosing them again is refused (MassPay::refusal: «still being processed»). The same dead
     * end the PR set out to remove, for the group whose charge it is.
     */
    public function test_adv_599_2_a_replaced_group_with_its_own_charge_on_its_way_says_so(): void
    {
        $visa = $this->saved('Visa 4242');
        $first = $this->group();
        $this->attempt($first, $visa, ChargeAttempt::STATUS_UNKNOWN);
        // The customer chose two other invoices since: the first group is replaced, its charge still unsettled.
        $this->group([$this->invoice('2026-11-13'), $this->invoice('2026-11-14')]);
        $this->assertSame(PaymentGroup::STATUS_REPLACED, $first->fresh()->status, 'setup');
        $this->assertNotNull(MassPay::refusal($first->invoices()->first()), 'setup: choosing them again is refused');

        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $first->fresh()])
            ->assertSee(__('invoices.mass_pay_held_title'))
            ->assertDontSee(__('These invoices were paid or chosen again: choose them again from your invoices.'));
    }

    /**
     * ADV-599-3. Severity: Info (wording to the customer). The note under the ways says «Your account balance, and ways that
     * take no group payment (bank transfer, say), are used on each invoice's page» on every group page: with credits off
     * (settings.credits_enabled) and no bank transfer at all it points the customer to a balance and a transfer that no
     * invoice page offers.
     */
    public function test_adv_599_3_the_note_names_no_balance_or_transfer_that_does_not_exist(): void
    {
        config(['settings.credits_enabled' => false]);

        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $this->group()])
            ->assertSeeHtml('id="pay-title"')
            ->assertDontSee(__('invoices.mass_pay_not_here'));
    }

    /**
     * PIN (ADV-599-P1). Severity: High if broken. The method chosen to start with is never one the server refuses for the
     * group: a gateway that carries no group, a disabled gateway, a gateway that saves no methods now (vault off), a method
     * stopped at autocharge_max_failures, and another account's method set as default. For each, nothing is chosen and a
     * crafted press with it charges nothing.
     */
    public function test_pin_the_method_chosen_to_start_with_is_never_one_the_server_refuses(): void
    {
        config(['settings.autocharge_max_failures' => 3]);
        $noGroup = Gateway::query()->create(['extension' => 'A599NoGroup', 'name' => 'NoGroupCo', 'type' => 'gateway', 'enabled' => true]);
        $noVault = Gateway::query()->create(['extension' => 'A599NoVault', 'name' => 'NoVaultCo', 'type' => 'gateway', 'enabled' => true]);
        $disabled = Gateway::query()->create(['extension' => 'A599Card', 'name' => 'OffCo', 'type' => 'gateway', 'enabled' => false]);
        $other = User::factory()->create()->client();
        $stopped = $this->saved('Stopped 0001');
        $stopped->forceFill(['failed_count' => 3])->save();
        $cases = [
            'no group' => $this->saved('NoGroup 1111', $noGroup),
            'vault off' => $this->saved('NoVault 2222', $noVault),
            'disabled gateway' => $this->saved('Off 3333', $disabled),
            'stopped' => $stopped,
            'another account' => $this->saved('Theirs 4444', null, $other),
        ];

        foreach ($cases as $case => $method) {
            $this->automatic($method);
            $page = $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $this->group()]);
            $this->assertNull($page->get('selectedMethod'), "$case: chosen to start with");
            $page->assertDontSee(__('invoices.pay_panel.automatic'));
            if ($case !== 'stopped') {
                // Stopped is still offered (a press by the customer may charge it, as on the invoice page); the others are not.
                $page->assertDontSee($method->name);
                $page->set('selectedMethod', $method->ulid)->call('processPayment');
                $this->assertSame(0, ChargeAttempt::query()->count(), "$case: charged by a crafted press");
            }
        }
    }

    /**
     * PIN (ADV-599-P2). Severity: Medium if broken. A group charge the bank declined, or one it asked to confirm, took
     * nothing: the group is not «on its way», the panel is shown, and the server opens the gateway (ADV-593-5).
     */
    public function test_pin_a_declined_or_unconfirmed_group_charge_holds_nothing(): void
    {
        $visa = $this->saved('Visa 4242');
        foreach ([ChargeAttempt::STATUS_FAILED, ChargeAttempt::STATUS_ACTION_REQUIRED] as $status) {
            $group = $this->group();
            $this->attempt($group, $visa, $status);

            $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group->fresh()])
                ->assertDontSee(__('invoices.mass_pay_held_title'))->assertSeeHtml('id="pay-title"')
                ->set('selectedMethod', 'gateway-' . $this->card->id)->call('processPayment')
                ->assertRedirect('https://pay.example.test/group/' . $group->ulid);
        }
    }

    /**
     * PIN (ADV-599-P3). Severity: High if broken. «On its way» never hides a group the server refuses for another reason
     * without saying so, and it is shown whenever the server refuses for a payment on its way: a processing gateway payment
     * of a non-lead invoice, or of an older group (replaced) that shares an invoice with this one.
     */
    public function test_pin_a_payment_on_its_way_of_any_invoice_of_the_group_holds_it_and_the_server_agrees(): void
    {
        // A non-lead invoice paid on its own page by SEPA after the group was chosen.
        $group = $this->group();
        $other = $group->invoices()->get()->firstWhere('id', '!=', $group->invoice_id);
        InvoiceTransaction::query()->create(['invoice_id' => $other->id, 'gateway_id' => $this->card->id, 'amount' => '10.00',
            'transaction_id' => 'tr_' . Str::random(8), 'status' => InvoiceTransactionStatus::Processing]);
        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group->fresh()])
            ->assertSee(__('invoices.mass_pay_held_title'))->assertDontSeeHtml('type="radio"')
            ->set('selectedMethod', 'gateway-' . $this->card->id)->call('processPayment')->assertNoRedirect();

        // An older group's processing checkout payment (on its lead) pays a shared invoice: the newer group is held too.
        $shared = $this->invoice('2026-11-20');
        $old = $this->group([$this->invoice('2026-11-19'), $shared]);
        $newer = $this->group([$shared, $this->invoice('2026-11-21')]);
        $payment = InvoiceTransaction::query()->create(['invoice_id' => $old->invoice_id, 'gateway_id' => $this->card->id, 'amount' => (string) $old->total,
            'transaction_id' => 'tr_' . Str::random(8), 'status' => InvoiceTransactionStatus::Processing]);
        $old->update(['invoice_transaction_id' => $payment->id]);
        $this->assertSame(PaymentGroup::STATUS_REPLACED, $old->fresh()->status, 'setup');

        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $newer->fresh()])
            ->assertSee(__('invoices.mass_pay_held_title'))->assertDontSeeHtml('type="radio"')
            ->set('selectedMethod', 'gateway-' . $this->card->id)->call('processPayment')->assertNoRedirect();
    }

    /**
     * PIN (ADV-599-P4). Severity: High if broken. A paid group is neither «on its way» nor payable: nothing is offered and
     * a crafted press charges nothing.
     */
    public function test_pin_a_paid_group_is_neither_held_nor_payable(): void
    {
        $visa = $this->saved('Visa 4242');
        $this->automatic($visa);
        $group = $this->group();
        $group->update(['status' => PaymentGroup::STATUS_PAID]);

        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group->fresh()])
            ->assertDontSee(__('invoices.mass_pay_held_title'))->assertDontSeeHtml('type="radio"')
            ->assertSee(__('These invoices were paid or chosen again: choose them again from your invoices.'))
            ->call('processPayment')->assertNoRedirect();
        $this->assertSame(0, ChargeAttempt::query()->count());
    }

    /**
     * PIN (ADV-599-P5). Severity: High if broken. Crafted updates choose or pay nothing not offered: another account's
     * saved method, the bank transfer, a gateway id with trailing text (the button may name it, the server refuses it), and
     * the group itself (locked).
     */
    public function test_pin_crafted_updates_pay_nothing_not_offered(): void
    {
        $bank = Gateway::query()->create(['extension' => 'A599Bank', 'name' => 'Bank transfer', 'type' => 'gateway', 'enabled' => true]);
        $theirs = $this->saved('Theirs 4444', null, User::factory()->create()->client());
        $group = $this->group();
        $page = $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group])->assertDontSee('Theirs 4444');

        $page->set('selectedMethod', $theirs->ulid)->call('processPayment')->assertNoRedirect();
        $page->set('selectedMethod', 'gateway-' . $bank->id)->call('processPayment')->assertNoRedirect();
        $page->set('selectedMethod', 'gateway-' . $this->card->id . 'x')->call('processPayment')->assertNoRedirect();
        $this->assertSame(0, ChargeAttempt::query()->count());

        // Another account's group: the locked property refuses the update.
        $stranger = User::factory()->create();
        $theirInvoices = [];
        foreach (['2026-11-11', '2026-11-12'] as $due) {
            $invoice = Invoice::factory()->create(['client_id' => $stranger->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
            $invoice->items()->create(['description' => 'Setup', 'price' => '10.00', 'quantity' => 1]);
            $theirInvoices[] = $invoice->id;
        }
        $theirGroup = (new MassPay)->open($stranger->client(), $theirInvoices, $stranger);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('paymentGroup', $theirGroup->id);
    }
}
