<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\User;
use App\Services\Import\WhmcsParity;
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
 * Round-3 review of #523 (the money journal of a WHMCS import), after the fixes in 38029a0d: WHMCS data shapes the
 * journal or parity still gets wrong. Each test fails on 38029a0d for the finding it names.
 */
class ImportMoneyJournalRound3Test extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clientId = User::factory()->create()->client()->id;
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $this->whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        // With WHMCS's own description of each row (addTransaction writes one).
        $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT, refundid INT DEFAULT 0, description TEXT DEFAULT \'\')');
        $this->whmcs->exec('CREATE TABLE tblcredit (id INTEGER PRIMARY KEY, clientid INT, date TEXT, description TEXT, amount REAL, relid INT DEFAULT 0)');
        $this->whmcs->exec('INSERT INTO tblcurrencies VALUES (1, \'EUR\')');
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$this->clientId}, 1)");
    }

    private function invoice(int $id, string $status, float $amount, float $credit = 0, string $paid = '2026-09-03 10:00:00', string $due = '2026-09-10'): void
    {
        $total = $amount - $credit; // WHMCS keeps the total net of the credit applied to it
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES ({$id}, {$this->clientId}, '', '2026-09-01', '{$due}', '{$paid}', '{$status}', {$total}, {$credit}, 0)");
        $this->item($id, 'Hosting', 0, $amount);
    }

    private function item(int $invoice, string $type, int $relid, float $amount): void
    {
        static $id = 9000;
        $id++;
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES ({$id}, {$invoice}, {$this->clientId}, '{$type}', {$relid}, 'Line', {$amount}, 0)");
    }

    private function payment(int $id, int $invoice, float $in, float $out = 0, int $refundOf = 0, string $date = '2026-09-03 10:00:00', string $gateway = 'banktransfer', string $description = 'Invoice Payment'): void
    {
        $this->whmcs->prepare('INSERT INTO tblaccounts VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)')
            ->execute([$id, $this->clientId, $invoice, $gateway, $date, $in, $out, $out > 0 ? '' : "tr_{$id}", $refundOf, $description]);
    }

    private function history(string $date, string $description, float $amount, int $relid = 0): void
    {
        $this->whmcs->prepare('INSERT INTO tblcredit (clientid, date, description, amount, relid) VALUES (?, ?, ?, ?, ?)')
            ->execute([$this->clientId, $date, $description, $amount, $relid]);
    }

    private function balance(float $amount): void
    {
        DB::table('credits')->insert(['client_id' => $this->clientId, 'currency_code' => 'EUR', 'amount' => $amount, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function import(): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        foreach (['importInvoices', 'importInvoiceItems', 'importPayments', 'importMassPay', 'journalMoney'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
    }

    /**
     * ADV-523-R3-1. A Mass Pay WHMCS refunded in full. WHMCS sets a parent whose money all went back to «Refunded»; its
     * children stay Paid, each with the «credit» the parent's payment put on them (tblcredit: «Mass Invoice Payment
     * Credit for Invoice #900» +25, «Credit Applied to Invoice #801/#802» -10/-15). WhmcsMassPay::PARENTS_SQL keeps only
     * a Paid parent (or one with no money), so 900 comes as an ordinary invoice with its payment and refund, while
     * creditApplied() turns the children's credit into credit payments out of the customer's own balance. The «Mass
     * Invoice Payment» row that brought that credit in is skipped (FROM_INVOICES, «rebuilt from the invoices», but 900 is
     * no parent here): the history runs to -25, and an opening_balance adjustment of +25 is invented to cover it and
     * listed to staff as a mismatch; the history shows credit the customer never had.
     */
    public function test_a_mass_pay_refunded_in_full_spends_no_credit_the_customer_never_had(): void
    {
        $this->invoice(801, 'Paid', 10, credit: 10, due: '2026-09-12');
        $this->invoice(802, 'Paid', 15, credit: 15, due: '2026-09-11');
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (900, {$this->clientId}, '', '2026-09-01', '2026-09-10', '2026-09-03 10:00:00', 'Refunded', 25, 0, 0)");
        $this->item(900, 'Invoice', 801, 10);
        $this->item(900, 'Invoice', 802, 15);
        $this->payment(31, 900, 25);
        $this->history('2026-09-03', 'Mass Invoice Payment Credit for Invoice #900', 25);
        $this->history('2026-09-03', 'Credit Applied to Invoice #801', -10, 801);
        $this->history('2026-09-03', 'Credit Applied to Invoice #802', -15, 802);
        $this->payment(32, 900, 0, out: 25, refundOf: 31, date: '2026-09-06 10:00:00', gateway: '', description: 'Refund of Transaction ID tr_31');
        $this->balance(0);

        $this->import();

        $this->assertSame(0, MoneyEvent::query()->where('cause', MoneyTrail::CAUSE_OPENING_BALANCE)->count(), 'the history explains the balance');
        $this->assertSame(0, MoneyEvent::query()->where('credit_balance_after', '<', 0)->count());
    }

    /**
     * ADV-523-R3-2. Parity counts a Mass Pay payment's refund to credit as credit no longer applied. «Credit applied to
     * invoices» here is the credit payments plus, for each Mass Pay payment, amount - credited_amount (the part its
     * child took). A refund to credit of that payment (ADV-523-R2-2) raises its credited_amount, so the child's share
     * shrinks by the refund: 20 here against WHMCS's 25, although every child kept what WHMCS applied to it. mycs:parity
     * exits 1 on an import that is right.
     */
    public function test_parity_is_not_moved_by_a_refund_to_credit_of_a_mass_pay_payment(): void
    {
        $this->invoice(801, 'Paid', 10, credit: 10, due: '2026-09-12');
        $this->invoice(802, 'Paid', 15, credit: 15, due: '2026-09-11');
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (900, {$this->clientId}, '', '2026-09-01', '2026-09-10', '2026-09-03', 'Paid', 25, 0, 0)");
        $this->item(900, 'Invoice', 801, 10);
        $this->item(900, 'Invoice', 802, 15);
        $this->payment(31, 900, 25);
        $this->payment(32, 900, 0, out: 5, refundOf: 31, date: '2026-09-06 10:00:00', gateway: '',
            description: 'Refund of Transaction ID tr_31 to Credit Balance');
        $this->history('2026-09-06', 'Credit from Refund of Invoice ID 900', 5);
        $this->balance(5);

        $this->import();
        // What parity reads besides (its WHMCS side), on the same copy.
        foreach (['ALTER TABLE tblinvoices ADD COLUMN subtotal REAL DEFAULT 0', 'ALTER TABLE tblinvoices ADD COLUMN tax REAL DEFAULT 0',
            'ALTER TABLE tblinvoices ADD COLUMN tax2 REAL DEFAULT 0', 'UPDATE tblinvoices SET subtotal = total + credit',
            'ALTER TABLE tblclients ADD COLUMN credit REAL DEFAULT 0', 'CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, domainstatus TEXT)',
            'CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, merged_ticket_id INTEGER DEFAULT 0)', 'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY)'] as $sql) {
            $this->whmcs->exec($sql);
        }

        $rows = collect((new WhmcsParity($this->whmcs))->rows())->keyBy('metric');
        $this->assertSame([2500, 2500], [$rows['Credit applied to invoices']['whmcs'], $rows['Credit applied to invoices']['mycs']]);
        $this->assertSame([500, 500], [$rows['Money out (refunds)']['whmcs'], $rows['Money out (refunds)']['mycs']]);
    }

    /**
     * ADV-523-R3-3. A cash refund of just the overpaid part. A 50 payment of a 40 invoice gave 10 to credit («Invoice
     * #N Overpayment»); staff refunded the 10 in cash and WHMCS took the credit back («Removal of Credit from Invoice
     * #N» -10). importRefunds uses the «Removal» row only when the refund is more than the invoice kept: 10 fits in
     * the 40, so the refund is taken from the invoice's own money. The payment keeps its credited 10 and refunds 10
     * (refundable 30 where the invoice kept 40), a -10 refund note marks down an invoice WHMCS still has paid in full,
     * and the «Removal» row is journaled again from the history as a staff_adjustment, though WHMCS named its invoice.
     */
    public function test_a_cash_refund_of_the_overpaid_part_takes_back_its_credit(): void
    {
        $this->invoice(311, 'Paid', 40);
        $this->payment(51, 311, 50);
        $this->history('2026-09-03', 'Invoice #311 Overpayment', 10, 311);
        $this->history('2026-09-05', 'Removal of Credit from Invoice #311', -10, 311);
        $this->payment(52, 311, 0, out: 10, refundOf: 51, date: '2026-09-05 10:00:00', gateway: '', description: 'Refund of Transaction ID tr_51');
        $this->balance(0);

        $this->import();

        $payment = InvoiceTransaction::query()->findOrFail(51);
        $this->assertSame(['10.00', '0.00'], [(string) $payment->refunded_amount, (string) $payment->credited_amount],
            'the 10 that became credit went back; the invoice kept its 40');
        $this->assertSame(0, DB::table('adjustment_notes')->where('invoice_id', 311)->count(), 'the invoice kept its 40');
        $this->assertSame(0, MoneyEvent::query()->where('cause', MoneyTrail::CAUSE_STAFF_ADJUSTMENT)->count(), 'WHMCS named the invoice');
    }
}
