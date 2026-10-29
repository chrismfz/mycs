<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Import\WhmcsApi;
use App\Services\Import\WhmcsPayMethodImport;
use App\Services\Payment\AutoCharge;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use MyCS\Extensions\Gateways\R2541Pay\R2541Pay;
use PDO;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Round-2 adversarial review of #541 (an item's own payment method), on the round-1 fix b68f1df8: «decided on the invoice
 * as made» (no_auto_charge set when an item is billed apart, or at import for WHMCS's open invoices of another way), with
 * AutoCharge's line check (paidAnotherWay) removed. Each test_r2_541_N fails on the PR head for a real issue. Owner's
 * invariants: the account's saved card is never charged automatically for an item that pays another way; WHMCS's
 * invoices are never charged by a card WHMCS would not have charged them to.
 */
class ServicePaymentMethodRound2Test extends TestCase
{
    use RefreshDatabase;

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
        if (!class_exists(R2541Pay::class)) {
            // A card gateway whose charges are recorded, never sent.
            eval('namespace MyCS\Extensions\Gateways\R2541Pay; class R2541Pay { public static array $charges = []; public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; } public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) { self::$charges[] = [(int) $invoice->id, (int) $agreement->id]; return true; } }');
        }
        R2541Pay::$charges = [];
        $this->client = User::factory()->create()->client();
        $this->bank = Gateway::query()->create(['name' => 'Bank Transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $this->cardGateway = Gateway::query()->create(['name' => 'Card', 'extension' => 'R2541Pay', 'type' => 'gateway', 'enabled' => true]);
        $this->paypal = Gateway::query()->create(['name' => 'PayPal', 'extension' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        $this->card = $this->agreement($this->client, $this->cardGateway, 'Visa **** 4242');
        // Saved, not yet the default (BillingAgreement makes the first one the default): each test says when it is.
        DB::table('clients')->where('id', $this->client->id)->update(['default_billing_agreement_id' => null, 'auto_charge' => true]);
        $this->client->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function agreement(Client $client, Gateway $gateway, string $name): BillingAgreement
    {
        return BillingAgreement::query()->create(['ulid' => (string) Str::ulid(), 'client_id' => $client->id, 'gateway_id' => $gateway->id,
            'name' => $name, 'type' => 'visa', 'external_reference' => 'pm_' . Str::random(8)]);
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

        return array_map(fn (Service $service) => (int) $service->invoices()->where('invoices.status', Invoice::STATUS_UNPAID)
            ->orderByDesc('invoices.id')->value('invoices.id'), $services);
    }

    /** A WHMCS source with the tables WhmcsPayMethodImport and the item step read. */
    private function whmcs(): PDO
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT, paymentmethod TEXT)');
        $whmcs->exec('CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, status TEXT, paymentmethod TEXT)');
        $whmcs->exec('CREATE TABLE tblpaymethods (id INTEGER PRIMARY KEY, userid INT, description TEXT, contact_id INT, contact_type TEXT, payment_id INT,
            payment_type TEXT, gateway_name TEXT, order_preference INT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $whmcs->exec('CREATE TABLE tblcreditcards (id INTEGER PRIMARY KEY, pay_method_id INT, card_type TEXT, last_four TEXT, expiry_date TEXT,
            card_data TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, status TEXT, paymentmethod TEXT)');

        return $whmcs;
    }

    /** One Stripe card in WHMCS for $client (method #11), answered by the API. */
    private function stripeCardIn(PDO $whmcs, Client $client): void
    {
        $c = $client->id;
        $whmcs->exec("INSERT INTO tblpaymethods (id, userid, payment_type, gateway_name, order_preference) VALUES (11, {$c}, 'RemoteCreditCard', 'stripe', 0)");
        $whmcs->exec("INSERT INTO tblcreditcards (pay_method_id, card_type, last_four, expiry_date, card_data) VALUES (11, 'Visa', '4242', '', 'x')");
        Http::fake(['https://whmcs.example.test/includes/api.php' => Http::response(['result' => 'success', 'clientid' => $c, 'paymethods' => [
            ['id' => 11, 'remote_token' => json_encode(['customer' => 'cus_R2a', 'method' => 'pm_R2Visa']), 'expiry_date' => '06/27', 'card_last_four' => '4242', 'card_type' => 'Visa'],
        ]])]);
    }

    private function payMethodImport(PDO $whmcs, ?Gateway $stripe = null): array
    {
        $map = fn (string $module) => match ($module) {
            'stripe' => $stripe?->id, 'banktransfer' => $this->bank->id, 'paypal_ppcpv' => $this->paypal->id, default => null,
        };

        return (new WhmcsPayMethodImport($whmcs, new WhmcsApi('https://whmcs.example.test', 'id-1', 'secret-1'), $map, $map))->run();
    }

    /** A WHMCS renewal invoice imported here (the same id), open, with its service's line. */
    private function importedInvoice(Client $client, Service $service): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-10-25']);
        $invoice->items()->create(['description' => 'Renewal', 'price' => 10, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => '2026-10-25', 'period_end' => '2026-11-25']);

        return $invoice->fresh();
    }

    /**
     * R2-541-1 (medium). Invariant 2, made worse by round 1. The account's way is read when the renewal is billed
     * (Invoice::preferredGatewayFor): with no saved method, the gateway of its last payment. A service the customer set to
     * PayPal, with PayPal the last payment's gateway, counts as the account's and goes on one invoice with a service of no
     * own way, no_auto_charge off. The customer then saves a card (Auto-charge on) before the due date. Round 1 removed
     * AutoCharge's line check (paidAnotherWay) and fixed ADV-541-4 by charging that whole invoice: the card now pays the
     * PayPal service too. Expected: the invoice with the PayPal service's line is never charged by the card (e.g. an
     * item with an explicit own way always goes apart, flagged; or the line check kept for lines of another gateway than
     * the card's).
     */
    public function test_r2_541_1_a_paypal_item_grouped_by_the_last_payment_is_charged_by_a_card_saved_later(): void
    {
        $old = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        DB::table('invoices')->where('id', $old->id)->update(['status' => Invoice::STATUS_PAID]);
        DB::table('invoice_transactions')->insert(['invoice_id' => $old->id, 'gateway_id' => $this->paypal->id, 'amount' => '10.00',
            'status' => 'succeeded', 'is_credit_transaction' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame($this->paypal->id, Invoice::preferredGatewayFor($this->client->id), 'precondition');

        $byPayPal = $this->service($this->paypal);
        [$invoiceId] = $this->bill($byPayPal, $this->service());
        $this->client->forceFill(['default_billing_agreement_id' => $this->card->id, 'auto_charge' => true])->save();

        $this->assertNull(AutoCharge::agreementFor(Invoice::query()->findOrFail($invoiceId))?->id,
            'the card is charged for a service the customer set to PayPal');
    }

    /**
     * R2-541-2 (medium). Same root as R2-541-1, by a change of the account's default. The default is a PayPal vault; a
     * service the customer set to PayPal on its page (stored as PayPal, equal to the account's) is billed on one invoice
     * with a service of no own way, no_auto_charge off. The customer then makes a new card the default before the due
     * date. Before round 1 paidAnotherWay kept the card off it; now the card charges the PayPal service's line, while
     * «an open invoice keeps the way it was made with» (PayPal). Expected: never charged by the card.
     */
    public function test_r2_541_2_a_paypal_item_on_the_accounts_invoice_is_charged_by_a_new_default_card(): void
    {
        $vault = $this->agreement($this->client, $this->paypal, 'PayPal maria@example.test');
        $this->client->forceFill(['default_billing_agreement_id' => $vault->id, 'auto_charge' => true])->save();

        [$invoiceId] = $this->bill($this->service($this->paypal), $this->service());
        $this->client->forceFill(['default_billing_agreement_id' => $this->card->id])->save();

        $this->assertNull(AutoCharge::agreementFor(Invoice::query()->findOrFail($invoiceId))?->id,
            'the new card is charged for a service set to PayPal, on an invoice made with PayPal');
    }

    /**
     * R2-541-3 (medium). Invariant 4. WHMCS's open invoices of another way get no_auto_charge only inside chooseDefault,
     * i.e. for a customer with a saved method imported (and a default chosen). A customer who paid everything by bank
     * transfer in WHMCS (no saved method) keeps its open bank-transfer renewal invoice unflagged. After the cut-over the
     * customer saves a card (paying another invoice, Auto-charge on by default): the cron charges the WHMCS bank-transfer
     * invoice to it, which WHMCS never would have. The same for the customers chooseDefault turns «Auto-charge» off for
     * (it returns before the flags), once staff give them a default. Expected: flagged at import for every customer.
     */
    public function test_r2_541_3_a_bank_transfer_customers_whmcs_invoice_is_charged_by_a_card_saved_after_the_cutover(): void
    {
        $stripe = Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $withCard = User::factory()->create()->client();
        $service = $this->service($this->bank);
        $invoice = $this->importedInvoice($this->client, $service);
        $whmcs = $this->whmcs();
        $c = $this->client->id;
        $whmcs->exec("INSERT INTO tblhosting VALUES ({$service->id}, {$c}, 'Active', 'banktransfer')");
        $whmcs->exec("INSERT INTO tblinvoices VALUES ({$invoice->id}, {$c}, 'Unpaid', 'banktransfer')");
        $this->stripeCardIn($whmcs, $withCard); // another customer's card: the run goes on

        $this->payMethodImport($whmcs, $stripe);
        // After the cut-over: the customer saves a card.
        $this->client->forceFill(['default_billing_agreement_id' => $this->card->id, 'auto_charge' => true])->save();

        $this->assertTrue((bool) $invoice->fresh()->no_auto_charge, 'a WHMCS bank-transfer invoice left to the card');
        $this->assertNull(AutoCharge::agreementFor($invoice->fresh())?->id);
    }

    /**
     * R2-541-4 (medium). Invariant 4 on a re-run (the importer is re-runnable, and is run again on the cut-over day).
     * On the second run the customer's saved methods exist already, so import() skips them, $imported is empty and
     * chooseDefault returns at once: a WHMCS bank-transfer invoice made since the first run is never flagged, and the
     * imported card charges it. Expected: flagged on every run.
     */
    public function test_r2_541_4_a_whmcs_invoice_of_another_way_imported_on_a_rerun_is_not_flagged(): void
    {
        $stripe = Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $byCard = $this->service($stripe);
        $byBank = $this->service($this->bank);
        $whmcs = $this->whmcs();
        $c = $this->client->id;
        $whmcs->exec("INSERT INTO tblhosting VALUES ({$byCard->id}, {$c}, 'Active', 'stripe'), ({$byBank->id}, {$c}, 'Active', 'banktransfer')");
        $this->stripeCardIn($whmcs, $this->client);
        $this->payMethodImport($whmcs, $stripe);
        $this->assertNotNull($this->client->fresh()->autoChargeAgreement(), 'precondition: the card is the default, Auto-charge on');

        // WHMCS bills the bank-transfer service before the cut-over; the importer runs again.
        $invoice = $this->importedInvoice($this->client, $byBank);
        $whmcs->exec("INSERT INTO tblinvoices VALUES ({$invoice->id}, {$c}, 'Unpaid', 'banktransfer')");
        $this->payMethodImport($whmcs, $stripe);

        $this->assertTrue((bool) $invoice->fresh()->no_auto_charge, 'the re-run left a WHMCS bank-transfer invoice to the card');
    }

    /**
     * R2-541-5 (low). Invariant 4, WHMCS's other open statuses. unpaidInvoices() reads status = 'Unpaid' only, while the
     * importer makes «Payment Pending» and «Collections» unpaid here too (invoiceStatus, ADV-531-1): such an invoice of
     * another way is left to the card. Expected: flagged, as an «Unpaid» one.
     */
    public function test_r2_541_5_a_payment_pending_whmcs_invoice_of_another_way_is_flagged(): void
    {
        $stripe = Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $byCard = $this->service($stripe);
        $byBank = $this->service($this->bank);
        $invoice = $this->importedInvoice($this->client, $byBank);
        $whmcs = $this->whmcs();
        $c = $this->client->id;
        $whmcs->exec("INSERT INTO tblhosting VALUES ({$byCard->id}, {$c}, 'Active', 'stripe'), ({$byBank->id}, {$c}, 'Active', 'banktransfer')");
        $whmcs->exec("INSERT INTO tblinvoices VALUES ({$invoice->id}, {$c}, 'Payment Pending', 'banktransfer')");
        $this->stripeCardIn($whmcs, $this->client);

        $this->payMethodImport($whmcs, $stripe);

        $this->assertNotNull($this->client->fresh()->autoChargeAgreement(), 'precondition: the card is the default, Auto-charge on');
        $this->assertTrue((bool) $invoice->fresh()->no_auto_charge, 'a «Payment Pending» bank-transfer invoice left to the card');
    }

    /**
     * R2-541-6 (low). Code review 4's fix does not survive a re-run: the item step sets every item to its WHMCS gateway
     * again (Stripe, installed by the first run), and chooseDefault, which emptied the default's own items, returns early
     * (nothing new imported). The card's items are pinned to Stripe again: a default changed later (a PayPal vault) bills
     * them apart and never charges them, unlike the services ordered here. Expected: they stay the account's (null).
     */
    public function test_r2_541_6_the_defaults_items_follow_the_account_after_a_rerun(): void
    {
        DB::table('extensions')->where('type', 'gateway')->where('extension', 'Stripe')->delete();
        $service = $this->service();
        $whmcs = $this->whmcs();
        $c = $this->client->id;
        $whmcs->exec("INSERT INTO tblhosting VALUES ({$service->id}, {$c}, 'Active', 'stripe')");
        $this->stripeCardIn($whmcs, $this->client);
        $run = function () use ($whmcs) {
            $command = app(ImportFromWhmcs::class);
            (new \ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
            $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
            (new \ReflectionMethod($command, 'importItemPaymentMethods'))->invoke($command);
            $gatewayId = (new \ReflectionMethod($command, 'gatewayId'))->getClosure($command);
            $existing = (new \ReflectionMethod($command, 'existingGateway'))->getClosure($command);
            (new WhmcsPayMethodImport($whmcs, new WhmcsApi('https://whmcs.example.test', 'id-1', 'secret-1'),
                fn (string $m) => $gatewayId($m), fn (string $m) => $existing($m)))->run();
        };

        $run();
        $this->assertNull($service->fresh()->payment_gateway_id, 'precondition: the first run leaves it the account\'s');
        $run();

        $this->assertNull($service->fresh()->payment_gateway_id, 'the re-run pinned the card\'s service to Stripe');
    }
}
