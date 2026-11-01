<?php

namespace Tests\Feature;

use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Import\WhmcsApi;
use App\Services\Import\WhmcsPayMethodImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

/**
 * WHMCS's saved payment methods as billing agreements: their references through WHMCS's API (GetPayMethods: the key
 * that encrypts them is not in the database), Stripe's customer as the client's stripe_id, the first by WHMCS's order its
 * default; nothing guessed, nothing without the API, no token ever in a note.
 */
class ImportPayMethodsTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private Client $client;

    private Client $other;

    /** @var array<string, int> */
    private array $gateways = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->client = User::factory()->create()->client();
        $this->other = User::factory()->create()->client();
        foreach (['Stripe', 'PayPal', 'Mollie'] as $extension) {
            $this->gateways[$extension] = (int) DB::table('extensions')->insertGetId(['name' => $extension, 'extension' => $extension, 'type' => 'gateway',
                'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblpaymethods (id INTEGER PRIMARY KEY, userid INT, description TEXT, contact_id INT, contact_type TEXT, payment_id INT,
            payment_type TEXT, gateway_name TEXT, order_preference INT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $this->whmcs->exec('CREATE TABLE tblcreditcards (id INTEGER PRIMARY KEY, pay_method_id INT, card_type TEXT, last_four TEXT, expiry_date TEXT,
            card_data TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        // The client's live services pay by Stripe (its default is a Stripe method: decision 6); the other's by bank transfer.
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT, paymentmethod TEXT)');
        $this->whmcs->exec('CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, status TEXT, paymentmethod TEXT)');
        $this->whmcs->exec("INSERT INTO tblhosting (userid, domainstatus, paymentmethod) VALUES ({$this->client->id}, 'Active', 'stripe'),
            ({$this->client->id}, 'Cancelled', 'paypal_ppcpv'), ({$this->other->id}, 'Active', 'banktransfer')");
        $this->whmcs->exec("INSERT INTO tbldomains (userid, status, paymentmethod) VALUES ({$this->client->id}, 'Active', 'stripe')");
        $c = $this->client->id;
        $o = $this->other->id;
        $this->whmcs->exec("INSERT INTO tblpaymethods (id, userid, payment_type, gateway_name, order_preference, deleted_at) VALUES
            (11, {$c}, 'RemoteCreditCard', 'stripe', 1, NULL), (12, {$c}, 'RemoteCreditCard', 'stripe', 0, NULL),
            (13, {$c}, 'RemoteCreditCard', 'paypal_ppcpv', 2, NULL), (14, {$c}, 'RemoteCreditCard', 'stripe', 3, '2025-01-01'),
            (15, {$o}, 'RemoteCreditCard', 'stripe', 0, NULL), (16, 999999, 'RemoteCreditCard', 'stripe', 0, NULL),
            (17, {$o}, 'RemoteCreditCard', 'mollie', 1, NULL)");
        $this->whmcs->exec("INSERT INTO tblcreditcards (pay_method_id, card_type, last_four, expiry_date, card_data) VALUES
            (11, 'Visa', '4242', '', 'x'), (12, 'American Express', '0005', '', 'x'), (13, 'PayPal', 'maria@example.test', '', 'x'), (15, 'MasterCard', '4444', '', 'x')");
    }

    private function fakeApi(): void
    {
        $answers = [
            $this->client->id => [
                ['id' => 11, 'remote_token' => json_encode(['customer' => 'cus_Alpha1', 'method' => 'pm_Visa1']), 'expiry_date' => '06/27', 'card_last_four' => '4242', 'card_type' => 'Visa'],
                ['id' => 12, 'remote_token' => json_encode(['customer' => 'cus_Alpha1', 'method' => 'pm_Amex2']), 'expiry_date' => '01/28', 'card_last_four' => '0005', 'card_type' => 'American Express'],
                // WHMCS's paypal_ppcpv shape (read on nixpal's WHMCS; example values here).
                ['id' => 13, 'remote_token' => json_encode(['vaultId' => '7XK31234AB567890C', 'customerId' => 'abcDEF123', 'transactionIdentifier' => '0AA0000TX']),
                    'expiry_date' => '03/54', 'card_last_four' => 'maria@example.test', 'card_type' => 'PayPal'],
            ],
            // A bare payment method (no customer): never charged blind.
            $this->other->id => [['id' => 15, 'remote_token' => 'pm_Lonely3', 'expiry_date' => '12/26', 'card_last_four' => '4444', 'card_type' => 'MasterCard']],
        ];
        Http::fake(['https://whmcs.example.test/includes/api.php' => function (Request $request) use ($answers) {
            $this->assertSame(['GetPayMethods', 'id-1', 'secret-1'], [$request['action'], $request['identifier'], $request['secret']]);

            return Http::response(['result' => 'success', 'clientid' => $request['clientid'], 'paymethods' => $answers[(int) $request['clientid']] ?? []]);
        }]);
    }

    private function import(?WhmcsApi $api, ?\Closure $itemGateway = null): array
    {
        return (new WhmcsPayMethodImport($this->whmcs, $api, fn (string $module) => match ($module) {
            'stripe', 'stripe_sepa' => $this->gateways['Stripe'], 'paypal_ppcpv' => $this->gateways['PayPal'], 'mollie' => $this->gateways['Mollie'], default => null,
        }, $itemGateway))->run();
    }

    /**
     * One service by card and three by bank transfer (owner 2026-10-29): each keeps its own payment method, so the card
     * is the default and charges the one, as WHMCS did; a second saved method's items are charged by it (owner 2026-11-01),
     * not listed as paid by hand.
     */
    public function test_a_card_for_some_items_is_the_default_when_the_others_keep_their_own_way(): void
    {
        $this->fakeApi();
        $bank = (int) DB::table('extensions')->insertGetId(['name' => 'Bank Transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true,
            'created_at' => now(), 'updated_at' => now()]);
        $c = $this->client->id;
        $this->whmcs->exec("INSERT INTO tblhosting (userid, domainstatus, paymentmethod) VALUES ({$c}, 'Active', 'banktransfer'), ({$c}, 'Active', 'banktransfer'),
            ({$c}, 'Active', 'banktransfer'), ({$c}, 'Active', 'paypal_ppcpv')");
        $items = fn (string $module) => match ($module) {
            'banktransfer' => $bank, 'stripe' => $this->gateways['Stripe'], 'paypal_ppcpv' => $this->gateways['PayPal'], default => null,
        };

        // Its open WHMCS invoices: one of the card's, one by bank transfer (never charged by the card there).
        $byCard = Invoice::factory()->create(['client_id' => $c, 'status' => 'unpaid']);
        $byBank = Invoice::factory()->create(['client_id' => $c, 'status' => 'unpaid']);
        $byWallet = Invoice::factory()->create(['client_id' => $c, 'status' => 'unpaid']);
        foreach ([$byCard, $byWallet] as $open) {
            $open->items()->create(['description' => 'Renewal', 'price' => '5.00', 'quantity' => 1]);
        }
        $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, status TEXT, paymentmethod TEXT)');
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES ({$byCard->id}, {$c}, 'Unpaid', 'stripe'), ({$byBank->id}, {$c}, 'Unpaid', 'banktransfer'),
            ({$byWallet->id}, {$c}, 'Unpaid', 'paypal_ppcpv')");

        $result = $this->import(new WhmcsApi('https://whmcs.example.test', 'id-1', 'secret-1'), $items);

        $this->assertSame([false, true], [(bool) $byCard->fresh()->no_auto_charge, (bool) $byBank->fresh()->no_auto_charge]);
        $client = $this->client->fresh();
        $this->assertSame('pm_Amex2', BillingAgreement::query()->find($client->default_billing_agreement_id)?->external_reference, 'the card\'s, its first by WHMCS\'s order');
        $this->assertTrue((bool) $client->auto_charge, 'the bank transfer ones keep their own way: never charged by it');
        $this->assertSame(0, $result['counts']['not_charged']);
        // The default's gateway's open invoice follows the account (a default changed later charges it, never another card of
        // that gateway: review of #568); the wallet's keeps its way.
        $this->assertNull($byCard->items()->value('payment_gateway_id'));
        $this->assertSame($this->gateways['PayPal'], (int) $byWallet->items()->value('payment_gateway_id'));
        $this->assertStringNotContainsString("Customer #{$c}:", implode("\n", $result['notes']));
    }

    /**
     * Basic PayPal («paypal», paid by hand in WHMCS) beside the PayPal wallet: the wallet charges every PayPal item here, so
     * those items get «Do not charge automatically» each; the account keeps «Auto-charge» for its Stripe ones (ADV-568-1, #568).
     */
    public function test_an_item_of_another_module_on_the_wallets_gateway_is_kept_from_it(): void
    {
        $this->fakeApi();
        $c = $this->client->id;
        $this->whmcs->exec("INSERT INTO tblhosting (id, userid, domainstatus, paymentmethod) VALUES (901, {$c}, 'Active', 'paypal')");
        $this->whmcs->exec("INSERT INTO tblhosting (userid, domainstatus, paymentmethod) VALUES ({$c}, 'Active', 'stripe')");
        $basic = Service::factory()->create(['id' => 901, 'client_id' => $c, 'payment_gateway_id' => $this->gateways['PayPal']]);
        $byCard = Service::factory()->create(['client_id' => $c]);
        $items = fn (string $module) => match ($module) {
            'stripe' => $this->gateways['Stripe'], 'paypal', 'paypal_ppcpv' => $this->gateways['PayPal'], default => null,
        };

        $result = $this->import(new WhmcsApi('https://whmcs.example.test', 'id-1', 'secret-1'), $items);

        $this->assertTrue($basic->fresh()->no_auto_charge);
        $this->assertFalse($byCard->fresh()->no_auto_charge);
        $this->assertTrue((bool) $this->client->fresh()->auto_charge, 'the account\'s Stripe items are charged as before');
        $this->assertSame(1, $result['counts']['items_kept']);
        $this->assertStringContainsString("Customer #{$c}: 1 service(s) and domain(s) paid by «paypal» in WHMCS", implode("\n", $result['notes']));
    }

    /**
     * Two saved modules on one gateway (a Stripe card and Stripe SEPA) beside a PayPal default: each charges its own items in
     * WHMCS, so none of them is kept as «another module's» (review 2 of #568).
     */
    public function test_items_of_a_saved_methods_own_module_are_not_kept(): void
    {
        $c = $this->client->id;
        $this->whmcs->exec("INSERT INTO tblpaymethods (id, userid, payment_type, gateway_name, order_preference, deleted_at) VALUES (18, {$c}, 'RemoteBankAccount', 'stripe_sepa', 4, NULL)");
        $this->whmcs->exec("INSERT INTO tblhosting (id, userid, domainstatus, paymentmethod) VALUES (903, {$c}, 'Active', 'stripe'), (904, {$c}, 'Active', 'stripe_sepa'),
            (905, {$c}, 'Active', 'paypal_ppcpv'), (906, {$c}, 'Active', 'paypal_ppcpv'), (907, {$c}, 'Active', 'paypal_ppcpv')");
        $card = Service::factory()->create(['id' => 903, 'client_id' => $c, 'payment_gateway_id' => $this->gateways['Stripe']]);
        $sepa = Service::factory()->create(['id' => 904, 'client_id' => $c, 'payment_gateway_id' => $this->gateways['Stripe']]);
        Http::fake(['https://whmcs.example.test/includes/api.php' => Http::response(['result' => 'success', 'clientid' => $c, 'paymethods' => [
            ['id' => 12, 'remote_token' => json_encode(['customer' => 'cus_Alpha1', 'method' => 'pm_Amex2']), 'expiry_date' => '01/28', 'card_last_four' => '0005', 'card_type' => 'American Express'],
            ['id' => 13, 'remote_token' => json_encode(['vaultId' => '7XK31234AB567890C', 'customerId' => 'abcDEF123']), 'expiry_date' => '03/54', 'card_last_four' => 'maria@example.test', 'card_type' => 'PayPal'],
            ['id' => 18, 'remote_token' => json_encode(['customer' => 'cus_Alpha1', 'method' => 'pm_Sepa9']), 'expiry_date' => '', 'card_last_four' => '1234', 'card_type' => 'SEPA'],
        ]])]);
        $items = fn (string $module) => match ($module) {
            'stripe', 'stripe_sepa' => $this->gateways['Stripe'], 'paypal_ppcpv' => $this->gateways['PayPal'], default => null,
        };

        $this->import(new WhmcsApi('https://whmcs.example.test', 'id-1', 'secret-1'), $items);

        $this->assertSame($this->gateways['PayPal'], (int) BillingAgreement::query()->find($this->client->fresh()->default_billing_agreement_id)?->gateway_id,
            'precondition: PayPal pays most, the default');
        $this->assertSame(2, BillingAgreement::query()->where('client_id', $c)->where('gateway_id', $this->gateways['Stripe'])->count(), 'precondition: card and SEPA saved');
        $this->assertFalse($card->fresh()->no_auto_charge, 'the card\'s own module');
        $this->assertFalse($sepa->fresh()->no_auto_charge, 'SEPA\'s own module');
    }

    /** One of another way with no payment method here would be charged by the card: «Auto-charge» off, listed (R3-1). */
    public function test_a_way_with_no_payment_method_here_keeps_auto_charge_off(): void
    {
        $this->fakeApi();
        $c = $this->client->id;
        $this->whmcs->exec("INSERT INTO tblhosting (userid, domainstatus, paymentmethod) VALUES ({$c}, 'Active', 'banktransfer')");

        $result = $this->import(new WhmcsApi('https://whmcs.example.test', 'id-1', 'secret-1'), fn (string $module) => $module === 'stripe' ? $this->gateways['Stripe'] : null);

        $this->assertFalse((bool) $this->client->fresh()->auto_charge);
        $this->assertNull($this->client->fresh()->default_billing_agreement_id);
        $this->assertStringContainsString("Customer #{$c}: 2 of its 3 live item(s) paid by a saved method in WHMCS, 1 by a way with no payment method here", implode("\n", $result['notes']));
    }

    public function test_the_methods_come_with_their_references_names_expiry_and_default(): void
    {
        $this->fakeApi();

        $result = $this->import(new WhmcsApi('https://whmcs.example.test', 'id-1', 'secret-1'));

        $agreements = BillingAgreement::query()->where('client_id', $this->client->id)->orderBy('id')->get();
        $this->assertSame([
            ['American Express **** 0005', 'amex', '2028-01-31', 'pm_Amex2', $this->gateways['Stripe']],
            ['Visa **** 4242', 'visa', '2027-06-30', 'pm_Visa1', $this->gateways['Stripe']],
            ['PayPal maria@example.test', 'paypal', null, '7XK31234AB567890C', $this->gateways['PayPal']],
        ], $agreements->map(fn ($a) => [$a->name, $a->type, $a->expiry, $a->external_reference, (int) $a->gateway_id])->all(),
            'by WHMCS\'s order; the deleted one never');
        $this->assertSame($agreements[0]->id, $this->client->fresh()->default_billing_agreement_id,
            'the gateway its live services and domains pay with (Stripe), its first by WHMCS\'s order: as WHMCS charged');
        $this->assertTrue((bool) $this->client->fresh()->auto_charge);
        $this->assertSame('cus_Alpha1', DB::table('properties')->where('model_type', Client::class)->where('model_id', $this->client->id)
            ->where('key', 'stripe_id')->value('value'), 'its Stripe customer, as the gateway keeps it');

        $this->assertSame(0, BillingAgreement::query()->where('client_id', $this->other->id)->count(), 'a bare payment method, Mollie: never guessed');
        $this->assertNull($this->other->fresh()->default_billing_agreement_id);
        $this->assertSame(['methods' => 7 - 1, 'imported' => 3, 'defaults' => 1, 'not_charged' => 0, 'items_kept' => 0, 'skipped' => 3], $result['counts']);
        $notes = implode("\n", $result['notes']);
        $this->assertStringContainsString('customer #999999 not here', $notes);
        $this->assertStringContainsString('«mollie»', $notes);
        foreach (['pm_', 'cus_', '7XK31234'] as $secret) {
            $this->assertStringNotContainsString($secret, $notes, 'never a token in a note');
        }
    }

    public function test_without_the_api_nothing_is_imported_and_it_says_so(): void
    {
        $result = $this->import(null);

        $this->assertSame(0, BillingAgreement::query()->count());
        $this->assertStringContainsString('WHMCS\'s API is needed', implode("\n", $result['notes']));
        $this->expectExceptionMessage('must be https');
        putenv('WHMCS_API_IDENTIFIER=id-1');
        putenv('WHMCS_API_SECRET=secret-1');
        try {
            WhmcsApi::fromEnvironment('http://whmcs.example.test');
        } finally {
            putenv('WHMCS_API_IDENTIFIER');
            putenv('WHMCS_API_SECRET');
        }
        Http::assertNothingSent();
    }

    public function test_the_api_from_the_environment_only_and_reads_only(): void
    {
        putenv('WHMCS_API_IDENTIFIER');
        putenv('WHMCS_API_SECRET');
        $this->assertNull(WhmcsApi::fromEnvironment('https://whmcs.example.test'));
        putenv('WHMCS_API_IDENTIFIER=id-1');
        putenv('WHMCS_API_SECRET=secret-1');
        try {
            $this->assertNotNull(WhmcsApi::fromEnvironment('https://whmcs.example.test/'));
            $this->assertNull(WhmcsApi::fromEnvironment(null));
            $this->expectExceptionMessage('is not a read');
            (new WhmcsApi('https://whmcs.example.test', 'id-1', 'secret-1'))->call('AddClient');
        } finally {
            putenv('WHMCS_API_IDENTIFIER');
            putenv('WHMCS_API_SECRET');
        }
    }
}
