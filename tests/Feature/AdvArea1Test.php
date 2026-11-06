<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\BillingAgreement;
use App\Models\Category;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\AdvA4Pay\AdvA4Pay;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Adversarial area review 1 (2026-11-07): the WHMCS importer's money. Synthetic data only. Each test fails on adv/areas
 * for the finding it names (ADV-A1-n).
 */
class AdvArea1Test extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private int $clientId;

    private Product $product;

    private BufferedOutput $output;

    private int $item = 5000;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        $this->clientId = User::factory()->create()->client()->id;
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT, credit REAL DEFAULT 0)');
        $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL, subtotal REAL DEFAULT 0, tax REAL DEFAULT 0, tax2 REAL DEFAULT 0, notes TEXT DEFAULT \'\')');
        $this->whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT, duedate TEXT DEFAULT \'0000-00-00\')');
        $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT, refundid INT DEFAULT 0, description TEXT DEFAULT \'\')');
        $this->whmcs->exec('CREATE TABLE tblcredit (id INTEGER PRIMARY KEY, clientid INT, date TEXT, description TEXT, amount REAL, relid INT DEFAULT 0)');
        $this->whmcs->exec('CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, hostingid INT, addonid INT, name TEXT)');
        // WHMCS's upgrades as it keeps them: «newvalue» is «product id,cycle» for a package, the new recurring amount
        // apart; status Pending and paid N until its invoice is paid (then WHMCS applies it).
        $this->whmcs->exec('CREATE TABLE tblupgrades (id INTEGER PRIMARY KEY, userid INT, orderid INT, type TEXT, date TEXT, relid INT,
            originalvalue TEXT, newvalue TEXT, amount REAL, recurringchange REAL, status TEXT, paid TEXT)');
        $this->whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR')");
        $this->whmcs->exec("INSERT INTO tblclients (id, currency) VALUES ({$this->clientId}, 1)");

        $this->product = Product::factory()->create(['name' => 'Business 10',
            'category_id' => Category::create(['name' => 'Shared Hosting', 'slug' => 'shared-hosting'])->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(Product $product, float $price): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'EUR']);

        return $plan;
    }

    /** A monthly service at 10.00 with this (WHMCS) id, paid until 2026-10-25, as importServices left it. */
    private function service(int $id): Service
    {
        return Service::factory()->create(['id' => $id, 'client_id' => $this->clientId, 'product_id' => $this->product->id,
            'plan_id' => $this->plan($this->product, 10)->id, 'status' => Service::STATUS_ACTIVE, 'currency_code' => 'EUR', 'price' => 10,
            'quantity' => 1, 'expires_at' => '2026-10-25']);
    }

    private function invoice(int $id, string $status, float $amount, string $due = '2026-10-25'): void
    {
        $this->whmcs->exec("INSERT INTO tblinvoices (id, userid, invoicenum, date, duedate, datepaid, status, total, credit, taxrate, subtotal)
            VALUES ({$id}, {$this->clientId}, '', '2026-10-15', '{$due}', '0000-00-00 00:00:00', '{$status}', {$amount}, 0, 0, {$amount})");
    }

    private function item(int $invoice, string $type, int $relid, float $amount): int
    {
        $id = ++$this->item;
        $this->whmcs->exec("INSERT INTO tblinvoiceitems (id, invoiceid, userid, type, relid, description, amount, taxed)
            VALUES ({$id}, {$invoice}, {$this->clientId}, '{$type}', {$relid}, 'Line', {$amount}, 0)");

        return $id;
    }

    /** @param  list<string>  $steps */
    private function import(array $steps = ['importInvoices', 'importInvoiceItems', 'importPayments', 'importMassPay', 'journalMoney']): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $this->output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));
        foreach ($steps as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
    }

    /**
     * ADV-A1-1. An upgrade ordered in WHMCS and not paid yet (tblupgrades Pending, paid N; its invoice Unpaid). WHMCS applies
     * it when its invoice is paid: the new product, its recurring amount. The importer brings the invoice and its line as a
     * charge of the service (`kind = charge`, no ServiceUpgrade), and tells no one: the customer pays it here (reminders
     * and the pay page ask for it) and keeps the old product at the old price. The upgrade they paid for never happens.
     */
    public function test_paying_an_imported_unpaid_whmcs_upgrade_upgrades_the_service(): void
    {
        $service = $this->service(101);
        $bigger = Product::factory()->create(['name' => 'Business 20', 'category_id' => $this->product->category_id]);
        $this->plan($bigger, 20);
        $this->whmcs->exec("INSERT INTO tblupgrades VALUES (41, {$this->clientId}, 9, 'package', '2026-10-15', 101, '{$this->product->id},monthly',
            '{$bigger->id},monthly', 4.00, 10.00, 'Pending', 'N')");
        $this->invoice(700, 'Unpaid', 4);
        $this->item(700, 'Upgrade', 41, 4);
        $this->import();

        $invoice = Invoice::query()->findOrFail(700);
        (new RecordPaymentService)->recordByStaff($invoice, null, '4.00', 'bank-1');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'the customer paid the upgrade');
        $this->assertSame($bigger->id, (int) $service->fresh()->product_id, 'paid in WHMCS, it would be on the product it paid the upgrade to');
    }
    /**
     * ADV-A1-2. A Mass Pay invoice the customer started and did not finish: WHMCS applied their credit to it (auto-apply
     * credit, tblinvoices.credit 5.00, «Credit Applied to Invoice #900» −5.00 in tblcredit) and no money came. PARENTS_SQL
     * takes it for a parent (no money on it), so it is dropped; importMassPay places only a Paid parent's money, and
     * creditApplied() skips parents. The 5.00 left the customer's balance in WHMCS and lands nowhere here: their children
     * are owed in full (25.00) and the journal books the missing 5.00 as credit «lost without a trace» (opening_balance).
     * In WHMCS paying the parent's 20.00 settles both children; here the customer pays 25.00.
     */
    public function test_credit_applied_to_an_unfinished_mass_pay_is_not_lost(): void
    {
        foreach ([[801, 10], [802, 15]] as [$id, $amount]) {
            $this->invoice($id, 'Unpaid', $amount);
            $this->item($id, 'Hosting', 0, $amount);
        }
        // WHMCS keeps the parent's total net of the credit applied to it.
        $this->whmcs->exec("INSERT INTO tblinvoices (id, userid, invoicenum, date, duedate, datepaid, status, total, credit, taxrate, subtotal)
            VALUES (900, {$this->clientId}, '', '2026-10-16', '2026-10-16', '0000-00-00 00:00:00', 'Unpaid', 20, 5, 0, 25)");
        $this->item(900, 'Invoice', 801, 10);
        $this->item(900, 'Invoice', 802, 15);
        $this->whmcs->exec("INSERT INTO tblcredit (clientid, date, description, amount, relid) VALUES
            ({$this->clientId}, '2026-09-01', 'Goodwill credit', 5, 0),
            ({$this->clientId}, '2026-10-16', 'Credit Applied to Invoice #900', -5, 900)");
        // WHMCS's balance now: nothing (the 5.00 is on invoice #900).
        DB::table('credits')->insert(['client_id' => $this->clientId, 'currency_code' => 'EUR', 'amount' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->import();

        $owed = (float) Invoice::query()->findOrFail(801)->remaining + (float) Invoice::query()->findOrFail(802)->remaining;
        $credit = (float) DB::table('credits')->where('client_id', $this->clientId)->sum('amount');
        $this->assertSame('20.00', number_format($owed - $credit, 2, '.', ''),
            'WHMCS: 25.00 billed, 5.00 of the customer\'s credit already on it; here they owe 25.00 and the 5.00 is gone');
    }
    /**
     * ADV-A1-3. WHMCS's «Payment Pending»: a SEPA debit (Stripe SEPA, myip) started and not cleared yet; WHMCS records the
     * payment when it clears, and meanwhile neither charges it again nor reminds the customer of it. docs/importer.md
     * («Status τιμολογίων») says it comes as `unpaid` *with a `processing` transaction*; invoiceStatus() makes it `unpaid`
     * and nothing else. Here it is an ordinary open invoice: no payment on its way (SavedMethodCharge::onItsWay), so the
     * cron charges the saved method again (the same mandate: a second debit), sends overdue reminders, and suspends its
     * service, for an invoice the customer has paid.
     */
    public function test_a_whmcs_payment_pending_invoice_has_its_payment_on_its_way(): void
    {
        $this->service(101);
        $this->invoice(700, 'Payment Pending', 10);
        $this->item(700, 'Hosting', 101, 10);

        $this->import();

        $invoice = Invoice::query()->findOrFail(700);
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice), 'its SEPA debit is on its way: never charged, reminded or suspended meanwhile');
    }

    /**
     * ADV-A4-1 (High; from adv/areas AdvArea4Test, same root cause as ADV-A1-3). WHMCS's «Payment Pending» (WHMCS 8: a
     * Stripe SEPA/ACH debit, or a pending PayPal capture, already started and taking days) is imported as a plain `unpaid`
     * invoice (ImportFromWhmcs::invoiceStatus), with nothing that says money is on its way: no processing transaction
     * (importer.md's own table says «Payment Pending is a processing transaction») and no hold. The customer's imported
     * saved method (myip: 4 Stripe SEPA mandates) is the default, so the first cron after the cut-over charges the same
     * invoice again: the customer is debited twice, and when WHMCS's own debit settles its webhook is «foreign» (no mark)
     * and only told to staff. Adapted to this file's fixture (its own clock and settings, as AdvArea4Test's setUp).
     */
    public function test_adv_a4_1_a_whmcs_payment_pending_invoice_is_not_charged_again_by_the_first_cron(): void
    {
        Cache::flush();
        MailFacade::fake();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-11-10 09:00:00');
        config(['settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0,
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true]);
        if (!class_exists(AdvA4Pay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\AdvA4Pay; class AdvA4Pay {
                public static array $charges = [];
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function supportsPaymentGroups() { return false; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) { self::$charges[] = [$invoice->id, (float) $amount]; return true; }
            }');
        }
        AdvA4Pay::$charges = [];
        $gateway = Gateway::query()->create(['extension' => 'AdvA4Pay', 'name' => 'SEPA', 'type' => 'gateway', 'enabled' => true]);
        // As WhmcsPayMethodImport leaves it: the SEPA mandate saved, the account's default, «Auto-charge» on.
        BillingAgreement::query()->create(['client_id' => $this->clientId, 'gateway_id' => $gateway->id, 'name' => 'SEPA Direct Debit **** 1234',
            'external_reference' => 'pm_sepa_adv', 'type' => 'sepa_debit']);
        DB::table('clients')->where('id', $this->clientId)->update(['auto_charge' => true]);
        $this->service(4101)->update(['expires_at' => '2026-11-08']);
        // WHMCS started the debit on the due date (2026-11-08): «Payment Pending», no payment row yet (SEPA settles in days).
        $this->whmcs->exec("INSERT INTO tblinvoices (id, userid, invoicenum, date, duedate, datepaid, status, total, credit, taxrate, subtotal)
            VALUES (9101, {$this->clientId}, '', '2026-11-01', '2026-11-08', '0000-00-00', 'Payment Pending', 10, 0, 0, 10)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems (id, invoiceid, userid, type, relid, description, amount, taxed)
            VALUES (1, 9101, {$this->clientId}, 'Hosting', 4101, 'Hosting renewal', 10, 0)");

        $this->import(['importInvoices', 'importInvoiceItems', 'importPayments']);
        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->findOrFail(9101)->status, 'precondition: imported open');

        (new AutoCharge)->run(); // the first cron after the cut-over

        $this->assertSame([], AdvA4Pay::$charges, 'the invoice WHMCS is already collecting (Payment Pending) is debited a second time');
    }
}
