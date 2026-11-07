<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\BillingAgreement;
use App\Models\Category;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Import\WhmcsParity;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyTrail;
use App\Services\Ops\RollbackReport;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
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

        // Owner's decision after review 1 (2026-11-07, «never bill wrongly»): not applied on payment here (a pending upgrade
        // brought R1-1/2/4), but named for staff to settle in WHMCS before the final dump, and never charged automatically.
        $this->assertStringContainsString('invoice #700 service #101 WHMCS upgrade #41 (package)', $this->output->fetch(), 'told to staff');
        $this->assertTrue((bool) Invoice::query()->findOrFail(700)->no_auto_charge, 'MyCS never charges it by itself');
        $this->assertSame(10.0, (float) $service->fresh()->price, 'unchanged until staff settle it in WHMCS');
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

    /** The fix of ADV-A1-1 (fixer): every unpaid upgrade line stays a charge, is named, and its invoice is not auto-charged. */
    public function test_fix_a1_1_unpaid_upgrades_stay_charges_named_and_not_auto_charged(): void
    {
        $this->service(101);
        $this->service(102);
        $bigger = Product::factory()->create(['name' => 'Business 20', 'category_id' => $this->product->category_id]);
        $this->plan($bigger, 20);
        $this->whmcs->exec("INSERT INTO tblupgrades VALUES (41, {$this->clientId}, 9, 'package', '2026-10-15', 101, '{$this->product->id},monthly',
            '{$bigger->id},monthly', 4.00, 10.00, 'Pending', 'N'), (42, {$this->clientId}, 10, 'configoptions', '2026-10-15', 102, '7=>1', '7=>2', 3.00, 3.00, 'Pending', 'N'),
            (43, {$this->clientId}, 11, 'package', '2026-09-15', 102, '1,monthly', '2,monthly', 2.00, 2.00, 'Completed', 'Y')");
        $this->invoice(700, 'Unpaid', 7);
        $this->item(700, 'Upgrade', 41, 4);
        $this->item(700, 'Upgrade', 42, 3);
        $this->invoice(701, 'Paid', 2);
        $this->item(701, 'Upgrade', 43, 2);
        $this->import();

        $this->assertSame(0, ServiceUpgrade::query()->count());
        $this->assertSame([[Service::class, 101, InvoiceItem::KIND_CHARGE, '4.00'], [Service::class, 102, InvoiceItem::KIND_CHARGE, '3.00']],
            InvoiceItem::query()->where('invoice_id', 700)->orderBy('id')->get()->map(fn (InvoiceItem $line) => [$line->reference_type,
                (int) $line->reference_id, $line->kind, number_format((float) $line->price, 2, '.', '')])->all());
        $output = $this->output->fetch();
        $this->assertStringContainsString('2 unpaid WHMCS upgrade(s)', $output);
        $this->assertStringContainsString('invoice #700 service #102 WHMCS upgrade #42 (configoptions)', $output);
        $this->assertSame([true, false], [(bool) Invoice::query()->findOrFail(700)->no_auto_charge, (bool) Invoice::query()->findOrFail(701)->no_auto_charge]);
    }

    /** The fix of ADV-A1-2 (fixer): the credit goes back with its moves, no «opening balance» for it, and parity agrees. */
    public function test_fix_a1_2_the_credit_back_is_journaled_and_parity_agrees(): void
    {
        $this->test_credit_applied_to_an_unfinished_mass_pay_is_not_lost();

        $events = DB::table('money_events')->where('client_id', $this->clientId)->get();
        $this->assertFalse($events->contains('cause', MoneyTrail::CAUSE_OPENING_BALANCE), 'no credit lost without a trace');
        $this->assertSame(1, $events->where('cause', MoneyTrail::CAUSE_CANCELLATION)->where('kind', 'credit_in')->count());
        $this->assertStringContainsString('Mass Pay #900 was never paid in WHMCS: the 5.00 EUR of credit applied to it is back', $this->output->fetch());

        foreach (['CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, domainstatus TEXT)', 'CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, merged_ticket_id INTEGER DEFAULT 0)',
            'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY)'] as $sql) {
            $this->whmcs->exec($sql);
        }
        $rows = collect((new WhmcsParity($this->whmcs))->rows())->keyBy('metric');
        $this->assertSame([500, 500], [$rows['Credit balance']['whmcs'], $rows['Credit balance']['mycs']]);
        $this->assertSame([1, 1], [$rows['Customers with credit']['whmcs'], $rows['Customers with credit']['mycs']]);
    }

    /** The fix of ADV-A1-3 (fixer): named on import, and staff complete it with its transaction id when the debit clears. */
    public function test_fix_a1_3_staff_complete_the_imported_pending_payment(): void
    {
        $this->service(101);
        $this->invoice(700, 'Payment Pending', 10);
        $this->item(700, 'Hosting', 101, 10);
        $this->import();
        $this->assertStringContainsString('Payment Pending (#700)', $this->output->fetch());

        $invoice = Invoice::query()->findOrFail(700);
        (new RecordPaymentService)->recordByStaff($invoice, null, '10.00', 'whmcs-pending-700');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame([['10.00', 'succeeded']], InvoiceTransaction::query()->where('invoice_id', 700)->get()
            ->map(fn (InvoiceTransaction $t) => [number_format((float) $t->amount, 2, '.', ''), $t->status->value])->all());
    }

    /** R1-6 (fixer): the imported pending payment is dated by its invoice's day, before the cut-over. */
    public function test_fix_r1_6_the_pending_payment_is_dated_by_the_invoice_date(): void
    {
        $this->service(101);
        $this->invoice(700, 'Payment Pending', 10, due: '2026-10-25');
        $this->item(700, 'Hosting', 101, 10);
        $this->import();

        $this->assertSame('2026-10-15', InvoiceTransaction::query()->where('invoice_id', 700)->sole()->created_at->toDateString());
    }

    /** R1-5 (fixer): one still processing a week on is told to staff by the daily cron, with its invoice and client. */
    public function test_fix_r1_5_the_cron_tells_staff_of_an_imported_payment_still_processing_after_a_week(): void
    {
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $this->service(101);
        $this->invoice(700, 'Payment Pending', 10);
        $this->item(700, 'Hosting', 101, 10);
        $this->import();
        $told = fn () => EmailLog::query()->where('subject', 'like', '%needs staff attention%')
            ->where('body', 'like', "%Invoice #700 (client #{$this->clientId}): the payment WHMCS had on its way%")->count();

        // A week counts from the import (2026-10-20), not from its WHMCS invoice's day (2026-10-15: R1b-3).
        Carbon::setTestNow('2026-10-26 10:00:00'); // 11 days after its invoice's day, 6 after the import
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(0, $told());

        Carbon::setTestNow('2026-10-28 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(1, $told());
        $this->assertSame('processing', InvoiceTransaction::query()->where('invoice_id', 700)->sole()->status->value, 'nothing changes by itself');
    }

    /**
     * Review 2 of #697, I1 (owner's decision, «never bill wrongly»): a Mass Pay parent in «Payment Pending» (its debit on
     * its way) is not placed over its children by a rule of the importer's own (its first versions, R1-3 and R1b-1, lost or
     * doubled money: review 2's F1, F2, F4): the import refuses to run, naming each parent and its children, before
     * anything is emptied, on a dry run too.
     */
    public function test_review2_i1_the_import_refuses_a_payment_pending_mass_pay(): void
    {
        foreach ([[801, 10], [802, 15]] as [$id, $amount]) {
            $this->invoice($id, 'Unpaid', $amount);
            $this->item($id, 'Hosting', 0, $amount);
        }
        $this->whmcs->exec("INSERT INTO tblinvoices (id, userid, invoicenum, date, duedate, datepaid, status, total, credit, taxrate, subtotal)
            VALUES (900, {$this->clientId}, '', '2026-10-16', '2026-10-16', '0000-00-00 00:00:00', 'Payment Pending', 20, 5, 0, 25)");
        $this->item(900, 'Invoice', 801, 10);
        $this->item(900, 'Invoice', 802, 15);
        // A single Payment Pending invoice is no reason to refuse (it keeps its hold, ADV-A1-3).
        $this->invoice(700, 'Payment Pending', 10);
        $this->item(700, 'Hosting', 0, 10);
        $this->whmcs->exec('CREATE TABLE tblconfiguration (setting TEXT, value TEXT)');
        ImportFromWhmcs::$connect = fn () => $this->whmcs;
        putenv('WHMCS_SOURCE_PASSWORD=secret');
        try {
            $refused = 'WHMCS Mass Pay invoice(s) in «Payment Pending»: #900 (children #801, #802). Their debit is still on its way:'
                . ' wait for it to clear (or fail) in WHMCS and dump again. Nothing done.';
            $source = ['dbname' => 'whmcs_copy', '--source-user' => 'reader', '--source-host' => 'db.example.test'];
            $this->artisan('app:import-from-whmcs', $source + ['--dry-run' => true])->assertFailed()->expectsOutputToContain($refused);
            // Before the database is even asked for (a confirmed run would empty it first).
            $this->artisan('app:import-from-whmcs', $source)->assertFailed()->expectsOutputToContain($refused)
                ->doesntExpectOutputToContain('--confirm-database');
        } finally {
            ImportFromWhmcs::$connect = null;
            putenv('WHMCS_SOURCE_PASSWORD');
        }
        $this->assertSame(1, DB::table('clients')->where('id', $this->clientId)->count(), 'nothing emptied');

        // Paid since in WHMCS (the debit cleared): no longer refused.
        $this->whmcs->exec("UPDATE tblinvoices SET status = 'Paid' WHERE id = 900");
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $this->assertSame([], (new ReflectionMethod($command, 'pendingMassPay'))->invoke($command));
    }

    /**
     * Review 2 of #697, I3: WHMCS lowers a Mass Pay parent's `credit` when credit is taken off it («Credit Removed from
     * Invoice #N», +amount back in the balance; checked read-only on the 2026-11-06 copies). So an unfinished parent
     * gives back only what is still on it, and parity agrees with WHMCS's balance.
     */
    public function test_review2_i3_an_unfinished_mass_pay_gives_back_only_the_credit_still_on_it(): void
    {
        foreach ([[801, 10], [802, 15]] as [$id, $amount]) {
            $this->invoice($id, 'Unpaid', $amount);
            $this->item($id, 'Hosting', 0, $amount);
        }
        // 5.00 applied, 2.00 of it removed since: WHMCS keeps 3.00 on it, its total 22.00, and the customer's balance 2.00.
        $this->whmcs->exec("INSERT INTO tblinvoices (id, userid, invoicenum, date, duedate, datepaid, status, total, credit, taxrate, subtotal)
            VALUES (900, {$this->clientId}, '', '2026-10-16', '2026-10-16', '0000-00-00 00:00:00', 'Unpaid', 22, 3, 0, 25)");
        $this->item(900, 'Invoice', 801, 10);
        $this->item(900, 'Invoice', 802, 15);
        $this->whmcs->exec("INSERT INTO tblcredit (clientid, date, description, amount, relid) VALUES
            ({$this->clientId}, '2026-09-01', 'Goodwill credit', 5, 0),
            ({$this->clientId}, '2026-10-16', 'Credit Applied to Invoice #900', -5, 900),
            ({$this->clientId}, '2026-10-17', 'Credit Removed from Invoice #900', 2, 900)");
        $this->whmcs->exec("UPDATE tblclients SET credit = 2 WHERE id = {$this->clientId}");
        DB::table('credits')->insert(['client_id' => $this->clientId, 'currency_code' => 'EUR', 'amount' => 2, 'created_at' => now(), 'updated_at' => now()]);

        $this->import();

        $this->assertSame('5.00', number_format((float) DB::table('credits')->where('client_id', $this->clientId)->sum('amount'), 2, '.', ''),
            'the 2.00 WHMCS gave back, and the 3.00 still on the parent: never the 5.00 applied again');
        $this->assertSame(['3.00'], DB::table('money_events')->where('client_id', $this->clientId)->where('cause', MoneyTrail::CAUSE_CANCELLATION)
            ->where('kind', 'credit_in')->pluck('amount')->map(fn ($a) => number_format((float) $a, 2, '.', ''))->all());

        foreach (['CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, domainstatus TEXT)', 'CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, merged_ticket_id INTEGER DEFAULT 0)',
            'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY)'] as $sql) {
            $this->whmcs->exec($sql);
        }
        $rows = collect((new WhmcsParity($this->whmcs))->rows())->keyBy('metric');
        $this->assertSame([500, 500], [$rows['Credit balance']['whmcs'], $rows['Credit balance']['mycs']]);
    }

    /**
     * Review 2 of #697, I4: the upgrades the importer names (and the runbook's pre-dump query selects) are those on an
     * invoice it makes unpaid (invoiceStatus): WHMCS's Unpaid, Payment Pending, Collections; never Draft, Paid,
     * Cancelled or Refunded.
     */
    public function test_review2_i4_unpaid_upgrades_are_those_on_an_invoice_imported_unpaid(): void
    {
        $this->service(101);
        $statuses = [700 => 'Unpaid', 701 => 'Payment Pending', 702 => 'Collections', 703 => 'Draft', 704 => 'Paid', 705 => 'Cancelled', 706 => 'Refunded'];
        foreach ($statuses as $invoice => $status) {
            $upgrade = $invoice - 659; // 41..47
            $this->whmcs->exec("INSERT INTO tblupgrades VALUES ({$upgrade}, {$this->clientId}, 9, 'configoptions', '2026-10-15', 101, '7=>1', '7=>2', 1.00, 1.00, 'Pending', 'N')");
            $this->invoice($invoice, $status, 1);
            $this->item($invoice, 'Upgrade', $upgrade, 1);
        }
        $this->import(['importInvoices', 'importInvoiceItems']);

        $output = $this->output->fetch();
        $this->assertStringContainsString('3 unpaid WHMCS upgrade(s)', $output);
        foreach ([700 => 41, 701 => 42, 702 => 43] as $invoice => $upgrade) {
            $this->assertStringContainsString("invoice #{$invoice} service #101 WHMCS upgrade #{$upgrade} (configoptions)", $output);
        }
        $this->assertSame([700, 701, 702], Invoice::query()->where('no_auto_charge', true)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * Review 2 of #697, I5: the weekly marker of the stale-pending notice is set only once the notices mail went out: a
     * run whose mail failed tells staff again the next day, not a week later.
     */
    public function test_review2_i5_a_failed_notices_mail_is_retried_the_next_day(): void
    {
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $this->service(101);
        $this->invoice(700, 'Payment Pending', 10);
        $this->item(700, 'Hosting', 101, 10);
        $this->import();
        $told = fn () => EmailLog::query()->where('subject', 'like', '%needs staff attention%')
            ->where('body', 'like', '%Invoice #700 (client #%')->count();

        $fail = true;
        EmailLog::creating(function (EmailLog $log) use (&$fail) {
            if ($fail && str_contains((string) $log->subject, 'needs staff attention')) {
                throw new \RuntimeException('mail down');
            }
        });
        Carbon::setTestNow('2026-10-28 10:00:00');
        try {
            $this->artisan('app:cron-job')->run();
        } catch (\RuntimeException) {
            // the run fails with it
        }
        $this->assertSame(0, $told(), 'not sent');

        $fail = false;
        Carbon::setTestNow('2026-10-29 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(1, $told(), 'told the next day, not a week later');
        Carbon::setTestNow('2026-10-30 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(1, $told(), 'then once a week');
    }

    /** Review 2 of #697, I2: importedPending binds its ids inline, as changed() does (ADV-690b-S-7). */
    public function test_review2_i2_imported_pending_binds_no_placeholder_per_id(): void
    {
        DB::enableQueryLog();
        (new ReflectionMethod(RollbackReport::class, 'importedPending'))->invoke(new RollbackReport(now()), range(1, 70_000));
        $max = collect(DB::getQueryLog())->max(fn ($q) => count($q['bindings']));
        DB::disableQueryLog();
        $this->assertLessThan(10, $max);
    }

    /** R1b-2 (fixer): the rollback report says a completed imported pending payment has no payment in WHMCS. */
    public function test_fix_r1b_2_the_rollback_report_notes_an_imported_pending_payment(): void
    {
        Config::set('audit.console', false);
        $this->service(101);
        $this->invoice(700, 'Payment Pending', 10);
        $this->item(700, 'Hosting', 101, 10);
        $this->import();
        $cutover = now()->addMinute();
        Carbon::setTestNow('2026-10-22 10:00:00');
        Config::set('audit.console', true);

        (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(700), null, '10.00', 'whmcs-pending-700');

        $section = collect((new RollbackReport($cutover))->sections())->firstWhere('key', 'payments_changed');
        $this->assertSame('note', $section['columns'][1]);
        $this->assertNotEmpty($section['rows']);
        $this->assertStringContainsString('no payment there; record it in WHMCS', $section['rows'][0][1]);
        $this->assertStringContainsString('record the payment in WHMCS', $section['todo']);
    }
}
