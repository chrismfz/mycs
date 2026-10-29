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
 * Round-2 adversarial review of #538 (the default and «Auto-charge» as WHMCS charged). Each test is a finding;
 * example.test data and a fake API only (Http::preventStrayRequests).
 */
class ImportPayMethodsRound2Test extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private Client $client;

    private Client $other;

    /** @var array<string, int> */
    private array $gateways = [];

    /** @var array<int, list<array<string, mixed>>> client id => GetPayMethods' paymethods */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->client = User::factory()->create()->client();
        $this->other = User::factory()->create()->client();
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
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT, paymentmethod TEXT, billingcycle TEXT)');
        $this->whmcs->exec('CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, status TEXT, paymentmethod TEXT, expirydate TEXT)');
    }

    private function method(int $id, Client $client, string $gateway, int $order, string $token, string $lastFour = '4242'): void
    {
        $this->whmcs->prepare('INSERT INTO tblpaymethods (id, userid, payment_type, gateway_name, order_preference) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, $client->id, 'RemoteCreditCard', $gateway, $order]);
        $this->whmcs->prepare('INSERT INTO tblcreditcards (pay_method_id, card_type, last_four, expiry_date, card_data) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, 'Visa', $lastFour, '', 'x']);
        $this->answers[$client->id][] = ['id' => $id, 'type' => 'RemoteCreditCard', 'gateway_name' => $gateway, 'remote_token' => $token,
            'expiry_date' => '06/30', 'card_last_four' => $lastFour, 'card_type' => 'Visa'];
    }

    private function service(Client $client, string $gateway, string $status = 'Active'): void
    {
        $this->whmcs->prepare("INSERT INTO tblhosting (userid, domainstatus, paymentmethod, billingcycle) VALUES (?, ?, ?, 'Monthly')")
            ->execute([$client->id, $status, $gateway]);
    }

    private function domain(Client $client, string $gateway, string $status, string $expiry): void
    {
        $this->whmcs->prepare('INSERT INTO tbldomains (userid, status, paymentmethod, expirydate) VALUES (?, ?, ?, ?)')
            ->execute([$client->id, $status, $gateway, $expiry]);
    }

    /** @return array{counts: array<string, int>, notes: list<string>} */
    private function run_(): array
    {
        $answers = $this->answers;
        Http::fake(['https://whmcs.example.test/includes/api.php' => fn (Request $request) => Http::response(['result' => 'success',
            'clientid' => $request['clientid'], 'paymethods' => $answers[(int) $request['clientid']] ?? []])]);

        return (new WhmcsPayMethodImport($this->whmcs, new WhmcsApi('https://whmcs.example.test', 'id-1', 'secret-1'), fn (string $module) => match ($module) {
            'stripe', 'stripe_sepa' => $this->gateways['Stripe'], 'paypal_ppcpv', 'paypal_acdc', 'paypalcheckout' => $this->gateways['PayPal'], default => null,
        }))->run();
    }

    private static function stripeToken(string $customer, string $method): string
    {
        return (string) json_encode(['customer' => $customer, 'method' => $method]);
    }

    /**
     * ADV-538-R2-1 (Medium, money). A customer with auto-CC on in WHMCS, one service on «stripe» and three on «banktransfer»,
     * one card saved. WHMCS charged the card for the one service's invoices only. The rule (billing-cursor decision 6, the
     * PR's fix 1) is the gateway *most* of the client's live items pay with: here bank transfer, which has no saved method,
     * so no default and «Auto-charge» off. chooseDefault counts only the saved methods' gateways (any item on one of them
     * wins), so the card becomes the account's default with «Auto-charge» on, and MyCS (one method per account, grouped
     * renewal invoices) charges it for all four services, three of which WHMCS never charged. On the copies: myip 18
     * customers with auto-CC on whose saved-gateway items are under half of their live items, nixpal 3.
     */
    public function test_adv_538_r2_1_a_minority_card_service_does_not_make_the_card_charge_every_renewal(): void
    {
        $this->client->forceFill(['auto_charge' => true])->save();
        $this->service($this->client, 'stripe');
        foreach (range(1, 3) as $_) {
            $this->service($this->client, 'banktransfer');
        }
        $this->method(11, $this->client, 'stripe', 0, self::stripeToken('cus_Min1', 'pm_Min1'));

        $this->run_();

        $this->assertSame(1, BillingAgreement::query()->where('client_id', $this->client->id)->count(), 'the card itself is brought');
        $this->assertNull($this->client->fresh()->autoChargeAgreement(),
            'most of its services pay by bank transfer: the card must not start paying all of them');
    }

    /**
     * ADV-538-R2-2 (Low). WHMCS's order across gateways: card A1 (stripe, order 0, id 1), a PayPal wallet (order 1, id 5),
     * card A2 (stripe, order 2, id 10); one service on «stripe», one domain on «paypal_ppcpv» (a tie). Within a gateway the
     * rule takes its first (A1, as WHMCS charges); between the tied gateways the newer. The loop compares each method with
     * the current best only: A1 is best, PayPal (5 > 1) replaces it, then A2 (another module, 10 > 5) replaces PayPal. The
     * default becomes A2, the stripe card WHMCS never charged (it charges the first by order).
     */
    public function test_adv_538_r2_2_a_gateways_second_method_never_wins_a_tie(): void
    {
        $this->service($this->client, 'stripe');
        $this->domain($this->client, 'paypal_ppcpv', 'Active', now()->addYear()->toDateString());
        $this->method(1, $this->client, 'stripe', 0, self::stripeToken('cus_Tie1', 'pm_A1'), '1111');
        $this->method(5, $this->client, 'paypal_ppcpv', 1, '7XY12345AB678901C');
        $this->method(10, $this->client, 'stripe', 2, self::stripeToken('cus_Tie1', 'pm_A2'), '2222');

        $this->run_();

        $default = BillingAgreement::query()->find($this->client->fresh()->default_billing_agreement_id);
        $this->assertNotNull($default);
        $this->assertNotSame('pm_A2', $default->external_reference, 'within stripe its first (pm_A1); between the tied gateways A1 or PayPal, never A2');
    }

    /**
     * ADV-538-R2-3 (Low: none on the copies). The usage query counts domains «Expired» and «Redemption» as live. A customer
     * with auto-CC on whose only item on a saved method's gateway is a domain that expired two years ago (WHMCS no longer
     * invoices it), its services on bank transfer: the card becomes the default with «Auto-charge» on, and MyCS charges it
     * for the bank-transfer services WHMCS never charged.
     */
    public function test_adv_538_r2_3_a_long_expired_domain_does_not_make_the_card_the_default(): void
    {
        $this->client->forceFill(['auto_charge' => true])->save();
        $this->service($this->client, 'banktransfer');
        $this->domain($this->client, 'stripe', 'Expired', now()->subYears(2)->toDateString());
        $this->method(11, $this->client, 'stripe', 0, self::stripeToken('cus_Exp1', 'pm_Exp1'));

        $this->run_();

        $this->assertNull($this->client->fresh()->autoChargeAgreement(), 'an expired domain is not invoiced in WHMCS: nothing it charged');
    }

    /**
     * ADV-538-R2-4 (Low). Two accounts on one Stripe customer with the same card (the realistic shared case: one payment
     * method attached once to the customer, saved on both WHMCS accounts). The second account's method is skipped as «the
     * same reference», before the transaction, so the shared customer is never recorded (stripeCustomers is filled only on
     * an import) and the «two accounts on one Stripe customer» note (ADV-538-4's fix) is not written: the staff see a
     * duplicate on one account, not that the two share a customer, and the second account (charged by WHMCS) is left with
     * no method.
     */
    public function test_adv_538_r2_4_two_accounts_sharing_one_stripe_card_are_listed_as_sharing_a_customer(): void
    {
        $this->method(11, $this->client, 'stripe', 0, self::stripeToken('cus_Shared9', 'pm_Same9'));
        $this->method(12, $this->other, 'stripe', 0, self::stripeToken('cus_Shared9', 'pm_Same9'));

        $result = $this->run_();

        $both = array_filter($result['notes'], fn (string $note) => str_contains($note, "#{$this->client->id}") && str_contains($note, "#{$this->other->id}"));
        $this->assertNotEmpty($both, 'one note naming both accounts');
        $this->assertStringNotContainsString('cus_Shared9', implode("\n", $result['notes']));
        $this->assertStringNotContainsString('pm_Same9', implode("\n", $result['notes']));
    }
}
