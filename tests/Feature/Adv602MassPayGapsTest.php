<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\ChargeNeedsAction;
use App\Livewire\Invoices\PayTogether;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\PaymentGroup;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\MassPay;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Payment\AutoCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use MyCS\Extensions\Gateways\MpgCard\MpgCard;
use Tests\TestCase;

/**
 * Adversarial review of PR #602 (Mass Pay: subscription invoices, Mass Pay switched off, 3-D Secure asked). Tests named
 * test_held_* show an attack that did not work and pass on the PR head; the ADV-602-N tests fail there and state the
 * expected behaviour.
 */
class Adv602MassPayGapsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Gateway $card;

    protected function setUp(): void
    {
        parent::setUp();
        // As MassPayGapsTest: MpgCard carries a group and saves methods; its charge answers as told.
        if (!class_exists('MyCS\\Extensions\\Gateways\\MpgCard\\MpgCard')) {
            eval('namespace MyCS\\Extensions\\Gateways\\MpgCard; class MpgCard { public static $answer = null; public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; } public function cancelBillingAgreement($agreement): bool { return true; }
                public function supportsPaymentGroups() { return true; }
                public function charge($invoice, $amount, $agreement) { return (self::$answer)(); }
                public function pay($invoice, $total) { return "https://pay.example.test/group/" . $invoice->payingGroup; } }');
        }
        MpgCard::$answer = fn () => true;
        $this->storeSettings(['credits_enabled' => true, 'credits_auto_use' => false]);
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        $this->card = Gateway::query()->create(['extension' => 'MpgCard', 'name' => 'CardCo', 'type' => 'gateway', 'enabled' => true]);
    }

    private function saved(string $name, bool $automatic = false): BillingAgreement
    {
        $agreement = BillingAgreement::query()->create(['ulid' => (string) Str::ulid(), 'client_id' => $this->client->id, 'gateway_id' => $this->card->id,
            'name' => $name, 'type' => 'visa', 'external_reference' => 'pm_' . Str::random(8)]);
        if ($automatic) {
            $this->client->forceFill(['default_billing_agreement_id' => $agreement->id, 'auto_charge' => true])->save();
        }

        return $agreement;
    }

    /** @return list<Invoice> unpaid invoices of the account of 10.00 each, due one day after another */
    private function invoices(int $count, int $from = 10): array
    {
        $invoices = [];
        for ($i = 0; $i < $count; $i++) {
            $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
                'due_at' => now()->addDays($from + $i)->toDateString()]);
            $invoice->items()->create(['description' => 'Setup', 'price' => '10.00', 'quantity' => 1]);
            $invoices[] = $invoice->fresh();
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

    /** The group charged to the method through its page, and the bank asked the customer to confirm (3-D Secure). */
    private function groupChargeAskedToConfirm(PaymentGroup $group, BillingAgreement $method): ChargeAttempt
    {
        MpgCard::$answer = fn () => throw new ChargeNeedsAction('3-D Secure');
        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group->fresh()])
            ->set('selectedMethod', $method->ulid)->call('processPayment')->assertNoRedirect();
        MpgCard::$answer = fn () => true;

        $attempt = ChargeAttempt::query()->latest('id')->firstOrFail();
        $this->assertSame(ChargeAttempt::STATUS_ACTION_REQUIRED, $attempt->status);
        $this->assertSame((int) $group->invoice_id, (int) $attempt->invoice_id, 'the group charge sits on its first invoice');
        $this->assertSame((int) $group->id, (int) $attempt->payment_group_id);

        return $attempt;
    }

    private function credit(): float
    {
        return (float) Credit::query()->where('client_id', $this->client->id)->where('currency_code', 'USD')->sum('amount');
    }

    // ---------------------------------------------------------------- findings (fail on the PR head)

    /**
     * ADV-602-1 (gap 3, low). The customer chose A and B and charged them with the account's automatic Visa: the bank asked
     * to confirm (3-D Secure). The attempt sits on A, the first invoice, with the group's id. The customer then chooses B
     * and C (A left out). The new group's page preselects that Visa again, because askedToConfirm() reads only each
     * invoice's own attempts: the charge that covered B (SavedMethodCharge::onItsWay counts a group's charge as one of B:
     * adversarial ADV-1) is not seen. Pressing the button asks the bank again.
     *
     * Expected: a method whose last charge covering one of the group's invoices (its own, or of a Mass Pay group it was in)
     * asked to confirm is not chosen to start with.
     */
    public function test_adv_602_1_a_method_asked_to_confirm_for_an_earlier_group_is_not_chosen_for_a_new_group_without_its_lead(): void
    {
        $visa = $this->saved('Visa 4242', automatic: true);
        [$a, $b, $c] = $this->invoices(3);
        $this->groupChargeAskedToConfirm($this->group([$a, $b]), $visa);

        $again = $this->group([$b, $c]);
        $this->assertSame((int) $b->id, (int) $again->invoice_id);

        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $again->fresh()])
            ->assertSee('Visa 4242')
            ->assertSet('selectedMethod', null);
    }

    /**
     * ADV-602-2 (gap 2, low). A group was paid (the gateway's payment spread over its invoices). Staff then switch Mass Pay
     * off. The customer opens the group's page again (a bookmark, the browser's back button): staleness() answers «Paying
     * several invoices at once is not available.» before it reads the group's status, so the page tells the customer
     * that paying together is unavailable instead of that these invoices were paid. A customer who just paid may think the
     * payment did not go through and pay each invoice again. The same for a replaced group: «choose them again» is lost.
     *
     * Expected: a paid or replaced group says so whatever the switch; the switch only refuses an open group.
     */
    public function test_adv_602_2_a_paid_group_says_it_was_paid_when_mass_pay_is_switched_off(): void
    {
        [$a, $b] = $this->invoices(2);
        $group = $this->group([$a, $b]);
        (new RecordPaymentService)->handle($a->id, $this->card, '20.00', transactionId: 'pay_adv6022', paymentGroup: $group->ulid);
        $this->assertSame(PaymentGroup::STATUS_PAID, $group->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $b->fresh()->status);

        $this->storeSettings(['mass_pay_enabled' => false]);

        $paid = __('These invoices were paid or chosen again: choose them again from your invoices.');
        $this->assertSame($paid, MassPay::staleness($group->fresh()));
        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group->fresh()])
            ->assertSee($paid)->assertDontSee(__('Paying several invoices at once is not available.'));
    }

    /**
     * ADV-602-3 (gap 3, nearby, low; the owner's call). After the group charge of A and B asked to confirm (the attempt on
     * A), the cron leaves A to the customer (AutoCharge::agreementFor: its last attempt asked to confirm) but still charges
     * B to the same Visa on its due date, for the same card the bank just asked about: it asks again, and the customer gets
     * a «payment failed» email for B too. The PR's own rule (mount) says it follows agreementFor, so both read the
     * group's attempt the same narrow way.
     *
     * Expected: the cron reads a group charge that asked to confirm as one of each of the group's invoices, as
     * SavedMethodCharge::onItsWay reads a group charge's hold (then B is paid by the customer, or by another method).
     */
    public function test_adv_602_3_the_cron_does_not_charge_a_group_invoice_to_a_method_the_bank_asked_to_confirm_for_its_group(): void
    {
        $this->markTestIncomplete('ADV-602-3, open for the owner: the cron (AutoCharge::agreementFor) reads only an invoice\'s own'
            . ' attempts, so a group charge that asked to confirm (on the first invoice) does not keep the others from the same'
            . ' method; already so before #602, and an off-session cron charge may pass where the group charge did not.');
        $visa = $this->saved('Visa 4242', automatic: true);
        [$a, $b] = $this->invoices(2);
        $this->assertSame((int) $visa->id, (int) AutoCharge::agreementFor($b->fresh())?->id, 'charged to the Visa before');

        $this->groupChargeAskedToConfirm($this->group([$a, $b]), $visa);

        $this->assertNull(AutoCharge::agreementFor($a->fresh()), 'the lead is left to the customer (held: ADV-7)');
        $this->assertNull(AutoCharge::agreementFor($b->fresh()), 'B was in the charge the bank asked to confirm');
    }

    // ---------------------------------------------------------------- held (pass on the PR head)

    /**
     * Held (gap 2): a checkout sent for the group before Mass Pay was switched off still pays the whole group when its
     * payment lands with the group's reference (groupPaidBy/spread do not read the switch): nothing is left as credit.
     */
    public function test_held_a_checkout_sent_before_the_switch_is_spread_after_it(): void
    {
        [$a, $b, $c] = $this->invoices(3);
        $group = $this->group([$a, $b, $c]);
        $this->assertSame('https://pay.example.test/group/' . $group->ulid, $this->livewireAs($this->user)
            ->test(PayTogether::class, ['paymentGroup' => $group->fresh()])
            ->set('selectedMethod', 'gateway-' . $this->card->id)->call('processPayment')->effects['redirect'] ?? null);

        $this->storeSettings(['mass_pay_enabled' => false]);
        (new RecordPaymentService)->handle($a->id, $this->card, '30.00', transactionId: 'pay_held_off', paymentGroup: $group->ulid);

        foreach ([$a, $b, $c] as $invoice) {
            $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        }
        $this->assertSame(PaymentGroup::STATUS_PAID, $group->fresh()->status);
        $this->assertSame(0.0, $this->credit());
    }

    /**
     * Held (gap 2): a processing group payment (SEPA) linked to the group while Mass Pay was on, completed without the
     * reference after it was switched off, is still spread (link() kept it on the group).
     */
    public function test_held_a_processing_group_payment_completed_after_the_switch_is_spread(): void
    {
        [$a, $b] = $this->invoices(2);
        $group = $this->group([$a, $b]);
        (new RecordPaymentService)->handle($a->id, $this->card, '20.00', transactionId: 'sepa_held', status: InvoiceTransactionStatus::Processing,
            paymentGroup: $group->ulid);
        $this->assertNotNull($group->fresh()->invoice_transaction_id);

        $this->storeSettings(['mass_pay_enabled' => false]);
        (new RecordPaymentService)->handle($a->id, $this->card, '20.00', transactionId: 'sepa_held');

        $this->assertSame(Invoice::STATUS_PAID, $a->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $b->fresh()->status);
        $this->assertSame(0.0, $this->credit());
    }

    /**
     * Held (gap 2): switched off, a crafted call cannot pay a replaced group either, nor open a new one; the open group's
     * gateway is not opened.
     */
    public function test_held_switched_off_no_crafted_call_pays_or_opens_a_group(): void
    {
        $visa = $this->saved('Visa 4242');
        [$a, $b, $c] = $this->invoices(3);
        $old = $this->group([$a, $b]);
        $open = $this->group([$a, $c]);
        $this->assertSame(PaymentGroup::STATUS_REPLACED, $old->fresh()->status);
        $this->storeSettings(['mass_pay_enabled' => false]);

        foreach ([$old, $open] as $group) {
            foreach ([$visa->ulid, 'gateway-' . $this->card->id] as $way) {
                $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group->fresh()])
                    ->set('selectedMethod', $way)->call('processPayment')->assertNoRedirect();
            }
        }
        $this->assertSame(0, ChargeAttempt::query()->count());
        $this->expectExceptionMessage(__('Paying several invoices at once is not available.'));
        $this->group([$b, $c]);
    }

    /**
     * Held (gap 1): the group's FIRST invoice's service gets a subscription after the group was chosen: the group is
     * stale and neither its gateway nor a saved method is used. A subscription id of only a space or «0» is a subscription
     * as for AutoCharge (PHP (string) !== ''), on SQLite; NULL and '' are none.
     */
    public function test_held_a_subscription_on_the_lead_after_the_choice_makes_the_group_stale(): void
    {
        $visa = $this->saved('Visa 4242', automatic: true);
        [$a, $b] = $this->invoices(2);
        $service = $this->renews($a, null);
        $group = $this->group([$a, $b]);
        $this->assertSame((int) $a->id, (int) $group->invoice_id);
        $service->update(['subscription_id' => 'I-LEAD']);

        $this->assertNotNull(MassPay::staleness($group->fresh()));
        foreach ([$visa->ulid, 'gateway-' . $this->card->id] as $way) {
            $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group->fresh()])
                ->set('selectedMethod', $way)->call('processPayment')->assertNoRedirect();
        }
        $this->assertSame(0, ChargeAttempt::query()->count());

        foreach ([['0', true], [' ', true], ['', false], [null, false]] as [$id, $refused]) {
            $service->update(['subscription_id' => $id]);
            $this->assertSame($refused, MassPay::refusal($a->fresh()) !== null, 'subscription id ' . var_export($id, true));
        }
    }

    /**
     * Held (gap 3): the group charge asked to confirm on its lead A; a new group with A as its second invoice (B due
     * first) still finds it (askedToConfirm reads every invoice of the group). A later declined charge of A to the same
     * method makes it chosen again, as AutoCharge::agreementFor reads the last attempt only.
     */
    public function test_held_the_lead_attempt_is_found_when_the_lead_is_not_first_and_a_later_attempt_clears_it(): void
    {
        $visa = $this->saved('Visa 4242', automatic: true);
        [$b, $a] = $this->invoices(2); // b due first
        $c = $this->invoices(1, from: 20)[0];
        $led = $this->group([$a, $c]);
        $this->assertSame((int) $a->id, (int) $led->invoice_id);
        $this->groupChargeAskedToConfirm($led, $visa);

        $again = $this->group([$b, $a]);
        $this->assertSame((int) $b->id, (int) $again->invoice_id);
        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $again->fresh()])->assertSet('selectedMethod', null);

        ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $a->id, 'client_id' => $this->client->id,
            'billing_agreement_id' => $visa->id, 'amount' => '10.00', 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CRON,
            'status' => ChargeAttempt::STATUS_FAILED, 'started_at' => now(), 'sent_at' => now(), 'finished_at' => now()]);
        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $again->fresh()])->assertSet('selectedMethod', $visa->ulid);
    }

    /**
     * Held (gap 3): after a group charge asked to confirm, nothing holds the group's invoices (a charge that took nothing:
     * ADV-593-5), so the customer can pay them with the card gateway at once, as the message says; the gateway's payment
     * pays the whole group.
     */
    public function test_held_after_the_bank_asked_the_group_is_paid_with_the_card_gateway(): void
    {
        $visa = $this->saved('Visa 4242', automatic: true);
        [$a, $b] = $this->invoices(2);
        $group = $this->group([$a, $b]);
        $this->groupChargeAskedToConfirm($group, $visa);

        $this->assertNull(MassPay::staleness($group->fresh()));
        $page = $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group->fresh()])
            ->assertSet('selectedMethod', null)
            ->set('selectedMethod', 'gateway-' . $this->card->id)->call('processPayment');
        $this->assertSame('https://pay.example.test/group/' . $group->ulid, $page->effects['redirect'] ?? null);

        (new RecordPaymentService)->handle($a->id, $this->card, '20.00', transactionId: 'pay_after_3ds', paymentGroup: $group->ulid);
        $this->assertSame(Invoice::STATUS_PAID, $b->fresh()->status);
        $this->assertSame(0.0, $this->credit());
    }
}
