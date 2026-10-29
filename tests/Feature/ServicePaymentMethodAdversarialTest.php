<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Clients\MoveToClient;
use App\Services\Import\WhmcsApi;
use App\Services\Import\WhmcsPayMethodImport;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\ItemPaymentMethod;
use App\Services\ServiceUpgrade\ChangeCycle;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use MyCS\Extensions\Gateways\Adv541Pay\Adv541Pay;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Adversarial review of #541 (each service's and domain's own payment method). Each test_adv_541_N fails on the PR head
 * for a real issue. Invariants (owner, as WHMCS): an item paid another way is invoiced apart with that gateway preferred;
 * the account's saved method never charges it automatically; the items with no own method are charged as before.
 */
class ServicePaymentMethodAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Gateway $bank;

    private Gateway $cardGateway;

    private Gateway $paypal;

    private BillingAgreement $card;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        config(['settings.credits_auto_use' => false]);
        if (!class_exists(Adv541Pay::class)) {
            // A card gateway whose charges are recorded, never sent.
            eval('namespace MyCS\Extensions\Gateways\Adv541Pay; class Adv541Pay { public static array $charges = []; public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; } public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) { self::$charges[] = [(int) $invoice->id, (int) $agreement->id, number_format((float) $amount, 2, ".", "")]; return true; } }');
        }
        Adv541Pay::$charges = [];
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        $this->bank = Gateway::query()->create(['name' => 'Bank Transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $this->cardGateway = Gateway::query()->create(['name' => 'Card', 'extension' => 'Adv541Pay', 'type' => 'gateway', 'enabled' => true]);
        $this->paypal = Gateway::query()->create(['name' => 'PayPal', 'extension' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        $this->card = BillingAgreement::query()->create(['ulid' => (string) Str::ulid(), 'client_id' => $this->client->id, 'gateway_id' => $this->cardGateway->id,
            'name' => 'Visa **** 4242', 'type' => 'visa', 'external_reference' => 'pm_' . Str::random(8)]);
        $this->client->forceFill(['default_billing_agreement_id' => $this->card->id, 'auto_charge' => true])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(?Gateway $gateway = null, ?Client $client = null): Service
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return Service::factory()->create(['client_id' => ($client ?? $this->client)->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => '2026-10-25', 'payment_gateway_id' => $gateway?->id]);
    }

    /** @return list<int> the open invoice each service's renewal went on */
    private function bill(Service ...$services): array
    {
        $billing = new RenewalBilling;
        foreach ($services as $service) {
            $this->assertSame(1, DB::transaction(fn () => $billing->billRenewal($service->id, now()->addDays(7))), "service #{$service->id} billed");
        }
        $billing->finishRenewalInvoices();

        return array_map(fn (Service $service) => $this->openInvoiceOf($service), $services);
    }

    private function openInvoiceOf(Service $service): int
    {
        return (int) $service->invoices()->where('invoices.status', Invoice::STATUS_UNPAID)->orderByDesc('invoices.id')->value('invoices.id');
    }

    /** A yearly service paid until 2027-01-01, with a monthly plan to change to now (as ClientPaymentMethodAdversarialTest). */
    private function cycleService(Gateway $own): array
    {
        config(['settings.credits_enabled' => true]); // a change now gives the unused days as credit
        Carbon::setTestNow('2026-07-02 10:00:00');
        $product = Product::factory()->create(['name' => 'Hosting']);
        $plans = [];
        foreach (['year' => 120.00, 'month' => 12.00] as $unit => $price) {
            $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => $unit,
                'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
            Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);
            $plans[$unit] = $plan->fresh();
        }
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plans['year']->id,
            'price' => 120.00, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2027-01-01',
            'payment_gateway_id' => $own->id]);
        $paid = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $paid->items()->create(['description' => 'Renewal', 'price' => 120.00, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => '2026-01-01', 'period_end' => '2027-01-01']);
        DB::table('invoices')->where('id', $paid->id)->update(['status' => Invoice::STATUS_PAID]);

        return [$service->fresh(), $plans['month']];
    }

    /**
     * ADV-541-1 (high). Invariant 2 on a change of billing cycle: ChangeCycle::apply (mode NOW, the customer's or staff's)
     * charges the new period's invoice at once to $client->autoChargeAgreement(), never asking AutoCharge::agreementFor /
     * ItemPaymentMethod::chargedBy. A service paid by bank transfer has its account's card charged for it.
     * Expected: no charge of the card; the customer pays it by bank transfer (the cron's AutoCharge leaves it, too).
     */
    public function test_adv_541_1_a_cycle_change_of_a_service_paid_another_way_never_charges_the_card(): void
    {
        [$service, $monthly] = $this->cycleService($this->bank);

        $invoice = ChangeCycle::apply($service, $monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $this->assertNotNull($invoice, 'precondition: an invoice of the new period');
        $this->assertSame([], Adv541Pay::$charges, 'the card was charged for a service paid by bank transfer');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    /**
     * ADV-541-2 (low). Invariant 1 on a change of billing cycle: the new period's invoice of a service with its own method
     * gets the account's preferred gateway (InvoiceObserver: the card's), not the service's; its page and email name the
     * card. Expected: its own method preferred, as a renewal's.
     */
    public function test_adv_541_2_a_cycle_change_invoice_prefers_the_services_own_method(): void
    {
        [$service, $monthly] = $this->cycleService($this->bank);
        $this->client->forceFill(['auto_charge' => false])->save(); // no charge in the way: only the preference is looked at

        $invoice = ChangeCycle::apply($service, $monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $this->assertSame($this->bank->id, (int) $invoice->fresh()->preferred_gateway_id);
    }

    /**
     * ADV-541-3 (medium). Invariant 2 after a change: two services with no own method are on one renewal invoice; the
     * customer then sets one of them to PayPal on its page (allowed while that invoice is open). AutoCharge::paidAnotherWay
     * reads the item's method now, so the whole invoice, the other service too, is never charged; WHMCS charges an invoice
     * by its own paymentmethod. Expected: the other service is still charged by the card (its invoice kept as it was, or
     * the changed line moved off it).
     */
    public function test_adv_541_3_changing_one_items_method_after_invoicing_keeps_the_others_charged(): void
    {
        $changed = $this->service();
        $other = $this->service();
        [$a, $b] = $this->bill($changed, $other);
        $this->assertSame($a, $b, 'precondition: one invoice for both');

        ItemPaymentMethod::set($changed->fresh(), $this->paypal->id);

        $this->assertSame($this->card->id, AutoCharge::agreementFor(Invoice::query()->findOrFail($this->openInvoiceOf($other)))?->id,
            'a service with no own method is no longer charged because another line of its invoice changed method');
    }

    /**
     * ADV-541-4 (medium). Invariants 1/2 with the account's gateway from its last payment: no saved method when the
     * renewals are made, the last payment by PayPal (Invoice::preferredGatewayFor's fallback). A service of its own set to
     * PayPal then counts as the account's (RenewalBilling::ownGateway) and goes on one invoice with a service of no own
     * method. The customer saves a card and turns «Auto-charge» on before the due date: the invoice has a PayPal line, so
     * the card charges neither. Expected: the service with no own method is charged (an item paying another way than the
     * card is never grouped with those the card pays).
     */
    public function test_adv_541_4_an_item_grouped_by_the_last_payments_gateway_blocks_the_cards_charge_of_the_rest(): void
    {
        $this->client->forceFill(['default_billing_agreement_id' => null])->save();
        $old = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        DB::table('invoices')->where('id', $old->id)->update(['status' => Invoice::STATUS_PAID]);
        DB::table('invoice_transactions')->insert(['invoice_id' => $old->id, 'gateway_id' => $this->paypal->id, 'amount' => '10.00',
            'status' => 'succeeded', 'is_credit_transaction' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame($this->paypal->id, Invoice::preferredGatewayFor($this->client->id), 'precondition: the account\'s gateway by its last payment');

        $byPayPal = $this->service($this->paypal);
        $account = $this->service();
        $this->bill($byPayPal, $account);
        $this->client->forceFill(['default_billing_agreement_id' => $this->card->id, 'auto_charge' => true])->save();

        $this->assertSame($this->card->id, AutoCharge::agreementFor(Invoice::query()->findOrFail($this->openInvoiceOf($account)))?->id,
            'the service with no own method is not charged: it shares an invoice with the PayPal one');
    }

    /**
     * ADV-541-5 (medium). A gateway removed in the admin (soft delete, so the key never nulls it): items set to it keep it.
     * Their renewals still go on an invoice apart preferring the removed gateway (no longer offered on the invoice), and
     * the card never charges them; WHMCS moves a deactivated gateway's items to another. Expected: an item whose method
     * is gone is the account's again (its renewal with the others, charged by the card), or at least not preferred a
     * gateway that no longer exists.
     */
    public function test_adv_541_5_an_item_whose_gateway_was_removed_is_not_left_on_it(): void
    {
        $service = $this->service($this->paypal);
        $this->paypal->delete();

        [$invoiceId] = $this->bill($service);
        $invoice = Invoice::query()->findOrFail($invoiceId);

        $this->assertNotSame($this->paypal->id, (int) $invoice->preferred_gateway_id, 'its renewal prefers a removed gateway');
        $this->assertSame($this->card->id, AutoCharge::agreementFor($invoice)?->id, 'never charged, nor payable by its own method');
    }

    /**
     * ADV-541-6 (low). Invariant 1 on a move: MoveToClient moves a whole open renewal invoice of a PayPal service and sets
     * its preferred gateway to the new account's (R3-2 of #539), while the service keeps PayPal as its own method: the
     * invoice now names the new account's card, which never charges it. Expected: the moved invoice keeps preferring the
     * service's own method.
     */
    public function test_adv_541_6_a_moved_invoice_keeps_its_items_own_method_preferred(): void
    {
        $service = $this->service($this->paypal);
        [$invoiceId] = $this->bill($service);
        $this->assertSame($this->paypal->id, (int) Invoice::query()->find($invoiceId)->preferred_gateway_id, 'precondition');
        $to = User::factory()->create()->client();
        $theirs = BillingAgreement::query()->create(['ulid' => (string) Str::ulid(), 'client_id' => $to->id, 'gateway_id' => $this->cardGateway->id,
            'name' => 'Visa **** 1111', 'type' => 'visa', 'external_reference' => 'pm_' . Str::random(8)]);
        $to->forceFill(['default_billing_agreement_id' => $theirs->id, 'auto_charge' => true])->save();

        app(MoveToClient::class)->service($service->fresh(), $to, Staff::factory()->create());

        $this->assertSame($this->paypal->id, $service->fresh()->payment_gateway_id, 'precondition: the service keeps its own method');
        $moved = Invoice::query()->whereHas('items', fn ($q) => $q->where('reference_type', Service::class)->where('reference_id', $service->id))
            ->where('status', Invoice::STATUS_UNPAID)->firstOrFail();
        $this->assertSame($to->id, (int) $moved->client_id, 'precondition: moved');
        $this->assertSame($this->paypal->id, (int) $moved->preferred_gateway_id, 'the moved invoice names the new account\'s card');
    }

    /**
     * ADV-541-7 (low). RenewalBilling::finishRenewalInvoices spends credit on a renewal with notify only when the account
     * has no saved method ($client->autoChargeAgreement(): «the card charges the rest right after»). For a renewal paid
     * another way the card never does, so the customer is never told that credit paid part of it, nor how much is left.
     * Expected: the «credit applied» email is sent, as for a customer with no card.
     */
    public function test_adv_541_7_credit_on_a_renewal_paid_another_way_is_told(): void
    {
        (new EmailTemplateSeeder)->run();
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
        (new CreditLedger)->add($this->client->id, 'USD', 400, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');

        [$invoiceId] = $this->bill($this->service($this->paypal));

        $invoice = Invoice::query()->findOrFail($invoiceId);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertTrue($invoice->transactions()->where('is_credit_transaction', true)->exists(), 'precondition: credit paid part of it');
        $this->assertNull(AutoCharge::agreementFor($invoice), 'precondition: the card never charges the rest');
        $this->assertTrue(EmailLog::query()->where('client_id', $this->client->id)->where('template_key', 'credit_applied')->exists(),
            'the customer is never told the credit paid part of it');
    }

    /**
     * ADV-541-8 (low). Import order: the item step (importItemPaymentMethods) runs before the saved methods' step, and
     * maps a module only to a gateway already here. The saved methods' step then installs the gateways of the methods it
     * imports (gatewayId), and chooseDefault asks existingGateway again: a module installed in between (PayPal's vault
     * here, no PayPal payment in WHMCS) counts as kept, while its items were left with no own method (the account's).
     * «Auto-charge» stays on with the card as default, and the card charges the PayPal item, which WHMCS never did.
     * Expected: that item keeps PayPal as its own method, or «Auto-charge» goes off.
     */
    public function test_adv_541_8_a_gateway_installed_after_the_item_step_never_hands_its_items_to_the_card(): void
    {
        $client = User::factory()->create()->client();
        DB::table('extensions')->where('type', 'gateway')->delete(); // a fresh install: no Stripe, no PayPal yet
        [$s1, $s2, $s3] = [$this->service(client: $client), $this->service(client: $client), $this->service(client: $client)];
        $whmcs = new \PDO('sqlite::memory:');
        $whmcs->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT, paymentmethod TEXT)');
        $whmcs->exec('CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, status TEXT, paymentmethod TEXT)');
        $whmcs->exec('CREATE TABLE tblpaymethods (id INTEGER PRIMARY KEY, userid INT, description TEXT, contact_id INT, contact_type TEXT, payment_id INT,
            payment_type TEXT, gateway_name TEXT, order_preference INT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $whmcs->exec('CREATE TABLE tblcreditcards (id INTEGER PRIMARY KEY, pay_method_id INT, card_type TEXT, last_four TEXT, expiry_date TEXT,
            card_data TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $c = $client->id;
        $whmcs->exec("INSERT INTO tblhosting VALUES ({$s1->id}, {$c}, 'Active', 'stripe'), ({$s2->id}, {$c}, 'Active', 'stripe'), ({$s3->id}, {$c}, 'Active', 'paypal_ppcpv')");
        $whmcs->exec("INSERT INTO tblpaymethods (id, userid, payment_type, gateway_name, order_preference) VALUES (11, {$c}, 'RemoteCreditCard', 'stripe', 0),
            (13, {$c}, 'RemoteCreditCard', 'paypal_ppcpv', 1)");
        $whmcs->exec("INSERT INTO tblcreditcards (pay_method_id, card_type, last_four, expiry_date, card_data) VALUES (11, 'Visa', '4242', '', 'x'), (13, 'PayPal', 'maria@example.test', '', 'x')");
        Http::fake(['https://whmcs.example.test/includes/api.php' => Http::response(['result' => 'success', 'clientid' => $c, 'paymethods' => [
            ['id' => 11, 'remote_token' => json_encode(['customer' => 'cus_Alpha1', 'method' => 'pm_Visa1']), 'expiry_date' => '06/27', 'card_last_four' => '4242', 'card_type' => 'Visa'],
            ['id' => 13, 'remote_token' => json_encode(['vaultId' => '7XK31234AB567890C', 'customerId' => 'abcDEF123', 'transactionIdentifier' => '0AA0000TX']),
                'expiry_date' => '03/54', 'card_last_four' => 'maria@example.test', 'card_type' => 'PayPal'],
        ]])]);
        $command = app(ImportFromWhmcs::class);
        (new \ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        // As handle(): the items' step, then the saved methods' with the command's own two closures.
        (new \ReflectionMethod($command, 'importItemPaymentMethods'))->invoke($command);
        $gatewayId = (new \ReflectionMethod($command, 'gatewayId'))->getClosure($command);
        $existing = (new \ReflectionMethod($command, 'existingGateway'))->getClosure($command);
        (new WhmcsPayMethodImport($whmcs, new WhmcsApi('https://whmcs.example.test', 'id-1', 'secret-1'), fn (string $m) => $gatewayId($m), fn (string $m) => $existing($m)))->run();

        $client = $client->fresh();
        $card = $client->autoChargeAgreement();
        $this->assertSame(2, BillingAgreement::query()->where('client_id', $c)->count(), 'precondition: the card and the PayPal vault imported');
        $this->assertNull($card === null ? null : ItemPaymentMethod::chargedBy($s3->fresh()),
            'the card charges a service WHMCS charged to PayPal: its own method was never set, «Auto-charge» left on');
    }
}
