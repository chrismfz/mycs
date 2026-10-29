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
use Throwable;

/**
 * Adversarial review of #538 (WHMCS's saved payment methods as billing agreements, through WHMCS's API). Each test is a
 * finding; example.test data and a fake API only.
 */
class ImportPayMethodsAdversarialTest extends TestCase
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
        $this->whmcs->exec('CREATE TABLE tblbankaccts (id INTEGER PRIMARY KEY, pay_method_id INT, bank_name TEXT, acct_type TEXT, bank_data TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, defaultgateway TEXT, disableautocc INT)');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT, paymentmethod TEXT)');
    }

    /** A WHMCS pay method, its card row and GetPayMethods' answer for it. */
    private function method(int $id, Client $client, string $gateway, int $order, string $token, string $cardType = 'Visa', string $lastFour = '4242',
        string $expiry = '06/27', string $paymentType = 'RemoteCreditCard'): void
    {
        $this->whmcs->prepare('INSERT INTO tblpaymethods (id, userid, payment_type, gateway_name, order_preference) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, $client->id, $paymentType, $gateway, $order]);
        if ($paymentType === 'RemoteCreditCard') {
            $this->whmcs->prepare('INSERT INTO tblcreditcards (pay_method_id, card_type, last_four, expiry_date, card_data) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, $cardType, $lastFour, '', 'x']);
            $this->answers[$client->id][] = ['id' => $id, 'type' => $paymentType, 'gateway_name' => $gateway, 'remote_token' => $token,
                'expiry_date' => $expiry, 'card_last_four' => $lastFour, 'card_type' => $cardType];
        } else {
            $this->whmcs->prepare('INSERT INTO tblbankaccts (pay_method_id, bank_name, acct_type, bank_data) VALUES (?, ?, ?, ?)')
                ->execute([$id, 'Example Bank', '', 'x']);
            // A bank account's shape: no card fields.
            $this->answers[$client->id][] = ['id' => $id, 'type' => $paymentType, 'gateway_name' => $gateway, 'remote_token' => $token,
                'bank_name' => 'Example Bank', 'account_type' => '', 'account_holder_name' => 'Example Holder'];
        }
    }

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
     * ADV-538-1 (High). A customer who pays by bank transfer in WHMCS (its default gateway and every active service
     * «banktransfer», auto-CC not disabled) but has an old Stripe card saved. WHMCS's cron only captures invoices whose
     * payment method is the card's gateway, so it never charged that card. After the import the card is the account's
     * default and «auto_charge» is on (from disableautocc = 0): MyCS charges it at the next renewal. On the local copies:
     * myip 39 customers whose first method's gateway pays none of their active services, nixpal 6. The billing cursor's own
     * migration rule (docs/billing-cursor.md: methods but no service charged automatically → «Auto-charge» off) is not
     * followed here.
     */
    public function test_adv_538_1_a_bank_transfer_customer_with_an_old_card_is_not_auto_charged_after_the_import(): void
    {
        $this->whmcs->exec("INSERT INTO tblclients (id, defaultgateway, disableautocc) VALUES ({$this->client->id}, 'banktransfer', 0)");
        $this->whmcs->exec("INSERT INTO tblhosting (userid, domainstatus, paymentmethod) VALUES ({$this->client->id}, 'Active', 'banktransfer')");
        $this->method(11, $this->client, 'stripe', 0, self::stripeToken('cus_Bank1', 'pm_Old1'));

        $this->run_();

        $this->assertSame(1, BillingAgreement::query()->where('client_id', $this->client->id)->count(), 'the card itself is brought');
        $this->assertNull($this->client->fresh()->autoChargeAgreement(),
            'WHMCS never charged this card (its services pay by bank transfer): MyCS must not start charging it');
    }

    /**
     * ADV-538-2 (Medium). `--whmcs-api-url=http://…` (a typo, or WHMCS's old URL): the identifier and the secret go in a
     * plain HTTP POST body, readable on the way, and the answers carry every customer's Stripe and PayPal references. The
     * importer should refuse anything but https before sending anything.
     */
    public function test_adv_538_2_the_api_secret_is_never_sent_over_plain_http(): void
    {
        Http::fake();
        putenv('WHMCS_API_IDENTIFIER=id-1');
        putenv('WHMCS_API_SECRET=secret-1');
        try {
            WhmcsApi::fromEnvironment('http://whmcs.example.test')?->call('GetPayMethods', ['clientid' => 1]);
        } catch (Throwable) {
            // refused: what we want
        } finally {
            putenv('WHMCS_API_IDENTIFIER');
            putenv('WHMCS_API_SECRET');
        }

        Http::assertNothingSent();
    }

    /**
     * ADV-538-3 (Medium). One customer with methods of two Stripe customers in WHMCS (cus_A first, cus_B second). The first
     * becomes its stripe_id; the second method (pm of cus_B) is still imported, although the Stripe gateway charges it with
     * customer = stripe_id (cus_A): Stripe refuses a payment method of another customer, so it can never be charged. The
     * customer sees «Visa **** 1111», may pick it for automatic payments, and every renewal fails. The PR only writes a note.
     */
    public function test_adv_538_3_a_method_of_a_second_stripe_customer_is_not_imported_as_chargeable(): void
    {
        $this->method(11, $this->client, 'stripe', 0, self::stripeToken('cus_First1', 'pm_First1'), 'Visa', '4242');
        $this->method(12, $this->client, 'stripe', 1, self::stripeToken('cus_Second2', 'pm_Second2'), 'Visa', '1111');

        $this->run_();

        $this->assertSame('cus_First1', DB::table('properties')->where('model_type', Client::class)->where('model_id', $this->client->id)
            ->where('key', 'stripe_id')->value('value'));
        $this->assertFalse(BillingAgreement::query()->where('client_id', $this->client->id)->where('external_reference', 'pm_Second2')->exists(),
            'charged with customer cus_First1, pm_Second2 is always refused by Stripe: it must not become a method the customer can choose');
    }

    /**
     * ADV-538-4 (Medium). Two WHMCS customers share one Stripe customer (a merged or duplicated account). Both get the same
     * stripe_id, silently: the second account's new cards are then saved on the first's Stripe customer, and Stripe's
     * receipts for its charges go to the first's e-mail. The import should at least list it for the staff (no token).
     */
    public function test_adv_538_4_two_customers_sharing_one_stripe_customer_are_listed(): void
    {
        $this->method(11, $this->client, 'stripe', 0, self::stripeToken('cus_Shared1', 'pm_Mine1'));
        $this->method(12, $this->other, 'stripe', 0, self::stripeToken('cus_Shared1', 'pm_Theirs2'));

        $result = $this->run_();

        $listed = array_filter($result['notes'], fn (string $note) => str_contains($note, "#{$this->client->id}") && str_contains($note, "#{$this->other->id}"));
        $this->assertNotEmpty($listed, 'two accounts on one Stripe customer: listed, naming both');
        $this->assertStringNotContainsString('cus_Shared1', implode("\n", $result['notes']));
    }

    /**
     * ADV-538-5 (High). The same Stripe payment method twice in WHMCS (saved twice: two tblpaymethods rows, one token; or
     * two accounts on one Stripe customer with the same card). billing_agreements.external_reference is unique, so the
     * second insert throws a UniqueConstraintViolationException out of run(): the whole import stops there, before
     * journalMoney, and ImportFromWhmcs logs the exception whole (Log::error), with the insert's SQL and its bindings, the
     * payment method id among them. Expected: one agreement, the duplicate listed, the import going on.
     */
    public function test_adv_538_5_the_same_payment_method_twice_is_one_agreement_and_does_not_stop_the_import(): void
    {
        $this->method(11, $this->client, 'stripe', 0, self::stripeToken('cus_Twice1', 'pm_Twice1'));
        $this->method(12, $this->client, 'stripe', 1, self::stripeToken('cus_Twice1', 'pm_Twice1'));

        $result = $this->run_(); // throws now: UNIQUE constraint failed: billing_agreements.external_reference

        $this->assertSame(1, BillingAgreement::query()->where('client_id', $this->client->id)->where('external_reference', 'pm_Twice1')->count());
        $this->assertStringNotContainsString('pm_Twice1', implode("\n", $result['notes']));
    }

    /**
     * ADV-538-6 (Low: none on the local copies). The importer maps paypal_acdc (PayPal's Advanced Card Processing: a card
     * vaulted at PayPal) to the PayPal gateway, and this import writes it as a PayPal wallet («PayPal», type paypal). The
     * PayPal gateway charges with payment_source.paypal.vault_id: a card's vault id is refused there, every time. It should
     * be skipped and listed, like a Mollie method.
     */
    public function test_adv_538_6_a_card_vaulted_at_paypal_is_not_imported_as_a_paypal_wallet(): void
    {
        $this->method(11, $this->client, 'paypal_acdc', 0, '9AB12345CD678901E', 'Visa', '4242');

        $this->run_();

        $this->assertSame(0, BillingAgreement::query()->where('client_id', $this->client->id)->count(),
            'charged as payment_source.paypal.vault_id, a card vault id never succeeds');
    }

    /**
     * ADV-538-7 (Low). An expiry in WHMCS's older MMYY form («1227», no slash): the regex wants a slash, so Carbon::parse
     * reads «1227» as a time of day, and the card is written as expiring at the end of the current month. The expiry
     * reminders (H2b) then tell the customer a valid card is expiring now.
     */
    public function test_adv_538_7_an_mmyy_expiry_is_not_read_as_the_current_month(): void
    {
        $this->method(11, $this->client, 'stripe', 0, self::stripeToken('cus_Exp1', 'pm_Exp1'), 'Visa', '4242', '1227');

        $this->run_();

        $expiry = BillingAgreement::query()->where('client_id', $this->client->id)->value('expiry');
        $this->assertContains($expiry, ['2027-12-31', null], 'December 2027, or unknown: never «this month»');
    }

    /**
     * ADV-538-8 (Low). A Stripe SEPA mandate (RemoteBankAccount, myip has 4): GetPayMethods answers a bank account without
     * card fields, and its row is in tblbankaccts, not tblcreditcards. The name comes out «SEPA Direct Debit **** », with
     * nothing after the stars.
     */
    public function test_adv_538_8_a_sepa_mandate_is_not_named_with_empty_stars(): void
    {
        $this->method(11, $this->client, 'stripe_sepa', 0, self::stripeToken('cus_Sepa1', 'pm_Sepa1'), paymentType: 'RemoteBankAccount');

        $this->run_();

        $agreement = BillingAgreement::query()->where('client_id', $this->client->id)->firstOrFail();
        $this->assertSame('sepa_debit', $agreement->type);
        $this->assertStringEndsNotWith('****', rtrim($agreement->name), 'no digits to show: no stars either');
    }
}
