<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Helpers\ExtensionHelper;
use App\Livewire\Client\Credits;
use App\Livewire\Client\PaymentMethods;
use App\Livewire\Invoices\PayTogether;
use App\Livewire\Invoices\Show;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Domains\DomainReminders;
use App\Services\Invoice\MassPay;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\CardExpiryNotices;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Gateways\AdvOff\AdvOff;
use MyCS\Extensions\Gateways\AdvOn\AdvOn;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Adversarial review of #540 (a disabled gateway is not offered to customers). Each ADV test fails on the PR head for a
 * defect; GUARD tests pass already and are kept so the fixes do not break what must keep working.
 */
class Adv540Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Gateway $on;

    private Gateway $off;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['AdvOn', 'AdvOff'] as $name) {
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
                    public function cancelBillingAgreement(\$agreement): bool { self::\$calls[] = 'cancel'; return true; }
                    public function createBillingAgreement(\$client) { self::\$calls[] = 'setup'; return 'https://{$name}.test/setup'; }
                    public function pay(\$invoice, \$total) { self::\$calls[] = 'pay'; return 'https://{$name}.test/pay'; }
                    public function charge(\$invoice, \$amount, \$agreement) { self::\$calls[] = 'charge:' . \$invoice->chargeKey; return false; }
                }");
            }
        }
        AdvOn::$calls = [];
        AdvOff::$calls = [];
        $this->on = Gateway::query()->create(['extension' => 'AdvOn', 'name' => 'AdvOn', 'type' => 'gateway', 'enabled' => true]);
        $this->off = Gateway::query()->create(['extension' => 'AdvOff', 'name' => 'AdvOff', 'type' => 'gateway', 'enabled' => false]);
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

    private function agreement(Gateway $gateway, string $ref, array $attributes = []): BillingAgreement
    {
        return BillingAgreement::query()->create($attributes + ['client_id' => $this->client->id, 'gateway_id' => $gateway->id, 'name' => 'Card ' . $ref,
            'external_reference' => $ref, 'type' => 'card']);
    }

    /**
     * ADV-540-1. Add Funds keeps the gateways it read at mount in a #[Locked] property and validates the choice against
     * that copy: a page opened before staff disabled the gateway still opens it (a new payment through a disabled gateway).
     */
    public function test_adv_540_1_add_funds_opened_before_the_gateway_was_disabled_does_not_open_it(): void
    {
        $this->storeSettings(['credits_enabled' => true, 'credits_minimum_deposit' => 20, 'credits_maximum_deposit' => 500, 'credits_maximum_credit' => 1000]);
        $page = $this->livewireAs($this->user)->test(Credits::class);
        $page->assertSet('gateway', $this->on->id);

        $this->on->update(['enabled' => false]); // staff turn it off while the page is open

        $page->call('addCredit');
        $this->assertSame([], AdvOn::$calls, 'the disabled gateway was opened from a page loaded before it was disabled');
        $this->assertSame(0, Invoice::query()->where('client_id', $this->client->id)->count(), 'no credit invoice for a gateway that is off');
    }

    /**
     * ADV-540-2. A charge whose outcome is unknown (timeout, 5xx: maybe taken) is a payment already on its way; the only way
     * to learn its outcome is to send it again with its idempotency key (the gateway answers with the first charge, never a
     * second). After the gateway is disabled, SavedMethodCharge refuses even that resend, and AutoCharge::agreementFor
     * no longer picks the method, so the cron never resends it either: maybe-taken money stays unrecorded, and after
     * KEY_REUSE_HOURS the hold lapses and the customer is asked to pay again another way.
     */
    public function test_adv_540_2_an_unknown_charge_of_a_disabled_gateway_is_still_resent_with_its_key(): void
    {
        $invoice = $this->invoice(50);
        $agreement = $this->agreement($this->off, 'OFF');
        $this->client->forceFill(['auto_charge' => true, 'default_billing_agreement_id' => $agreement->id])->save();
        $unknown = ChargeAttempt::query()->create([
            'key' => '0b1e5f2a-540a-4d2c-9e7f-0000000540a2', 'invoice_id' => $invoice->id, 'client_id' => $this->client->id, 'billing_agreement_id' => $agreement->id,
            'amount' => 50, 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CRON, 'status' => ChargeAttempt::STATUS_UNKNOWN,
            'started_at' => now()->subHours(2), 'sent_at' => now()->subHours(2),
        ]);

        $this->assertSame($agreement->id, AutoCharge::agreementFor($invoice->fresh())?->id,
            'the cron resends an unknown charge with its key, also after its gateway was disabled');

        $attempt = (new SavedMethodCharge)->charge($invoice->fresh(), $agreement, ChargeAttempt::SOURCE_CRON);
        $this->assertSame($unknown->id, $attempt->id, 'the same attempt, resent');
        $this->assertSame(['charge:0b1e5f2a-540a-4d2c-9e7f-0000000540a2'], AdvOff::$calls, 'resent with its own key: no new money is taken');
    }

    /**
     * ADV-540-3. Payment Methods → «make default» takes any saved method of the account, also one of a disabled gateway: the
     * customer sets a method for renewals that the cron will never charge (the invoice page's «set as default» checks it).
     */
    public function test_adv_540_3_a_disabled_gateways_method_is_not_made_the_renewal_default(): void
    {
        // The account's first method becomes its default on its own (ClientPaymentMethodTest): an enabled one here, so the
        // test reads what «make default» does (author, round 1: the null default asserted first could never hold).
        $kept = $this->agreement($this->on, 'ON');
        $agreement = $this->agreement($this->off, 'OFF');
        $this->assertSame($kept->id, (int) $this->client->fresh()->default_billing_agreement_id);

        $this->livewireAs($this->user)->test(PaymentMethods::class)->call('makeDefault', $agreement->ulid);

        $this->assertSame($kept->id, (int) $this->client->fresh()->default_billing_agreement_id, 'a method that cannot be charged is not the renewals\' method');
    }

    /**
     * ADV-540-4. The card-expiry notice (CardExpiryNotices) still mails «your card expires, update it» for a method of a
     * disabled gateway: nothing is charged to it any more (as for a removed gateway, CR-11) and the customer cannot save
     * a new one through that gateway.
     */
    public function test_adv_540_4_no_card_expiry_notice_for_a_disabled_gateways_method(): void
    {
        Mail::fake();
        Carbon::setTestNow(now()->startOfMonth()->addDays(26));
        config(['settings.cc_expiry_notice_day' => 25, 'settings.mail_disable' => false]);
        try {
            $this->agreement($this->off, 'OFF', ['expiry' => now()->endOfMonth()->toDateString()]);
            $this->assertSame(0, (new CardExpiryNotices)->run(), 'a card nothing charges any more is not chased');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * ADV-540-5. A domain renewal reminder before its invoice exists reads «auto_charged» from
     * Client::autoChargeAgreement(), which does not know about disabled gateways (AutoCharge::agreementFor does): the
     * customer is told the renewal is charged automatically, and it never is.
     */
    public function test_adv_540_5_a_domain_reminder_does_not_promise_an_automatic_charge_through_a_disabled_gateway(): void
    {
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $tld = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR', 'price' => '23.33']);
        $domain = Domain::factory()->create(['client_id' => $this->client->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Active, 'expires_at' => now()->addDays(30)->toDateString(),
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
        $agreement = $this->agreement($this->off, 'OFF');
        $this->client->forceFill(['auto_charge' => true, 'default_billing_agreement_id' => $agreement->id])->save();

        $data = new ReflectionMethod(DomainReminders::class, 'data');
        $result = $data->invoke(new DomainReminders, $domain->fresh(), 30, now()->addDays(30), null);

        $this->assertFalse($result['auto_charged'], 'its default method\'s gateway is off: nothing will charge it');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Guards: they pass on the PR head and keep what must still work.

    /** GUARD: the invoice page re-reads the gateways per request: one disabled after the page loaded is refused. */
    public function test_guard_the_invoice_page_refuses_a_gateway_disabled_after_it_loaded(): void
    {
        $page = $this->livewireAs($this->user)->test(Show::class, ['invoice' => $this->invoice()]);
        $page->set('selectedMethod', 'gateway-' . $this->on->id);
        $this->on->update(['enabled' => false]);

        $page->call('processPayment')->assertNoRedirect();
        $this->assertSame([], AdvOn::$calls);
    }

    /** GUARD: Mass Pay re-reads the gateways per request too. */
    public function test_guard_mass_pay_refuses_a_gateway_disabled_after_it_loaded(): void
    {
        $group = (new MassPay)->open($this->client, [$this->invoice(10)->id, $this->invoice(20)->id], $this->user);
        $kept = $this->agreement($this->on, 'ON');
        $page = $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group]);
        $this->on->update(['enabled' => false]);

        $page->set('selectedMethod', 'gateway-' . $this->on->id)->call('processPayment')->assertNoRedirect();
        $page->set('selectedMethod', $kept->ulid)->call('processPayment');
        $this->assertSame([], AdvOn::$calls);
        $this->assertSame(0, ChargeAttempt::query()->count());
    }

    /** GUARD: staff still record a payment against a disabled gateway (Mark Paid lists every gateway). */
    public function test_guard_staff_record_a_payment_through_a_disabled_gateway(): void
    {
        $invoice = $this->invoice(50);

        (new RecordPaymentService)->recordByStaff($invoice, $this->off, '50.00', 'manual-1');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame($this->off->id, (int) $invoice->transactions()->sole()->gateway_id);
    }

    /** GUARD: a chargeback the disabled gateway's webhook reports on a payment is still recorded. */
    public function test_guard_a_chargeback_on_a_disabled_gateway_is_recorded(): void
    {
        $invoice = $this->invoice(50);
        ExtensionHelper::addPayment($invoice->id, 'AdvOff', 50, null, 'tx_cb', currency: 'USD');

        $this->assertTrue(ExtensionHelper::chargebackAtGateway('AdvOff', 'tx_cb', 'cb_1', 50.0, 'USD'));
    }

    /** GUARD: the customer can still remove a saved method of a disabled gateway. */
    public function test_guard_a_disabled_gateways_method_can_still_be_removed(): void
    {
        $agreement = $this->agreement($this->off, 'OFF');

        $this->livewireAs($this->user)->test(PaymentMethods::class)->call('removePaymentMethod', $agreement->ulid);

        $this->assertSame(['cancel'], AdvOff::$calls);
        $this->assertSoftDeleted($agreement);
    }

    /** GUARD: a payment the disabled gateway reported as processing still completes when its webhook says so. */
    public function test_guard_a_processing_payment_on_a_disabled_gateway_completes(): void
    {
        $invoice = $this->invoice(50);

        ExtensionHelper::addProcessingPayment($invoice->id, 'AdvOff', 50, null, 'tx_proc', currency: 'USD');
        ExtensionHelper::addPayment($invoice->id, 'AdvOff', 50, null, 'tx_proc', currency: 'USD');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }
}
