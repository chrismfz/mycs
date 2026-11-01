<?php

namespace Tests\Feature;

use App\Livewire\Client\PaymentMethods;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\ItemPaymentMethod;
use App\Support\Actor;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use MyCS\Extensions\Gateways\Adv568r2Card\Adv568r2Card;
use MyCS\Extensions\Gateways\Adv568r2Vault\Adv568r2Vault;
use Tests\TestCase;

/**
 * Adversarial review of #568, round 2 (a service's or domain's own «Do not charge automatically», the «Automatic charge»
 * line, and the fixes of round 1). Each test_adv_568_r2_N fails on the PR head for a real issue; its setup assertions pass.
 * Invariants: the switch keeps only its own item's renewals from the cron, the account's others are charged as before;
 * what the page says is what the cron does; the import never leaves an item WHMCS never charged to a saved method here.
 */
class ChargeByGatewayMethodAdversarialRound2Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Gateway $cardGateway;

    private Gateway $vaultGateway;

    private BillingAgreement $card;

    private BillingAgreement $vault;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '',
            'settings.autocharge_max_failures' => 0, 'settings.cronjob_order_suspend' => 8]);
        foreach (['Adv568r2Card', 'Adv568r2Vault'] as $name) {
            if (!class_exists("MyCS\\Extensions\\Gateways\\{$name}\\{$name}")) {
                eval("namespace MyCS\\Extensions\\Gateways\\{$name}; class {$name} {
                    /** @var list<array{invoice: int, agreement: int}> */
                    public static array \$charges = [];
                    public function __construct(\$config = []) {}
                    public function supportsBillingAgreements() { return true; }
                    public function cancelBillingAgreement(\$agreement): bool { return true; }
                    public function charge(\$invoice, \$amount, \$agreement) {
                        self::\$charges[] = ['invoice' => \$invoice->id, 'agreement' => \$agreement->id];
                        return true;
                    } }");
            }
        }
        Adv568r2Card::$charges = Adv568r2Vault::$charges = [];
        Mail::fake();
        Queue::fake();
        Carbon::setTestNow('2026-11-01 09:00:00');
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        $this->cardGateway = Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Adv568r2Card', 'type' => 'gateway', 'enabled' => true]);
        $this->vaultGateway = Gateway::query()->create(['name' => 'PayPal', 'extension' => 'Adv568r2Vault', 'type' => 'gateway', 'enabled' => true]);
        $this->card = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->cardGateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_r2_1', 'type' => 'visa']);
        $this->vault = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->vaultGateway->id,
            'name' => 'PayPal a@example.test', 'external_reference' => 'B-r2-1', 'type' => 'paypal']);
        $this->client->forceFill(['auto_charge' => true])->save();
        $this->assertSame($this->card->id, $this->client->fresh()->default_billing_agreement_id, 'precondition: the card is the default');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(?Gateway $gateway = null, array $attributes = []): Service
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return Service::factory()->create($attributes + ['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => 10,
            'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-11-01', 'payment_gateway_id' => $gateway?->id]);
    }

    /** An open renewal invoice due today, a line for each service (each line records its service's way as billed). */
    private function invoice(Service ...$services): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-01']);
        foreach ($services as $service) {
            $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);
        }

        return $invoice->fresh();
    }

    private function said(string $key, array $with = []): string
    {
        return __("services.auto_charge_status.{$key}", $with);
    }

    /** @return list<int> the invoices the cron charged */
    private function cron(): array
    {
        (new AutoCharge)->run();

        return array_values(array_unique(array_column([...Adv568r2Card::$charges, ...Adv568r2Vault::$charges], 'invoice')));
    }

    /**
     * ADV-568-R2-1. The cron bills a customer's renewals of the same day on one invoice (RenewalBilling::renewalGroupKey,
     * keyed by client, due date and currency, not by the switch). One service set «Do not charge automatically» then
     * keeps every other service renewing that day from the card: AutoCharge::itemKeptFromIt drops the whole invoice. The
     * other service's page says «Yes, with Visa 4242», and the switch is meant to be «for it alone».
     */
    public function test_adv_568_r2_1_one_items_switch_stops_the_charge_of_the_others_billed_on_its_invoice(): void
    {
        $kept = $this->service(null, ['no_auto_charge' => true]);
        $charged = $this->service(null);
        $billing = new RenewalBilling;
        foreach ([$kept, $charged] as $service) {
            $this->assertSame(1, DB::transaction(fn () => $billing->billRenewal($service->id, now()->addDays(7))), "service #{$service->id} billed");
        }
        $billing->finishRenewalInvoices();
        $invoiceOfCharged = (int) $charged->invoices()->where('invoices.status', Invoice::STATUS_UNPAID)->value('invoices.id');
        $this->assertNotSame(0, $invoiceOfCharged, 'precondition: its renewal is billed');
        // Fix of #568 (author): the kept one is billed on an invoice apart; this precondition asserted the shared invoice the fix removes.
        $this->assertNotSame($invoiceOfCharged, (int) $kept->invoices()->where('invoices.status', Invoice::STATUS_UNPAID)->value('invoices.id'),
            'the kept one\'s renewal goes on an invoice apart');
        $this->assertSame($this->said('charged', ['method' => 'Visa 4242']), ItemPaymentMethod::autoChargeStatus($charged->fresh()),
            'precondition: its page says the card charges it');

        $this->assertContains($invoiceOfCharged, $this->cron(),
            'the service with auto-charge on is charged; only the one set «Do not charge automatically» is left to the customer');
    }

    /**
     * ADV-568-R2-2. Which saved method pays is the money permission's in «Login as client» (SA-5: PaymentMethods::makeDefault
     * and the account's «Auto-charge» need `pay`, and so does this PR's «turn it on»). Since #568 an item's own payment
     * method chooses a saved method too (before, another gateway's was never charged): staff without `pay` set a service
     * from the account's way (the card) to PayPal, and the customer's PayPal vault is charged for it from then on.
     */
    public function test_adv_568_r2_2_staff_without_pay_choose_the_saved_method_that_charges_a_service(): void
    {
        $service = $this->service(null);
        session(['impersonating' => $this->user->id, CurrentClient::SESSION_KEY => $this->client->id, CurrentClient::IMPERSONATING_KEY => $this->client->id]);
        Auth::guard('staff')->setUser(Staff::factory()->withPermissions(['admin.users.impersonate', 'admin.services.update', 'admin.clients.update'])->create());
        Auth::guard('web')->setUser($this->user);
        app(CurrentClient::class)->forget();
        $this->assertTrue(Actor::impersonating() && !Actor::staffMayAs('pay'), 'precondition: staff in «Login as client» without the money permission');

        Livewire::test(PaymentMethods::class)->call('makeDefault', $this->vault->ulid);
        $this->assertSame($this->card->id, (int) $this->client->fresh()->default_billing_agreement_id, 'precondition: they may not choose which saved method pays');
        $this->assertSame($this->card->id, ItemPaymentMethod::chargedBy($service)?->id, 'precondition: the card charges it');

        Livewire::test(ServiceShow::class, ['service' => $service])->set('paymentGatewayId', (string) $this->vaultGateway->id);

        $this->assertNotSame($this->vault->id, ItemPaymentMethod::chargedBy($service->fresh())?->id,
            'staff without `pay` must not move the service\'s charge from the card to the PayPal vault');
    }

    /**
     * ADV-568-R2-3. The page says «Yes, with Visa 4242» for a default stopped at autocharge_max_failures, while
     * AutoCharge::agreementFor (the cron, the reminder) charges nothing until the customer pays with it.
     */
    public function test_adv_568_r2_3_the_page_says_charged_with_a_method_the_cron_stopped(): void
    {
        config(['settings.autocharge_max_failures' => 3]);
        $this->card->forceFill(['failed_count' => 3])->save();
        $service = $this->service(null);
        $invoice = $this->invoice($service);
        $this->assertNull(AutoCharge::agreementFor($invoice), 'precondition: the cron no longer charges the stopped card');
        $this->assertSame([], $this->cron());

        $this->assertNotSame($this->said('charged', ['method' => 'Visa 4242']), ItemPaymentMethod::autoChargeStatus($service->fresh()),
            'the page must not say the card charges it while the cron has stopped charging it');
    }

    /**
     * ADV-568-R2-4. The «Automatic charge» line is shown on a suspended service (billing-agreement partial: active or
     * suspended), and says «Yes, with Visa 4242»; the cron never charges an invoice with a line of a service that is not
     * active (AutoCharge::due, $notCharged). The customer waits for a charge that never comes, and the service stays
     * suspended (then terminated).
     */
    public function test_adv_568_r2_4_the_page_says_charged_for_a_suspended_service_the_cron_never_charges(): void
    {
        $service = $this->service(null, ['status' => Service::STATUS_SUSPENDED]);
        $invoice = $this->invoice($service);
        $this->assertSame([], $this->cron(), 'precondition: the cron leaves a suspended service\'s invoice to the customer');
        $this->assertSame([], Adv568r2Card::$charges);

        $this->assertNotSame($this->said('charged', ['method' => 'Visa 4242']), ItemPaymentMethod::autoChargeStatus($service->fresh()),
            "the page must not say the card charges it (invoice #{$invoice->id} is never charged automatically)");
    }

    /**
     * ADV-568-R2-5. A domain with «Auto renew» off: the cron charges none of its invoices (AutoCharge::due: auto_renew
     * false), and bills no renewal; its page (shown for an active domain, `billed`) and the admin say «Yes, with Visa 4242».
     */
    public function test_adv_568_r2_5_the_page_says_charged_for_a_domain_that_does_not_renew(): void
    {
        $domain = Domain::factory()->create(['client_id' => $this->client->id, 'currency_code' => 'USD', 'auto_renew' => false]);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-01']);
        $invoice->items()->create(['description' => 'Domain renewal', 'price' => '12.00', 'quantity' => 1, 'reference_type' => Domain::class, 'reference_id' => $domain->id]);
        $this->assertSame([], $this->cron(), 'precondition: the cron charges nothing of a domain that does not renew');

        $this->assertNotSame($this->said('charged', ['method' => 'Visa 4242']), ItemPaymentMethod::autoChargeStatus($domain->fresh()),
            'the page must not say the card charges a domain the cron leaves alone');
    }

    /**
     * ADV-568-R2-6. Its open invoice is charged by the way its lines were billed (wayOf: «as billed, not as the item says
     * now»), but the page reads the item's way now. A service billed on PayPal whose customer then sets it to the
     * account's way is told «Yes, with Visa 4242», and the cron takes its open invoice from the PayPal wallet.
     */
    public function test_adv_568_r2_6_the_page_names_the_new_way_while_the_cron_charges_its_open_invoice_the_old(): void
    {
        $service = $this->service($this->vaultGateway);
        $open = $this->invoice($service);
        $this->assertSame($this->vaultGateway->id, (int) $open->items()->value('payment_gateway_id'), 'precondition: its line records PayPal');
        $service->update(['payment_gateway_id' => null]); // the customer: «the account's»

        // Fixed the other way (author, #568): an open invoice keeps the way it was billed with (#541, R2-541), so the page now
        // names the method the cron takes it from; the card from its next renewal. This test asserted the page's old answer.
        $this->assertSame($this->said('charged', ['method' => $this->vault->name]), ItemPaymentMethod::autoChargeStatus($service->fresh()), 'what its page says');
        $this->assertSame([$open->id], $this->cron());
        $this->assertSame([$open->id], array_column(Adv568r2Vault::$charges, 'invoice'), 'the page and the cron agree: the wallet, as billed');
    }
}
