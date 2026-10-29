<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\MoneyEvent;
use App\Models\User;
use App\Services\Money\MoneyTrail;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Adversarial review of #528, round 2 (the fixes in 33f8428a).
 */
class ImportDatesAndCountriesRound2Test extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private ImportFromWhmcs $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR')");

        $this->command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($this->command, 'pdo'))->setValue($this->command, $this->whmcs);
        $this->command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
    }

    /**
     * ADV-528-R2-1: the same hole as ADV-528-2, outside Mass Pay. An invoice WHMCS applied account credit to, with no
     * datepaid (still Unpaid, the rest owed) and no «Credit Applied to Invoice» row in tblcredit: creditApplied dates
     * its credit payment by the invoice's day (`date`, now midnight) but does not flag it day-only, so the journal puts
     * the credit out at 00:00, before the same day's timed overpayment that brought the credit: below zero.
     */
    public function test_credit_applied_dated_by_the_invoices_day_alone_is_spent_after_the_days_timed_credit(): void
    {
        $clientId = User::factory()->create()->client()->id;
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $this->whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT, refundid INT DEFAULT 0)');
        $this->whmcs->exec('CREATE TABLE tblcredit (id INTEGER PRIMARY KEY, clientid INT, date TEXT, description TEXT, amount REAL, relid INT DEFAULT 0)');
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$clientId}, 1)");

        // 2026-09-01 10:00: invoice 103 (25) paid 30, 5 to credit.
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (103, {$clientId}, '', '2026-09-01', '2026-09-10', '2026-09-01 10:00:00', 'Paid', 25, 0, 0)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES (1, 103, {$clientId}, 'Hosting', 0, 'Line', 25, 0)");
        $this->whmcs->exec("INSERT INTO tblaccounts VALUES (1, {$clientId}, 103, 'banktransfer', '2026-09-01 10:00:00', 30, 0, 0, 'tr_1', 0)");
        $this->whmcs->exec("INSERT INTO tblcredit (clientid, date, description, amount) VALUES ({$clientId}, '2026-09-01', 'Invoice #103 Overpayment', 5)");
        // The same day: invoice 104 (20), 5 of it paid from that credit, the rest owed; no datepaid, no «Credit Applied» row.
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (104, {$clientId}, '', '2026-09-01', '2026-09-10', '0000-00-00 00:00:00', 'Unpaid', 15, 5, 0)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES (2, 104, {$clientId}, 'Hosting', 0, 'Line', 20, 0)");

        foreach (['importInvoices', 'importInvoiceItems', 'importPayments', 'importMassPay', 'journalMoney'] as $step) {
            (new ReflectionMethod($this->command, $step))->invoke($this->command);
        }

        $moves = MoneyEvent::query()->whereIn('kind', ['credit_in', 'credit_out'])->orderBy('id')->get()
            ->map(fn (MoneyEvent $event) => [$event->kind->value, $event->cause, (string) $event->credit_balance_after])->all();
        $this->assertSame([['credit_in', MoneyTrail::CAUSE_OVERPAYMENT, '5.00'], ['credit_out', MoneyTrail::CAUSE_CREDIT_PAYMENT, '0.00']], $moves,
            'the credit is spent after it came, never below zero');
        $this->assertSame(0, DB::table('money_events')->where('credit_balance_after', '<', 0)->count());
    }
}
