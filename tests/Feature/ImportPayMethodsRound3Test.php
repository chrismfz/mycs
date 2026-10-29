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
 * Round-3 review of #538 (the default and «Auto-charge» by all of the client's gateways). Each test is a finding;
 * example.test data and a fake API only (Http::preventStrayRequests).
 */
class ImportPayMethodsRound3Test extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private Client $client;

    /** @var array<string, int> */
    private array $gateways = [];

    /** @var array<int, list<array<string, mixed>>> client id => GetPayMethods' paymethods */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->client = User::factory()->create()->client();
        $this->client->forceFill(['auto_charge' => true])->save();
        foreach (['Stripe', 'PayPal'] as $extension) {
            $this->gateways[$extension] = (int) DB::table('extensions')->insertGetId(['name' => $extension, 'extension' => $extension, 'type' => 'gateway',
                'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblpaymethods (id INTEGER PRIMARY KEY, userid INT, description TEXT, contact_id INT, contact_type TEXT, payment_id INT,
            payment_type TEXT, gateway_name TEXT, order_preference INT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $this->whmcs->exec('CREATE TABLE tblcreditcards (id INTEGER PRIMARY KEY, pay_method_id INT, card_type TEXT, last_four TEXT, expiry_date TEXT,
            card_data TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        // WHMCS's columns: a subscription, a free account, a domain set not to renew.
        $this->whmcs->exec("CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT, paymentmethod TEXT, billingcycle TEXT,
            amount REAL DEFAULT 10, subscriptionid TEXT DEFAULT '')");
        $this->whmcs->exec('CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, status TEXT, paymentmethod TEXT, expirydate TEXT,
            recurringamount REAL DEFAULT 10, donotrenew INT DEFAULT 0)');
    }

    private function method(int $id, string $gateway, string $token, string $lastFour = '4242'): void
    {
        $this->whmcs->prepare('INSERT INTO tblpaymethods (id, userid, payment_type, gateway_name, order_preference) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, $this->client->id, 'RemoteCreditCard', $gateway, 0]);
        $this->whmcs->prepare('INSERT INTO tblcreditcards (pay_method_id, card_type, last_four, expiry_date, card_data) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, 'Visa', $lastFour, '', 'x']);
        $this->answers[$this->client->id][] = ['id' => $id, 'type' => 'RemoteCreditCard', 'gateway_name' => $gateway, 'remote_token' => $token,
            'expiry_date' => '06/30', 'card_last_four' => $lastFour, 'card_type' => 'Visa'];
    }

    private function service(string $gateway, string $cycle = 'Monthly', float $amount = 10, string $subscription = ''): void
    {
        $this->whmcs->prepare("INSERT INTO tblhosting (userid, domainstatus, paymentmethod, billingcycle, amount, subscriptionid) VALUES (?, 'Active', ?, ?, ?, ?)")
            ->execute([$this->client->id, $gateway, $cycle, $amount, $subscription]);
    }

    private function domain(string $gateway, bool $doNotRenew = false): void
    {
        $this->whmcs->prepare("INSERT INTO tbldomains (userid, status, paymentmethod, expirydate, donotrenew) VALUES (?, 'Active', ?, ?, ?)")
            ->execute([$this->client->id, $gateway, now()->addMonths(3)->toDateString(), (int) $doNotRenew]);
    }

    /** @return array{counts: array<string, int>, notes: list<string>} */
    private function run_(): array
    {
        $answers = $this->answers;
        Http::fake(['https://whmcs.example.test/includes/api.php' => fn (Request $request) => Http::response(['result' => 'success',
            'clientid' => $request['clientid'], 'paymethods' => $answers[(int) $request['clientid']] ?? []])]);

        return (new WhmcsPayMethodImport($this->whmcs, new WhmcsApi('https://whmcs.example.test', 'id-1', 'secret-1'), fn (string $module) => match ($module) {
            'stripe', 'stripe_sepa' => $this->gateways['Stripe'], 'paypal', 'paypal_ppcpv', 'paypal_acdc', 'paypalcheckout' => $this->gateways['PayPal'], default => null,
        }))->run();
    }

    private static function stripeToken(string $customer, string $method): string
    {
        return (string) json_encode(['customer' => $customer, 'method' => $method]);
    }

    /**
     * ADV-538-R3-1 (Medium, money: charges that stop). The round-2 rule turns «Auto-charge» off for a customer whose
     * leading gateway has no saved method even when some of its live items are on the saved method's gateway, i.e.
     * items WHMCS did charge to that card. On the copies (aggregates, items active/suspended or domains active/grace,
     * payments of the last 12 months): myip 23 such «off» customers have a live item on a saved method's gateway and
     * 20 of them paid through it; nixpal 3 and 3. After the cut-over those items' renewals are no longer charged: they
     * go unpaid, to reminders and suspension. That trade-off may be right (one method pays all of an account's
     * renewals here), but the customers are only counted, never listed, and the console line and docs/importer.md say
     * they are customers «none of whose services are on a saved method's gateway» / «whom the card did not pay in
     * practice», which is not so. Staff need the list to tell them or to set the method by hand.
     */
    public function test_adv_538_r3_1_a_customer_whose_charged_card_is_turned_off_is_listed(): void
    {
        $this->service('stripe');
        foreach (range(1, 3) as $_) {
            $this->service('banktransfer');
        }
        $this->method(11, 'stripe', self::stripeToken('cus_R3a', 'pm_R3a'));

        $result = $this->run_();

        $this->assertFalse((bool) $this->client->fresh()->auto_charge, 'the round-2 rule itself (unchanged): no card paying all four');
        $listed = array_filter($result['notes'], fn (string $note) => str_contains($note, "#{$this->client->id}"));
        $this->assertNotEmpty($listed, 'a customer WHMCS charged for one service, now charged for none: listed for staff');
        $this->assertStringNotContainsString('pm_R3a', implode("\n", $result['notes']));
        $this->assertStringNotContainsString('cus_R3a', implode("\n", $result['notes']));
    }

    /**
     * ADV-538-R3-2 (Low: none on the copies). Items paid by a gateway subscription count as charged by the saved
     * method. Two services on «paypal_ppcpv» paid by a PayPal subscription (tblhosting.subscriptionid), one on bank
     * transfer, a PayPal vault saved: WHMCS never charged the vault (the subscriptions pay themselves, the bank
     * transfer is paid by hand), yet the vault becomes the default with «Auto-charge» on, and AutoCharge (which skips
     * only the subscribed services' invoices) charges it for the bank-transfer service.
     */
    public function test_adv_538_r3_2_subscription_paid_services_do_not_make_the_vault_the_default(): void
    {
        $this->service('paypal_ppcpv', subscription: 'I-SUBR3A');
        $this->service('paypal_ppcpv', subscription: 'I-SUBR3B');
        $this->service('banktransfer');
        $this->method(21, 'paypal_ppcpv', (string) json_encode(['vaultId' => '7R3VAULT01', 'customerId' => 'CUSTR3', 'transactionIdentifier' => 'TXR3']));

        $this->run_();

        $this->assertSame(1, BillingAgreement::query()->where('client_id', $this->client->id)->count(), 'the vault itself is brought');
        $this->assertNull($this->client->fresh()->autoChargeAgreement(),
            'only the bank-transfer service is charged by MyCS here, and WHMCS never charged the vault for it');
    }

    /**
     * ADV-538-R3-3 (Low: none on the copies). Items WHMCS never invoices count as charged: a «Free Account» service
     * and a domain set «Do not renew», both on «stripe», outweigh the one paid service on bank transfer. WHMCS never
     * charged the card (nothing on stripe was invoiced), yet the card becomes the default with «Auto-charge» on and
     * pays the bank-transfer renewals.
     */
    public function test_adv_538_r3_3_free_and_not_renewing_items_do_not_make_the_card_the_default(): void
    {
        $this->service('stripe', 'Free Account', 0);
        $this->domain('stripe', doNotRenew: true);
        $this->service('banktransfer');
        $this->method(31, 'stripe', self::stripeToken('cus_R3c', 'pm_R3c'));

        $this->run_();

        $this->assertNull($this->client->fresh()->autoChargeAgreement(), 'nothing on stripe was ever invoiced, so never charged to the card');
    }
}
