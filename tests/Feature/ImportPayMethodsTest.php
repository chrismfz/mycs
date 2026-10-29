<?php

namespace Tests\Feature;

use App\Models\BillingAgreement;
use App\Models\Client;
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
     * is the default and charges the one, as WHMCS did; a second saved method's items are listed, paid by hand.
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

        $result = $this->import(new WhmcsApi('https://whmcs.example.test', 'id-1', 'secret-1'), $items);

        $client = $this->client->fresh();
        $this->assertSame('pm_Amex2', BillingAgreement::query()->find($client->default_billing_agreement_id)?->external_reference, 'the card\'s, its first by WHMCS\'s order');
        $this->assertTrue((bool) $client->auto_charge, 'the bank transfer ones keep their own way: never charged by it');
        $this->assertSame(0, $result['counts']['not_charged']);
        $this->assertStringContainsString("Customer #{$c}: 1 live item(s) charged by a second saved method in WHMCS («paypal_ppcpv»)", implode("\n", $result['notes']));
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
        $this->assertSame(['methods' => 7 - 1, 'imported' => 3, 'defaults' => 1, 'not_charged' => 0, 'skipped' => 3], $result['counts']);
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
