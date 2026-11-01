<?php

namespace Tests\Feature;

use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Import\WhmcsApi;
use App\Services\Import\WhmcsPayMethodImport;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\ItemPaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Gateways\Adv568CardPay\Adv568CardPay;
use MyCS\Extensions\Gateways\Adv568VaultPay\Adv568VaultPay;
use PDO;
use Tests\TestCase;

/**
 * Adversarial review of #568 (charge each gateway's invoices to the account's method of that gateway). Each test fails
 * on the PR head for a defect found; see the PR comment for the table.
 */
class ChargeByGatewayMethodAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Gateway $cardGateway;

    private Gateway $vaultGateway;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '',
            'settings.autocharge_max_failures' => 0, 'settings.cronjob_order_suspend' => 8]);
        foreach (['Adv568CardPay', 'Adv568VaultPay'] as $name) {
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
        Adv568CardPay::$charges = Adv568VaultPay::$charges = [];
        Mail::fake();
        Carbon::setTestNow('2026-11-01 09:00:00');
        $this->client = User::factory()->create()->client();
        $this->client->forceFill(['auto_charge' => true])->save();
        $this->cardGateway = Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Adv568CardPay', 'type' => 'gateway', 'enabled' => true]);
        $this->vaultGateway = Gateway::query()->create(['name' => 'PayPal', 'extension' => 'Adv568VaultPay', 'type' => 'gateway', 'enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function agreement(Gateway $gateway, string $name, ?string $expiry = null): BillingAgreement
    {
        return BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id, 'name' => $name,
            'external_reference' => 'ref-' . $name, 'type' => 'visa', 'expiry' => $expiry]);
    }

    private function service(?Gateway $gateway): Service
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);

        return Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => 10,
            'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-11-01', 'payment_gateway_id' => $gateway?->id]);
    }

    /** An open renewal invoice due today, one line per service (each records its service's way, as billed). */
    private function invoice(Service ...$services): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-01']);
        foreach ($services as $service) {
            $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);
        }

        return $invoice->fresh();
    }

    /** @return list<int> the agreements the cron charged */
    private function cron(): array
    {
        (new AutoCharge)->run();

        return array_column([...Adv568CardPay::$charges, ...Adv568VaultPay::$charges], 'agreement');
    }

    /**
     * ADV-568-1. The importer maps several modules to one gateway here (ImportFromWhmcs::GATEWAYS: «paypal», «paypalcheckout»,
     * «paypal_acdc», «paypal_ppcpv» → PayPal). An item paid with basic PayPal («paypal», a redirect, no saved method)
     * was never charged automatically in WHMCS (the importer itself keeps such open invoices from it:
     * openInvoicesKeepTheirWay → no_auto_charge). After the import its payment_gateway_id is PayPal, and with this PR the
     * customer's PayPal wallet (paypal_ppcpv) now charges its renewals: money WHMCS never took. chooseDefault's «unkept»
     * check only looks at the default's gateway, not at the second saved method's.
     */
    public function test_adv_568_1_an_item_of_basic_paypal_in_whmcs_is_not_charged_to_the_paypal_wallet_after_import(): void
    {
        $stripe = (int) DB::table('extensions')->insertGetId(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => true,
            'created_at' => now(), 'updated_at' => now()]);
        $paypal = (int) DB::table('extensions')->insertGetId(['name' => 'PayPal', 'extension' => 'PayPal', 'type' => 'gateway', 'enabled' => true,
            'created_at' => now(), 'updated_at' => now()]);
        $c = $this->client->id;
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblpaymethods (id INTEGER PRIMARY KEY, userid INT, description TEXT, contact_id INT, contact_type TEXT, payment_id INT,
            payment_type TEXT, gateway_name TEXT, order_preference INT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $whmcs->exec('CREATE TABLE tblcreditcards (id INTEGER PRIMARY KEY, pay_method_id INT, card_type TEXT, last_four TEXT, expiry_date TEXT,
            card_data TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT, paymentmethod TEXT)');
        $whmcs->exec('CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, status TEXT, paymentmethod TEXT)');
        // Two services by card, one by basic PayPal (paid by hand in WHMCS); a Stripe card and a PayPal wallet saved.
        $whmcs->exec("INSERT INTO tblhosting (userid, domainstatus, paymentmethod) VALUES ({$c}, 'Active', 'stripe'), ({$c}, 'Active', 'stripe'),
            ({$c}, 'Active', 'paypal')");
        $whmcs->exec("INSERT INTO tblpaymethods (id, userid, payment_type, gateway_name, order_preference, deleted_at) VALUES
            (21, {$c}, 'RemoteCreditCard', 'stripe', 0, NULL), (22, {$c}, 'RemoteCreditCard', 'paypal_ppcpv', 1, NULL)");
        $whmcs->exec("INSERT INTO tblcreditcards (pay_method_id, card_type, last_four, expiry_date, card_data) VALUES
            (21, 'Visa', '4242', '', 'x'), (22, 'PayPal', 'buyer@example.test', '', 'x')");
        Http::fake(['https://whmcs.example.test/includes/api.php' => Http::response(['result' => 'success', 'clientid' => $c, 'paymethods' => [
            ['id' => 21, 'remote_token' => json_encode(['customer' => 'cus_Adv1', 'method' => 'pm_Adv1']), 'expiry_date' => '06/29', 'card_last_four' => '4242', 'card_type' => 'Visa'],
            ['id' => 22, 'remote_token' => json_encode(['vaultId' => '9ADV568VAULT', 'customerId' => 'x']), 'expiry_date' => '03/54', 'card_last_four' => 'buyer@example.test', 'card_type' => 'PayPal'],
        ]])]);
        // As ImportFromWhmcs maps them: every PayPal module to the one PayPal gateway.
        $gatewayOf = fn (string $module) => match ($module) {
            'stripe' => $stripe, 'paypal', 'paypal_ppcpv', 'paypalcheckout', 'paypal_acdc' => $paypal, default => null,
        };
        // The item step gave the basic PayPal service its WHMCS way.
        $basic = $this->service(null);
        $basic->forceFill(['payment_gateway_id' => $paypal])->save();

        (new WhmcsPayMethodImport($whmcs, new WhmcsApi('https://whmcs.example.test', 'id-1', 'secret-1'), $gatewayOf, $gatewayOf))->run();

        $wallet = BillingAgreement::query()->where('client_id', $c)->where('gateway_id', $paypal)->first();
        $this->assertNotNull($wallet, 'precondition: the PayPal wallet was imported');
        $this->assertNotNull($this->client->fresh()->default_billing_agreement_id, 'precondition: the card is the default');
        $invoice = $this->invoice($basic->fresh());
        $this->assertSame($paypal, (int) $invoice->items()->value('payment_gateway_id'), 'precondition: its line records PayPal');

        $this->assertNull(ItemPaymentMethod::chargedBy($basic->fresh()), 'basic PayPal was paid by hand in WHMCS: no saved method charges it');
        $this->assertNull(AutoCharge::agreementFor($invoice), 'WHMCS never charged a «paypal» invoice to the paypal_ppcpv wallet');
    }

    /**
     * ADV-568-2. DATA-9 (BillingAgreement::booted): a removed default leaves the account with no default «rather than
     * another card it did not choose». A service set to pay with Stripe (the default's own gateway: it went on the
     * account's invoice, charged by the default) falls to the account's other Stripe card once the customer removes the
     * default card, while the account's other services (no way of their own) rightly stop being charged. A card the
     * customer never chose for renewals is charged (Client::autoChargeMethodFor with no default: any gateway, the
     * default's own included, goes to «the first live method of it»).
     */
    public function test_adv_568_2_removing_the_default_card_does_not_move_its_gateways_items_to_another_card(): void
    {
        $chosen = $this->agreement($this->cardGateway, 'Visa 1111');
        $other = $this->agreement($this->cardGateway, 'Mastercard 2222'); // saved paying once, never made the default
        $this->assertSame($chosen->id, $this->client->fresh()->default_billing_agreement_id, 'precondition');
        $byStripe = $this->invoice($this->service($this->cardGateway));
        $this->assertSame($chosen->id, AutoCharge::agreementFor($byStripe)?->id, 'precondition: the default charges it');

        $chosen->delete(); // the customer removes the card that paid the renewals
        $this->assertNull($this->client->fresh()->default_billing_agreement_id, 'DATA-9: no default');
        $byAccount = $this->invoice($this->service(null));

        $this->assertNull(AutoCharge::agreementFor($byAccount->fresh()), 'the account\'s own wait for a new default');
        $this->assertNotContains($other->id, $this->cron(), 'DATA-9: never another card the customer did not choose');
    }

    /**
     * ADV-568-3. «The first live method of its gateway by id» is the oldest: when a customer replaces an expired card
     * by adding a new one (the old one stays listed), the cron keeps charging the expired card for the items of that
     * gateway and never the valid one, and the customer has no way to pick it (only the default is selectable).
     */
    public function test_adv_568_3_an_expired_old_method_is_not_chosen_over_a_valid_newer_one_of_its_gateway(): void
    {
        $this->agreement($this->vaultGateway, 'PayPal default'); // the default (first)
        $expired = $this->agreement($this->cardGateway, 'Visa 1111 (expired)', '2026-08-31');
        $valid = $this->agreement($this->cardGateway, 'Visa 3333', '2029-12-31');
        $byCard = $this->invoice($this->service($this->cardGateway));

        $this->assertNotSame($expired->id, AutoCharge::agreementFor($byCard)?->id, 'a card expired two months ago');
        $this->assertSame($valid->id, AutoCharge::agreementFor($byCard)?->id, 'the valid card of its gateway');
    }
}
