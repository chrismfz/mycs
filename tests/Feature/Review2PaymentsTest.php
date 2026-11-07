<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\User;
use App\Services\Money\CreditAutoApply;
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
 * Review 2 of the payments PRs #692 and #694 (P1-P9): a late Stripe event of a refund WHMCS made, credit on an overdue
 * subscription renewal, the legacy PayPal IPN's lookups, Stripe's fee. Fixtures only, no real data, no network.
 */
class Review2PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_review2_payments';

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
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true, 'settings.cronjob_suspend_requires_invoice' => false,
            'settings.paypal_legacy_receivers' => 'billing@example.test']);
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

    /** The importer's own steps, on the fixture. */
    private function import(array $steps): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        foreach ($steps as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
    }

    /** An active monthly service at 10.00 (EUR). */
    private function service(string $expires, array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['client_id' => $this->clientId, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'EUR', 'status' => Service::STATUS_ACTIVE, 'subscription_id' => null,
            'expires_at' => $expires, ...$attributes]);
    }

    private function openInvoiceFor(Service $service, string $due): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR',
            'due_at' => $due, 'created_at' => Carbon::parse($due)->subDays(7)]);
        $start = Carbon::parse($service->expires_at);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => $service->price, 'period_start' => $start, 'period_end' => $start->copy()->addMonth()]);

        return $invoice;
    }

    private function stripeGateway(): Gateway
    {
        $gateway = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $gateway->settings()->create(['key' => 'stripe_secret_key', 'value' => 'sk_test_review2']);
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

    /** @param  array<string, string>  $fields */
    private function legacyIpn(array $fields): TestResponse
    {
        Http::fake(['https://ipnpb.paypal.com/*' => Http::response('VERIFIED')]);

        return $this->call('POST', '/modules/gateways/callback/paypal.php', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            http_build_query($fields + ['receiver_email' => 'billing@example.test', 'mc_currency' => 'EUR']));
    }

    private function mails(string $subject): int
    {
        return EmailLog::query()->where('subject', $subject)->count();
    }

    private function enableCredit(): void
    {
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
    }

    /**
     * P1. WHMCS keeps a Stripe refund it made by the refund's BALANCE TRANSACTION (tblaccounts.transid = txn_..., as on
     * the nixpal copy), not by the refund id (re_...) that Stripe's late `refund.updated` carries. The event's refund
     * object names that balance transaction: matched to the imported row, a replay, never booked a second time. Another
     * refund of the same payment (its own re_ and txn_) is still recorded.
     */
    public function test_p1_a_late_stripe_refund_event_matches_whmcs_refund_by_its_balance_transaction(): void
    {
        $this->stripeGateway();
        $service = $this->service('2026-12-01');
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (9301, {$this->clientId}, '', '2026-10-25', '2026-11-01', '2026-11-01 10:00:00', 'Paid', 100, 0, 0, 'stripe')");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES (3, 9301, {$this->clientId}, 'Hosting', {$service->id}, 'Hosting', 100, 0)");
        $this->whmcs->exec("INSERT INTO tblaccounts VALUES (7301, {$this->clientId}, 9301, 'stripe', '2026-11-01 10:00:00', 'Invoice Payment', 100, 0, 3.2, 'pi_3QfixtureAAAA', 0)");
        $this->whmcs->exec("INSERT INTO tblaccounts VALUES (7302, {$this->clientId}, 9301, 'stripe', '2026-11-08 12:00:00', 'Refund of Transaction ID pi_3QfixtureAAAA', 0, 40, 0, 'txn_3QfixtureBBBB', 7301)");

        $this->import(['importInvoices', 'importInvoiceItems', 'importPayments', 'importRefunds']);
        $payment = InvoiceTransaction::query()->findOrFail(7301);
        $this->assertSame('40.00', number_format((float) $payment->refunded_amount, 2, '.', ''), 'precondition: WHMCS\'s refund imported');
        $this->assertSame('pi_3QfixtureAAAA', GatewayRefund::query()->where('reference', 'txn_3QfixtureBBBB')->value('payment_reference'), 'its payment\'s id kept');

        $this->stripeEvent('refund.updated', ['id' => 're_3QfixtureCCCC', 'object' => 'refund', 'payment_intent' => 'pi_3QfixtureAAAA', 'amount' => 4000,
            'currency' => 'eur', 'status' => 'succeeded', 'balance_transaction' => 'txn_3QfixtureBBBB',
            'destination_details' => ['card' => ['reference' => '12345678901234567890123']]])->assertOk();
        $this->assertSame('40.00', number_format((float) $payment->fresh()->refunded_amount, 2, '.', ''), 'WHMCS\'s refund booked a second time');

        // A new refund of the same payment, made in Stripe's dashboard after the cut-over: recorded.
        $this->stripeEvent('refund.created', ['id' => 're_3QfixtureDDDD', 'object' => 'refund', 'payment_intent' => 'pi_3QfixtureAAAA', 'amount' => 1000,
            'currency' => 'eur', 'status' => 'succeeded', 'balance_transaction' => 'txn_3QfixtureEEEE'])->assertOk();
        $this->assertSame('50.00', number_format((float) $payment->fresh()->refunded_amount, 2, '.', ''), 'another refund is recorded');
    }

    /**
     * P1 (legacy IPN, review of #694 F2). A WHMCS-era PayPal refund, imported with its row (reference = WHMCS's transid,
     * PayPal's refund transaction): its IPN to WHMCS's old address is recorded here, staff are not told to act on it.
     */
    public function test_p1_the_legacy_ipn_of_an_imported_whmcs_paypal_refund_is_recorded_here(): void
    {
        $service = $this->service('2026-12-01');
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (9302, {$this->clientId}, '', '2026-10-25', '2026-11-01', '2026-11-01 10:00:00', 'Paid', 30, 0, 0, 'paypal')");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES (4, 9302, {$this->clientId}, 'Hosting', {$service->id}, 'Hosting', 30, 0)");
        $this->whmcs->exec("INSERT INTO tblaccounts VALUES (7311, {$this->clientId}, 9302, 'paypal', '2026-11-01 10:00:00', 'Invoice Payment', 30, 0, 1.2, '8AB12345CD678901E', 0)");
        $this->whmcs->exec("INSERT INTO tblaccounts VALUES (7312, {$this->clientId}, 9302, 'paypal', '2026-11-08 12:00:00', 'Refund of Transaction ID 8AB12345CD678901E', 0, 30, 0, '9FG12345HJ678901K', 7311)");
        $this->import(['importInvoices', 'importInvoiceItems', 'importPayments', 'importRefunds']);
        $this->assertSame(GatewayRefund::ORIGIN_IMPORT, GatewayRefund::query()->where('gateway', 'PayPal')->where('reference', '9FG12345HJ678901K')->value('origin'), 'precondition');

        $this->legacyIpn(['txn_type' => 'web_accept', 'payment_status' => 'Refunded', 'txn_id' => '9FG12345HJ678901K',
            'parent_txn_id' => '8AB12345CD678901E', 'mc_gross' => '-30.00'])->assertOk();

        $this->assertSame(0, $this->mails('PayPal refund or reversal not recorded'), 'staff are told to act on a refund WHMCS made and the import kept');
    }

    /**
     * P2 (review 2). An overdue subscription renewal is paid from credit once it is overdue by Suspend Days (from its due
     * date, the days the cron counts), not before: the days before, a late subscription charge (Stripe's retries, a
     * PayPal sale) may still pay it, and credit first would make that charge credit, the customer paying twice.
     */
    public function test_p2_credit_pays_an_overdue_subscription_renewal_once_overdue_by_suspend_days(): void
    {
        $this->enableCredit();
        // Suspend Days 8, due on 2026-11-03: overdue by 7 days on 2026-11-10, by 8 on 2026-11-11.
        $service = $this->service('2026-11-03 00:00:00', ['subscription_id' => 'sub_review2']);
        $invoice = $this->openInvoiceFor($service, '2026-11-03');
        Credit::query()->create(['client_id' => $this->clientId, 'currency_code' => 'EUR', 'amount' => '50.00']);

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'paid from credit while the subscription may still pay it');
        $this->assertSame('50.00', number_format((float) Credit::query()->where('client_id', $this->clientId)->value('amount'), 2, '.', ''));
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);

        Carbon::setTestNow('2026-11-11 09:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'the credit covering it pays it once overdue by Suspend Days');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'not suspended while credit covering it sits unused');
        $this->assertSame('40.00', number_format((float) Credit::query()->where('client_id', $this->clientId)->value('amount'), 2, '.', ''));
    }

    /** P2 (review 2). A subscription service already suspended for non-payment is paid from credit that comes later. */
    public function test_p2_a_suspended_subscription_service_is_paid_from_credit_that_comes_later(): void
    {
        $this->enableCredit();
        $service = $this->service('2026-10-25 00:00:00', ['subscription_id' => 'sub_review2_suspended']);
        $invoice = $this->openInvoiceFor($service, '2026-10-25');
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'precondition: suspended for non-payment');

        // The credit comes days later (a staff adjustment, a downgrade).
        Carbon::setTestNow('2026-11-12 09:00:00');
        Credit::query()->create(['client_id' => $this->clientId, 'currency_code' => 'EUR', 'amount' => '50.00']);
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'the credit sits unused while the service goes on to termination');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'paid, brought back');
    }

    /** P2 (review 2). Suspension switched off (the cut-over's safety net): the date rule alone, still paid. */
    public function test_p2_suspension_switched_off_still_pays_from_credit(): void
    {
        $this->enableCredit();
        config(['settings.cronjob_suspend_enabled' => false]);
        $service = $this->service('2026-11-01 00:00:00', ['subscription_id' => 'sub_review2_off']);
        $invoice = $this->openInvoiceFor($service, '2026-11-01');
        Credit::query()->create(['client_id' => $this->clientId, 'currency_code' => 'EUR', 'amount' => '50.00']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'never paid from credit while suspensions are off');
    }

    /**
     * P3. The overdue path reads the customer's opt-out again under the lock (eligible(subscriptionRenewal: true) skips
     * it): a customer who opted out meanwhile keeps their credit.
     */
    public function test_p3_an_opted_out_customer_is_not_paid_from_credit_on_the_overdue_path(): void
    {
        $this->enableCredit();
        Carbon::setTestNow('2026-11-11 09:00:00');
        $service = $this->service('2026-11-02 00:00:00', ['subscription_id' => 'sub_review2_optout']);
        $invoice = $this->openInvoiceFor($service, '2026-11-02');
        Credit::query()->create(['client_id' => $this->clientId, 'currency_code' => 'EUR', 'amount' => '50.00']);
        // Opted out after forCustomer's unlocked read: the private step itself, as it then runs.
        DB::table('clients')->where('id', $this->clientId)->update(['credit_auto_apply' => false]);

        $paid = (new ReflectionMethod(CreditAutoApply::class, 'toOverdueSubscriptionRenewal'))->invoke(new CreditAutoApply, $invoice->id, 5000);

        $this->assertNull($paid, 'credit paid the invoice of a customer who opted out');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    /**
     * P4. Refused invoices cost forCustomer no extra queries each for the overdue path: the subscription renewals it may
     * pay are read once per customer.
     */
    public function test_p4_refused_invoices_do_not_cost_queries_each_on_the_overdue_path(): void
    {
        $this->enableCredit();
        $overhead = function (int $count): int {
            $client = User::factory()->create()->client()->id;
            Credit::query()->create(['client_id' => $client, 'currency_code' => 'EUR', 'amount' => '500.00']);
            $ids = [];
            for ($i = 0; $i < $count; $i++) {
                // Subscription renewals overdue but not yet suspendable: refused by toInvoice and by the overdue path.
                $service = $this->service('2026-11-05 00:00:00', ['client_id' => $client, 'subscription_id' => 'sub_p4_' . $client . '_' . $i]);
                $ids[] = $this->openInvoiceFor($service, '2026-11-05')->id;
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->assertSame(0, (new CreditAutoApply)->forCustomer($client, 'EUR'));
            $all = count(DB::getQueryLog());
            DB::flushQueryLog();
            foreach ($ids as $id) {
                (new CreditAutoApply)->toInvoice($id);
            }
            $own = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $all - $own;
        };

        $one = $overhead(1);
        $five = $overhead(5);
        // One balance read per invoice; nothing more per invoice refused (was three more each: invoice, lines, services).
        $this->assertLessThanOrEqual(4, $five - $one, "forCustomer costs {$one} queries over toInvoice for one invoice, {$five} for five");
    }

    /**
     * P5 (review of #694 F3). The legacy IPN's «payment recorded here» is a PayPal payment of ours (or one staff recorded
     * by hand), succeeded, or processing on a PayPal gateway: another gateway's row with the same id is not.
     */
    public function test_p5_the_legacy_ipn_money_lookup_is_scoped_to_paypal(): void
    {
        $this->stripeGateway();
        $invoice = Invoice::factory()->create(['client_id' => $this->clientId, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-11-10']);
        $invoice->items()->create(['description' => 'Line', 'price' => '20.00', 'quantity' => 1]);
        $stripe = Gateway::query()->where('extension', 'Stripe')->firstOrFail();
        $invoice->transactions()->create(['amount' => 20, 'status' => InvoiceTransactionStatus::Succeeded, 'gateway_id' => $stripe->id, 'transaction_id' => '1AB23456CD789012E']);

        $this->legacyIpn(['txn_type' => 'web_accept', 'payment_status' => 'Completed', 'txn_id' => '1AB23456CD789012E', 'mc_gross' => '20.00'])->assertOk();
        $this->assertSame(1, $this->mails('PayPal payment not recorded'), 'a Stripe row with the same id taken for the PayPal payment');

        // A PayPal payment of ours still processing (an eCheck): its own webhook completes it; staff are not told.
        $paypal = Gateway::query()->create(['extension' => 'PayPal', 'name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        $invoice->transactions()->create(['amount' => 20, 'status' => InvoiceTransactionStatus::Processing, 'gateway_id' => $paypal->id, 'transaction_id' => '2AB23456CD789012E']);
        $this->legacyIpn(['txn_type' => 'web_accept', 'payment_status' => 'Completed', 'txn_id' => '2AB23456CD789012E', 'mc_gross' => '20.00'])->assertOk();
        $this->assertSame(1, $this->mails('PayPal payment not recorded'), 'staff told to record a payment its own webhook completes');

        // One staff recorded by hand (no gateway) but not succeeded: not money here.
        $invoice->transactions()->create(['amount' => 20, 'status' => InvoiceTransactionStatus::Failed, 'transaction_id' => '3AB23456CD789012E']);
        $this->legacyIpn(['txn_type' => 'web_accept', 'payment_status' => 'Completed', 'txn_id' => '3AB23456CD789012E', 'mc_gross' => '20.00'])->assertOk();
        $this->assertSame(2, $this->mails('PayPal payment not recorded'));
    }

    /**
     * P6 (review of #694 F8). A refund row that recorded nothing (another currency, nothing left on the payment) is not
     * «already recorded»: staff are told. WHMCS's own refund, imported, is the money's record even at 0 (WHMCS booked it;
     * the import warned of what it could not).
     */
    public function test_p6_a_refund_row_that_recorded_nothing_is_not_recorded_here(): void
    {
        $paypal = Gateway::query()->create(['extension' => 'PayPal', 'name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        $invoice = Invoice::factory()->create(['client_id' => $this->clientId, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-11-10']);
        $invoice->items()->create(['description' => 'Line', 'price' => '20.00', 'quantity' => 1]);
        $payment = $invoice->transactions()->create(['amount' => 20, 'status' => InvoiceTransactionStatus::Succeeded, 'gateway_id' => $paypal->id, 'transaction_id' => '4AB23456CD789012E']);
        $row = fn (string $reference, string $origin) => GatewayRefund::query()->create(['invoice_transaction_id' => $payment->id, 'gateway' => 'PayPal',
            'reference' => $reference, 'payment_reference' => '4AB23456CD789012E', 'amount' => 20, 'currency_code' => 'USD', 'recorded_amount' => 0, 'origin' => $origin]);
        $row('5AB23456CD789012E', GatewayRefund::ORIGIN_GATEWAY); // in USD: the webhook recorded nothing
        $row('6AB23456CD789012E', GatewayRefund::ORIGIN_IMPORT);

        // A Mass Pay parent's id, not this payment's: only the refund's own row can say it is recorded.
        $this->legacyIpn(['txn_type' => 'web_accept', 'payment_status' => 'Refunded', 'txn_id' => '5AB23456CD789012E', 'parent_txn_id' => 'NOPARENT1', 'mc_gross' => '-20.00'])->assertOk();
        $this->assertSame(1, $this->mails('PayPal refund or reversal not recorded'), 'a refund that recorded nothing taken as recorded');

        $this->legacyIpn(['txn_type' => 'web_accept', 'payment_status' => 'Refunded', 'txn_id' => '6AB23456CD789012E', 'parent_txn_id' => 'NOPARENT1', 'mc_gross' => '-20.00'])->assertOk();
        $this->assertSame(1, $this->mails('PayPal refund or reversal not recorded'), 'WHMCS\'s imported refund told to staff again');
    }

    /**
     * F4 (review 2). A `refund.failed` of a refund WHMCS made (imported by its balance transaction): staff are told, as of
     * one recorded here; it is not kept as a failed refund of its own.
     */
    public function test_f4_a_failed_event_of_an_imported_whmcs_stripe_refund_tells_staff(): void
    {
        $this->stripeGateway();
        $service = $this->service('2026-12-01');
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (9303, {$this->clientId}, '', '2026-10-25', '2026-11-01', '2026-11-01 10:00:00', 'Paid', 100, 0, 0, 'stripe')");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES (5, 9303, {$this->clientId}, 'Hosting', {$service->id}, 'Hosting', 100, 0)");
        $this->whmcs->exec("INSERT INTO tblaccounts VALUES (7321, {$this->clientId}, 9303, 'stripe', '2026-11-01 10:00:00', 'Invoice Payment', 100, 0, 3.2, 'pi_3QfailedAAAA', 0)");
        $this->whmcs->exec("INSERT INTO tblaccounts VALUES (7322, {$this->clientId}, 9303, 'stripe', '2026-11-08 12:00:00', 'Refund of Transaction ID pi_3QfailedAAAA', 0, 40, 0, 'txn_3QfailedBBBB', 7321)");
        $this->import(['importInvoices', 'importInvoiceItems', 'importPayments', 'importRefunds']);

        $this->stripeEvent('refund.failed', ['id' => 're_3QfailedCCCC', 'object' => 'refund', 'payment_intent' => 'pi_3QfailedAAAA', 'amount' => 4000,
            'currency' => 'eur', 'status' => 'failed', 'balance_transaction' => 'txn_3QfailedBBBB', 'failure_balance_transaction' => 'txn_3QfailedDDDD'])->assertOk();

        $this->assertSame(1, $this->mails('Gateway refund failed after it was recorded'), 'staff not told that WHMCS\'s refund failed');
        $this->assertFalse(GatewayRefund::query()->where('reference', 're_3QfailedCCCC')->exists(), 'kept as a failed refund of its own');
    }

    /** F6 (review 2). A PayPal payment on a gateway staff removed since is still recorded here for the legacy IPN. */
    public function test_f6_the_legacy_ipn_sees_payments_of_a_removed_paypal_gateway(): void
    {
        $paypal = Gateway::query()->create(['extension' => 'PayPal', 'name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        $invoice = Invoice::factory()->create(['client_id' => $this->clientId, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-11-10']);
        $invoice->items()->create(['description' => 'Line', 'price' => '20.00', 'quantity' => 1]);
        $invoice->transactions()->create(['amount' => 20, 'status' => InvoiceTransactionStatus::Succeeded, 'gateway_id' => $paypal->id, 'transaction_id' => '7AB23456CD789012E']);
        $paypal->delete();

        $this->legacyIpn(['txn_type' => 'web_accept', 'payment_status' => 'Completed', 'txn_id' => '7AB23456CD789012E', 'mc_gross' => '20.00'])->assertOk();

        $this->assertSame(0, $this->mails('PayPal payment not recorded'), 'staff told to record a payment recorded here again');
    }

    /** P7 (review of #694 F4). Stripe's charge.updated finds its payment once and sets its fee on it. */
    public function test_p7_charge_updated_looks_its_payment_up_once(): void
    {
        $stripe = $this->stripeGateway();
        Http::fake(['https://api.stripe.com/v1/balance_transactions/*' => Http::response(['id' => 'txn_fee_review2', 'fee' => 290])]);
        $invoice = Invoice::factory()->create(['client_id' => $this->clientId, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-11-10']);
        $invoice->items()->create(['description' => 'Line', 'price' => '50.00', 'quantity' => 1]);
        $payment = $invoice->transactions()->create(['amount' => 50, 'status' => InvoiceTransactionStatus::Succeeded, 'gateway_id' => $stripe->id, 'transaction_id' => 'pi_fee_review2']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->stripeEvent('charge.updated', ['id' => 'ch_fee_review2', 'object' => 'charge', 'payment_intent' => 'pi_fee_review2',
            'balance_transaction' => 'txn_fee_review2', 'amount' => 5000, 'currency' => 'eur'])->assertOk();
        // Either quoting: "col" on SQLite, `col` on MariaDB.
        $lookups = collect(DB::getQueryLog())->filter(fn (array $query) => preg_match('/from [`"]invoice_transactions[`"]/', $query['query'])
            && preg_match('/[`"]transaction_id[`"] = \?/', $query['query']))->count();
        DB::disableQueryLog();

        $this->assertSame('2.90', number_format((float) $payment->fresh()->fee, 2, '.', ''));
        $this->assertSame(1, $lookups, 'the payment looked up twice');
    }
}
