<?php

namespace Tests\Feature;

use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\ItemPaymentMethod;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use MyCS\Extensions\Gateways\Adv568r3Card\Adv568r3Card;
use MyCS\Extensions\Gateways\Adv568r3Vault\Adv568r3Vault;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * Adversarial review of #568, round 3: commit 611568dd (ItemPaymentMethod::followOnOpenInvoices, an item's change of
 * payment method moves its open invoices too). Each test_adv_568_r3_N fails on the PR head for a real issue; its setup
 * assertions pass. Invariants: a second method is never sent a charge of an invoice whose charge to the first may have
 * taken money; an invoice of the item alone is charged the new way, as its page and the commit say.
 */
class ChargeByGatewayMethodAdversarialRound3Test extends TestCase
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
        foreach (['Adv568r3Card', 'Adv568r3Vault'] as $name) {
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
        Adv568r3Card::$charges = Adv568r3Vault::$charges = [];
        Mail::fake();
        Queue::fake();
        Carbon::setTestNow('2026-11-01 09:00:00');
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        $this->cardGateway = Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Adv568r3Card', 'type' => 'gateway', 'enabled' => true]);
        $this->vaultGateway = Gateway::query()->create(['name' => 'PayPal', 'extension' => 'Adv568r3Vault', 'type' => 'gateway', 'enabled' => true]);
        $this->card = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->cardGateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_r3_1', 'type' => 'visa']);
        $this->vault = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->vaultGateway->id,
            'name' => 'PayPal a@example.test', 'external_reference' => 'B-r3-1', 'type' => 'paypal']);
        $this->client->forceFill(['auto_charge' => true])->save();
        $this->assertSame($this->card->id, $this->client->fresh()->default_billing_agreement_id, 'precondition: the card is the default');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(?Gateway $gateway = null): Service
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => 10,
            'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-11-01', 'payment_gateway_id' => $gateway?->id]);
    }

    /** An open renewal invoice due today, one line for the service (recording its way as billed). */
    private function invoice(Service $service): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-01']);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);

        return $invoice->fresh();
    }

    /**
     * ADV-568-R3-1 (high: two methods charged for one invoice). A charge to the card was sent and never answered: its
     * attempt stays `started` (the PHP process died, the worker was killed mid-call). After HOLD_MINUTES it is a charge of
     * unknown outcome (SavedMethodCharge::unknownAttempt: «started and never finished», resent with its key, which takes no
     * new money), but SavedMethodCharge::onItsWay only holds a `started` one for HOLD_MINUTES. followOnOpenInvoices relies
     * on onItsWay alone, so it moves the invoice to PayPal, and the cron sends the PayPal vault a new charge of the invoice
     * the card may already have paid. Before 611568dd the open invoice kept the card's way and the cron resent the card's
     * charge with its key.
     */
    public function test_adv_568_r3_1_a_card_charge_started_and_never_answered_does_not_keep_its_invoice_from_moving_to_another_method(): void
    {
        $service = $this->service(null);
        $open = $this->invoice($service);
        $sent = now()->subMinutes(ChargeAttempt::HOLD_MINUTES + 20);
        ChargeAttempt::query()->forceCreate(['invoice_id' => $open->id, 'client_id' => $this->client->id, 'billing_agreement_id' => $this->card->id,
            'source' => ChargeAttempt::SOURCE_CLIENT, 'status' => ChargeAttempt::STATUS_STARTED, 'amount' => '10.00', 'currency_code' => 'USD',
            'key' => (string) Str::uuid(), 'started_at' => $sent, 'sent_at' => $sent]);
        $this->assertNotNull(SavedMethodCharge::unknownAttempt($open->id, $this->card->id), 'precondition: the card\'s charge is one of unknown outcome');
        $this->assertSame($this->card->id, AutoCharge::agreementFor($open)?->id, 'precondition: the card charges it (its charge resent with its key)');

        ItemPaymentMethod::set($service, $this->vaultGateway->id);
        (new AutoCharge)->run();

        $this->assertSame([], Adv568r3Vault::$charges,
            'the PayPal vault must not be charged for an invoice whose card charge may have taken the money (unknown outcome)');
        $this->assertNull($open->items()->value('payment_gateway_id'), 'its way stays the card\'s while that charge is of unknown outcome');
    }

    /**
     * ADV-568-R3-2 (medium: an invoice no method charges, the item's page and the commit say the new way does). WHMCS's
     * open invoices of a chargeable way record it on all their lines (WhmcsPayMethodImport::openInvoicesKeepTheirWay
     * updates every invoice_items row of the invoice): a late fee, a setup fee, a manual «Item» line (imported with no
     * reference) records PayPal too. The customer moves the service to the account's way (the card): followOnOpenInvoices
     * re-records only the service's lines, sees the invoice as «of it alone» (only Service/Domain lines count), sets its
     * preferred method to the card's and does not split. The late fee still says PayPal: AutoCharge::wayOf finds two ways,
     * and no method charges it, while the page says «charged with Visa 4242» for the next renewal and the commit promises
     * an invoice of the item alone takes the new way.
     */
    public function test_adv_568_r3_2_an_imported_invoice_of_the_item_alone_is_charged_by_no_method_after_its_way_changes(): void
    {
        $service = $this->service($this->vaultGateway); // WHMCS's paymentmethod: PayPal
        $open = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-01']);
        $open->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);
        $open->items()->create(['description' => 'Late Fee', 'price' => '2.00', 'quantity' => 1, 'reference_type' => null, 'reference_id' => 77]);
        // As WhmcsPayMethodImport::openInvoicesKeepTheirWay leaves it: every line of the invoice records PayPal.
        InvoiceItem::query()->where('invoice_id', $open->id)->update(['payment_gateway_id' => $this->vaultGateway->id]);
        $this->assertSame($this->vault->id, AutoCharge::agreementFor($open->fresh())?->id, 'precondition: as imported, the PayPal vault charges it');

        ItemPaymentMethod::set($service, null); // the account's way: the card

        $this->assertSame($this->cardGateway->id, (int) $open->fresh()->preferred_gateway_id, 'precondition: it took the card as its preferred method');
        $this->assertSame($this->card->id, AutoCharge::agreementFor($open->fresh())?->id,
            'an invoice of the service alone follows its new way: the card charges it, not no method at all');
    }

    /**
     * ADV-568-R3-3 (low: no trace of which method an invoice was moved to). The way its lines record decides which saved
     * method is charged (AutoCharge::wayOf), and every other change of a line goes through the model, audited
     * (InvoiceLineMove::move: «each move is audited»). followOnOpenInvoices re-records it with a query
     * («(clone $mine)->update(...)»): the InvoiceItem audit, and InvoiceItemObserver, never see it. When a customer asks
     * why PayPal was charged for an invoice billed to the card, the invoice's lines say nothing.
     */
    public function test_adv_568_r3_3_the_way_its_open_invoices_lines_take_is_not_audited(): void
    {
        $service = $this->service(null);
        $open = $this->invoice($service);
        $line = $open->items()->sole();
        config(['audit.console' => true]); // as a web request: the customer's page, staff's form

        ItemPaymentMethod::set($service, $this->vaultGateway->id);

        $this->assertSame($this->vaultGateway->id, (int) $line->fresh()->payment_gateway_id, 'precondition: its line took PayPal');
        $this->assertTrue(Audit::query()->where('auditable_type', $service->getMorphClass())->where('auditable_id', $service->id)->get()
            ->contains(fn (Audit $audit) => array_key_exists('payment_gateway_id', (array) $audit->new_values)), 'precondition: the service\'s change is audited');
        $this->assertTrue(Audit::query()->where('auditable_type', $line->getMorphClass())->where('auditable_id', $line->id)->get()
            ->contains(fn (Audit $audit) => array_key_exists('payment_gateway_id', (array) $audit->new_values)),
            'the line\'s new way (which method charges its invoice) leaves a trace in its audit');
    }
}
