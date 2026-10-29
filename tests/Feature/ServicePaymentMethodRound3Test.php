<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Import\WhmcsPayMethodImport;
use App\Services\Payment\AutoCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

/**
 * Round-3 review of #541 (an item's own payment method), on the round-2 fix 6c88772c: each line records its item's way as
 * billed (invoice_items.payment_gateway_id, AutoCharge::paidAnotherWay), and the importer marks WHMCS's open invoices
 * (openInvoicesKeepTheirWay). Each test_r3_541_N fails on that head for a real issue. Owner's invariants: the account's
 * saved card is never charged automatically for an item paying another way; the import never lets the card charge what
 * WHMCS would not have.
 */
class ServicePaymentMethodRound3Test extends TestCase
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
        Cache::flush();
        config(['settings.credits_auto_use' => false]);
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        $this->client = User::factory()->create()->client();
        $this->bank = Gateway::query()->create(['name' => 'Bank Transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $this->cardGateway = Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $this->paypal = Gateway::query()->create(['name' => 'PayPal', 'extension' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        $this->card = BillingAgreement::query()->create(['ulid' => (string) Str::ulid(), 'client_id' => $this->client->id,
            'gateway_id' => $this->cardGateway->id, 'name' => 'Visa **** 4242', 'type' => 'visa', 'external_reference' => 'pm_' . Str::random(8)]);
        // Saved, not yet the default: each test says when it is.
        DB::table('clients')->where('id', $this->client->id)->update(['default_billing_agreement_id' => null, 'auto_charge' => true]);
        $this->client->refresh();
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

        return Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => '2026-10-25', 'payment_gateway_id' => $gateway?->id]);
    }

    /**
     * R3-541-1 (medium). Invariant 4 when the import runs without WHMCS's API (a supported mode: no --whmcs-api-url or
     * WHMCS_API_* in the environment, WhmcsApi::fromEnvironment gives null). WhmcsPayMethodImport::run() then returns
     * early when WHMCS has saved methods ("not imported: WHMCS's API is needed"), before openInvoicesKeepTheirWay, which
     * round 2 moved to "every run, every customer" (R2-541-3/4). A customer paying by bank transfer keeps its open WHMCS
     * renewal invoice unflagged; after the cut-over it saves a card (Auto-charge on) and the cron charges the WHMCS
     * bank-transfer invoice, which WHMCS never would. The methods === [] path does mark them. Expected: WHMCS's open
     * invoices keep their way on every run, the API or not (they need nothing from it).
     */
    public function test_r3_541_1_without_whmcs_api_a_bank_transfer_whmcs_invoice_is_left_to_a_card_saved_later(): void
    {
        $service = $this->service($this->bank);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-10-25']);
        $invoice->items()->create(['description' => 'Renewal', 'price' => 10, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => '2026-10-25', 'period_end' => '2026-11-25']);
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT, paymentmethod TEXT)');
        $whmcs->exec('CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, status TEXT, paymentmethod TEXT)');
        $whmcs->exec('CREATE TABLE tblpaymethods (id INTEGER PRIMARY KEY, userid INT, description TEXT, contact_id INT, contact_type TEXT, payment_id INT,
            payment_type TEXT, gateway_name TEXT, order_preference INT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $whmcs->exec('CREATE TABLE tblcreditcards (id INTEGER PRIMARY KEY, pay_method_id INT, card_type TEXT, last_four TEXT, expiry_date TEXT,
            card_data TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, status TEXT, paymentmethod TEXT)');
        $c = $this->client->id;
        $other = User::factory()->create()->client()->id;
        $whmcs->exec("INSERT INTO tblhosting VALUES ({$service->id}, {$c}, 'Active', 'banktransfer')");
        $whmcs->exec("INSERT INTO tblinvoices VALUES ({$invoice->id}, {$c}, 'Unpaid', 'banktransfer')");
        // Another customer's card in WHMCS: there are saved methods, the API is not configured.
        $whmcs->exec("INSERT INTO tblpaymethods (id, userid, payment_type, gateway_name, order_preference) VALUES (11, {$other}, 'RemoteCreditCard', 'stripe', 0)");
        $whmcs->exec("INSERT INTO tblcreditcards (pay_method_id, card_type, last_four, expiry_date, card_data) VALUES (11, 'Visa', '4242', '', 'x')");
        $map = fn (string $module) => match ($module) {
            'stripe' => $this->cardGateway->id, 'banktransfer' => $this->bank->id, default => null,
        };

        (new WhmcsPayMethodImport($whmcs, null, $map, $map))->run();
        // After the cut-over: the customer saves a card.
        $this->client->forceFill(['default_billing_agreement_id' => $this->card->id, 'auto_charge' => true])->save();

        $this->assertNull(AutoCharge::agreementFor($invoice->fresh()->load('client'))?->id, 'a WHMCS bank-transfer invoice left to the card (import without the API)');
    }

    /**
     * R3-541-2 (medium). Invariant 1 through staff's API. A line staff add to an open invoice for a service set to PayPal
     * (POST /api/v1/admin/invoice-items, reference_type Service, no kind: a renewal to AutoCharge::due) gets no way:
     * payment_gateway_id is fillable but not in CreateInvoiceItemRequest's rules, and nothing reads it from the service.
     * paidAnotherWay finds no line of another gateway and the account's card charges the PayPal service. The same for a
     * line re-pointed at such a service (PUT, reference_id). Expected: a service's or domain's line, however made, records
     * its item's way (e.g. InvoiceItemObserver on create and on a reference change: ItemPaymentMethod::live), so the card
     * never charges it.
     */
    public function test_r3_541_2_a_line_staff_add_by_the_api_for_a_paypal_service_is_charged_by_the_card(): void
    {
        $byPayPal = $this->service($this->paypal);
        $this->client->forceFill(['default_billing_agreement_id' => $this->card->id, 'auto_charge' => true])->save();
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-10-25']);
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 'test', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token),
            'permissions' => ['admin.invoice_items.create', 'admin.invoice_items.update']]);

        $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'), ['invoice_id' => $invoice->id, 'description' => 'Renewal',
            'price' => 10, 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $byPayPal->id])->assertSuccessful();

        $this->assertNull(AutoCharge::agreementFor($invoice->fresh()->load('client'))?->id, 'the card is charged for a service set to PayPal');
    }
}
