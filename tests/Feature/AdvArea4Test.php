<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Adversarial area review 4 of 6: payments and gateways at the cut-over (WHMCS-era objects meeting MyCS's webhooks,
 * the cron and the importer). Fixtures only, no real data, no network (Http::fake / preventStrayRequests).
 */
class AdvArea4Test extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_adv_area_4';

    private PDO $whmcs;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        MailFacade::fake();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-11-10 09:00:00');
        config(['settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0,
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true]);
        $this->clientId = User::factory()->create()->client()->id;

        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL, paymentmethod TEXT)');
        $this->whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, description TEXT,
            amountin REAL, amountout REAL, fees REAL, transid TEXT, refundid INT)');
        $this->whmcs->exec('CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, hostingid INT, addonid INT, name TEXT)');
        $this->whmcs->exec('CREATE TABLE tblupgrades (id INTEGER PRIMARY KEY, relid INT, type TEXT)');
        $this->whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR')");
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$this->clientId}, 1)");
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The importer's own steps, on the fixture (as ImportInvoiceLineTypesTest runs them). */
    private function import(array $steps): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        foreach ($steps as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
    }

    /** An active monthly service at 10.00 with this (WHMCS) id. */
    private function service(int $id, string $expires): Service
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'EUR']);

        return Service::factory()->create(['id' => $id, 'client_id' => $this->clientId, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'EUR', 'status' => Service::STATUS_ACTIVE, 'subscription_id' => null, 'expires_at' => $expires]);
    }

    private function stripeGateway(): Gateway
    {
        $gateway = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $gateway->settings()->create(['key' => 'stripe_secret_key', 'value' => 'sk_test_adv']);
        $gateway->settings()->create(['key' => 'stripe_webhook_secret', 'value' => self::SECRET]);

        return $gateway;
    }

    /** @param  array<string, mixed>  $object */
    private function stripeEvent(string $type, array $object): TestResponse
    {
        $time = time();
        $payload = (string) json_encode(['id' => 'evt_' . uniqid(), 'type' => $type, 'data' => ['object' => $object]]);

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $payload, self::SECRET)], $payload);
    }

    /**
     * ADV-A4-2 (High). A refund WHMCS made (imported: on the payment's refunded_amount, the payment without a gateway, the
     * refund's own id not kept anywhere) is recorded a SECOND time when Stripe sends an event of it after the cut-over:
     * `refund.updated` comes days after a card refund (the acquirer reference added, a pending refund settling). The
     * importer keeps no GatewayRefund for it, its journal events are source `import` (so the «refunded by hand» match does
     * not see them), and gatewayPayment matches the gateway-less payment by its transid: another 40.00 is booked as
     * refunded and the invoice is reduced again, for money the customer got once.
     */
    public function test_adv_a4_2_a_late_stripe_event_of_a_refund_whmcs_made_is_not_recorded_again(): void
    {
        $this->stripeGateway();
        $this->service(4201, '2026-12-01');
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (9201, {$this->clientId}, '', '2026-10-25', '2026-11-01', '2026-11-01 10:00:00', 'Paid', 100, 0, 0, 'stripe')");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES (2, 9201, {$this->clientId}, 'Hosting', 4201, 'Hosting', 100, 0)");
        // Paid by card through WHMCS, then 40.00 refunded from WHMCS (Stripe refund re_whmcs_1) two days before the cut-over.
        $this->whmcs->exec("INSERT INTO tblaccounts VALUES (7001, {$this->clientId}, 9201, 'stripe', '2026-11-01 10:00:00', 'Invoice Payment', 100, 0, 3.2, 'pi_whmcs_1', 0)");
        $this->whmcs->exec("INSERT INTO tblaccounts VALUES (7002, {$this->clientId}, 9201, 'stripe', '2026-11-08 12:00:00', 'Refund of Transaction ID pi_whmcs_1', 0, 40, 0, 're_whmcs_1', 7001)");

        $this->import(['importInvoices', 'importInvoiceItems', 'importPayments', 'importRefunds']);
        $payment = InvoiceTransaction::query()->findOrFail(7001);
        $this->assertSame('40.00', number_format((float) $payment->refunded_amount, 2, '.', ''), 'precondition: WHMCS\'s refund imported');

        // After the cut-over, Stripe adds the acquirer reference to that refund: refund.updated, to MyCS's new endpoint.
        $this->stripeEvent('refund.updated', ['id' => 're_whmcs_1', 'object' => 'refund', 'payment_intent' => 'pi_whmcs_1', 'amount' => 4000,
            'currency' => 'eur', 'status' => 'succeeded', 'destination_details' => ['card' => ['reference' => '12345678901234567890123']]])->assertOk();

        $this->assertSame('40.00', number_format((float) $payment->fresh()->refunded_amount, 2, '.', ''),
            'WHMCS\'s refund of 40.00 is booked a second time: 80.00 refunded for 40.00 given back');
    }

    /**
     * ADV-A4-3 (Medium). Stripe's `charge.updated` looks its payment up by `$charge->payment_intent` without checking it
     * is set. A charge with none (made with the Charges API: WHMCS's older Stripe module, any other system on the account)
     * is updated after the cut-over (a description or metadata edited in the dashboard, a dispute): `where('transaction_id',
     * null)` is `IS NULL`, so it finds a payment staff entered without a transaction id (a bank transfer) and writes the
     * foreign charge's Stripe fee on it (addPaymentFee does the same lookup): that payment's net is wrong in every report.
     */
    public function test_adv_a4_3_charge_updated_without_a_payment_intent_never_touches_a_payment_without_transaction_id(): void
    {
        $this->stripeGateway();
        Http::fake(['https://api.stripe.com/v1/balance_transactions/*' => Http::response(['id' => 'txn_legacy', 'fee' => 290])]);
        $invoice = Invoice::factory()->create(['client_id' => $this->clientId, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-11-10']);
        $invoice->items()->create(['description' => 'Line', 'price' => '50.00', 'quantity' => 1]);
        // A bank transfer staff recorded: no gateway, no transaction id.
        $bank = $invoice->transactions()->create(['amount' => 50, 'status' => InvoiceTransactionStatus::Succeeded]);
        $this->assertNull($bank->fresh()->transaction_id, 'precondition');
        $this->assertNull($bank->fresh()->fee, 'precondition');

        $this->stripeEvent('charge.updated', ['id' => 'ch_legacy_whmcs', 'object' => 'charge', 'payment_intent' => null,
            'balance_transaction' => 'txn_legacy', 'amount' => 2500, 'currency' => 'eur'])->assertOk();

        $this->assertNull($bank->fresh()->fee, 'a foreign charge\'s Stripe fee is written on a bank transfer');
    }

    /**
     * ADV-A4-4 (Medium). WHMCS's old IPN address keeps receiving IPNs after the cut-over for every PayPal transaction of
     * the account when the account's own IPN setting (Profile → Instant Payment Notifications) points at it, as WHMCS's
     * basic PayPal module has merchants set: MyCS's own vault charges and checkouts included. The controller never looks
     * whether the transaction is recorded here, so for a capture MyCS recorded itself it mails staff «PayPal payment not
     * recorded: the money WAS taken and is NOT recorded here, record it on the customer's invoice by hand»: following it
     * pays the invoice twice (the second as credit), and a MyCS refund's IPN asks them to «act on the invoice» again.
     */
    public function test_adv_a4_4_the_legacy_ipn_never_tells_staff_to_record_a_payment_mycs_recorded(): void
    {
        config(['settings.paypal_legacy_receivers' => 'billing@example.test']);
        Http::fake(['https://ipnpb.paypal.com/*' => Http::response('VERIFIED')]);
        DB::table('extensions')->insert(['name' => 'PayPal', 'extension' => 'PayPal', 'type' => 'gateway', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $invoice = Invoice::factory()->create(['client_id' => $this->clientId, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-11-10']);
        $invoice->items()->create(['description' => 'Line', 'price' => '12.40', 'quantity' => 1]);
        // MyCS's vault charge (or checkout), recorded from PayPal's answer and its webhook: capture 5KX12345AB678901C.
        ExtensionHelper::addPayment($invoice->id, 'PayPal', '12.40', null, '5KX12345AB678901C', currency: 'EUR');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'precondition: recorded here');

        // The account-level IPN of the same transaction, to WHMCS's old address.
        $this->call('POST', '/modules/gateways/callback/paypal.php', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query([
            'txn_type' => 'express_checkout', 'payment_status' => 'Completed', 'txn_id' => '5KX12345AB678901C', 'mc_gross' => '12.40',
            'mc_currency' => 'EUR', 'invoice' => 'mycs-' . $invoice->id, 'receiver_email' => 'billing@example.test']))->assertOk();

        $this->assertSame(0, EmailLog::query()->where('subject', 'PayPal payment not recorded')->count(),
            'staff are told to record by hand a payment MyCS recorded itself');
    }
}
