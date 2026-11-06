<?php

namespace Tests\Feature;

use App\Models\BillingAgreement;
use App\Models\Gateway;
use App\Models\Service;
use App\Models\User;
use App\Services\Import\WhmcsApi;
use App\Services\Import\WhmcsPayMethodImport;
use App\Services\Payment\ItemPaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

/**
 * Adversarial review of #679 (the default's own gateway under another module; ways of paying as disabled gateways).
 * Fixtures only, no real data.
 */
class Adv679PayMethodsTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    /** @var array<string, int> */
    private array $gateways = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        foreach (['Stripe', 'PayPal'] as $extension) {
            $this->gateways[$extension] = (int) DB::table('extensions')->insertGetId(['name' => $extension, 'extension' => $extension, 'type' => 'gateway',
                'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        // As importPaymentWays installs it: disabled.
        $this->gateways['BankTransfer'] = (int) DB::table('extensions')->insertGetId(['name' => 'Bank deposit', 'extension' => 'BankTransfer', 'type' => 'gateway',
            'enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblpaymethods (id INTEGER PRIMARY KEY, userid INT, description TEXT, contact_id INT, contact_type TEXT, payment_id INT,
            payment_type TEXT, gateway_name TEXT, order_preference INT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $this->whmcs->exec('CREATE TABLE tblcreditcards (id INTEGER PRIMARY KEY, pay_method_id INT, card_type TEXT, last_four TEXT, expiry_date TEXT,
            card_data TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT, paymentmethod TEXT)');
        $this->whmcs->exec('CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, status TEXT, paymentmethod TEXT)');
    }

    private function import(): array
    {
        $map = fn (string $module) => match ($module) {
            'stripe', 'stripe_sepa' => $this->gateways['Stripe'], 'paypal', 'paypal_ppcpv' => $this->gateways['PayPal'],
            'banktransfer' => $this->gateways['BankTransfer'], default => null,
        };

        return (new WhmcsPayMethodImport($this->whmcs, new WhmcsApi('https://whmcs.example.test', 'id-1', 'secret-1'),
            fn (string $module) => $module === 'banktransfer' ? null : $map($module), $map))->run();
    }

    /**
     * ADV-679-1. A Stripe card and Stripe SEPA both saved, the card the default (most items): WHMCS charged the SEPA items
     * with the SEPA mandate. The new «default's gateway under another module» rule seeds them as foreign without the
     * check the loop below it makes (`!isset($first[$item])`), so they get «Do not charge automatically»; with a PayPal
     * default the same items are charged (test_items_of_a_saved_methods_own_module_are_not_kept).
     */
    public function test_sepa_items_beside_a_stripe_card_default_are_still_charged(): void
    {
        $c = User::factory()->create()->client()->id;
        $this->whmcs->exec("INSERT INTO tblpaymethods (id, userid, payment_type, gateway_name, order_preference, deleted_at) VALUES
            (21, {$c}, 'RemoteCreditCard', 'stripe', 0, NULL), (22, {$c}, 'RemoteBankAccount', 'stripe_sepa', 1, NULL)");
        $this->whmcs->exec("INSERT INTO tblhosting (id, userid, domainstatus, paymentmethod) VALUES (931, {$c}, 'Active', 'stripe'), (932, {$c}, 'Active', 'stripe'),
            (933, {$c}, 'Active', 'stripe_sepa')");
        $card = Service::factory()->create(['id' => 931, 'client_id' => $c, 'payment_gateway_id' => $this->gateways['Stripe']]);
        $sepa = Service::factory()->create(['id' => 933, 'client_id' => $c, 'payment_gateway_id' => $this->gateways['Stripe']]);
        Http::fake(['https://whmcs.example.test/includes/api.php' => Http::response(['result' => 'success', 'clientid' => $c, 'paymethods' => [
            ['id' => 21, 'remote_token' => json_encode(['customer' => 'cus_Beta1', 'method' => 'pm_Card1']), 'expiry_date' => '01/40', 'card_last_four' => '4242', 'card_type' => 'Visa'],
            ['id' => 22, 'remote_token' => json_encode(['customer' => 'cus_Beta1', 'method' => 'pm_Sepa1']), 'expiry_date' => '', 'card_last_four' => '1234', 'card_type' => 'SEPA'],
        ]])]);

        $this->import();

        $this->assertSame(2, BillingAgreement::query()->where('client_id', $c)->where('gateway_id', $this->gateways['Stripe'])->count(), 'precondition: card and SEPA saved');
        $this->assertSame('pm_Card1', BillingAgreement::query()->find(DB::table('clients')->where('id', $c)->value('default_billing_agreement_id'))?->external_reference,
            'precondition: the card is the default');
        $this->assertFalse((bool) $card->fresh()->no_auto_charge);
        $this->assertFalse((bool) $sepa->fresh()->no_auto_charge, 'SEPA\'s own module, charged in WHMCS by its saved mandate');
    }

    /**
     * ADV-679-2. A card customer with bank transfer items was «Auto-charge» off before #679; now it stays on, and only the
     * imported, disabled «Bank transfer» gateway keeps the card from those items. Staff deleting that gateway (one they
     * never set up: nixpal, or myip's unused Eurobank one) turns the items into «the account's» (ItemPaymentMethod::live,
     * chargedBy: «deleted: the account's») and the card charges what WHMCS never charged.
     */
    public function test_deleting_the_imported_disabled_gateway_does_not_let_the_card_charge_its_items(): void
    {
        $c = User::factory()->create()->client()->id;
        $this->whmcs->exec("INSERT INTO tblpaymethods (id, userid, payment_type, gateway_name, order_preference, deleted_at) VALUES (31, {$c}, 'RemoteCreditCard', 'stripe', 0, NULL)");
        $this->whmcs->exec("INSERT INTO tblhosting (id, userid, domainstatus, paymentmethod) VALUES (941, {$c}, 'Active', 'stripe'), (942, {$c}, 'Active', 'banktransfer')");
        Service::factory()->create(['id' => 941, 'client_id' => $c, 'payment_gateway_id' => $this->gateways['Stripe']]);
        $byBank = Service::factory()->create(['id' => 942, 'client_id' => $c, 'payment_gateway_id' => $this->gateways['BankTransfer']]);
        Http::fake(['https://whmcs.example.test/includes/api.php' => Http::response(['result' => 'success', 'clientid' => $c, 'paymethods' => [
            ['id' => 31, 'remote_token' => json_encode(['customer' => 'cus_Gamma1', 'method' => 'pm_Card3']), 'expiry_date' => '01/40', 'card_last_four' => '4242', 'card_type' => 'Visa'],
        ]])]);

        $this->import();

        $this->assertTrue((bool) DB::table('clients')->where('id', $c)->value('auto_charge'), 'precondition: #679 keeps the account charged');
        $this->assertNull(ItemPaymentMethod::chargedBy($byBank->fresh()), 'precondition: the bank transfer item is not charged');

        Gateway::query()->findOrFail($this->gateways['BankTransfer'])->delete(); // staff remove a gateway they do not use

        $this->assertNull(ItemPaymentMethod::chargedBy($byBank->fresh()), 'WHMCS never charged it: the card must not either');
    }
}
