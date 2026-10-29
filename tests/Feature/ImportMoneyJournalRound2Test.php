<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\User;
use App\Services\Money\MoneyCheck;
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
 * Round-2 review of #523 (the money journal of a WHMCS import), after the fixes in 6fe2f7e8: WHMCS data shapes the
 * refund-to-credit detection still gets wrong. Each test fails on 6fe2f7e8 for the finding it names.
 */
class ImportMoneyJournalRound2Test extends TestCase
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

    /** @return list<string> what MoneyCheck finds */
    private function findings(): array
    {
        return collect((new MoneyCheck)->run())->map(fn (array $finding) => "{$finding['rule']}: {$finding['subject']} {$finding['detail']}")->all();
    }

    /**
     * ADV-523-R2-1. A cash refund taken for a refund to credit. importRefunds matches WHMCS's «Credit from Refund of
     * Invoice ID N» row to the first refund of that invoice with the same amount, in date order, whatever the refund's
     * own description says. An invoice refunded twice for the same amount, first in cash («Refund of Transaction ID
     * tr_41») and later to credit («... to Credit Balance»): the cash refund takes the credit row and becomes a
     * refund_to_credit, the real refund to credit is one too (by its description). No money out is journaled, the
     * payment has no refunded_amount (staff could give the 10 back again in cash), the customer's history gains 10 of
     * credit it never had, and an opening_balance adjustment takes it away again (listed to staff as a mismatch).
     */
    public function test_a_cash_refund_is_not_taken_for_a_later_refund_to_credit_of_the_same_amount(): void
    {
        $this->invoice(301, 'Paid', 50);
        $this->payment(41, 301, 50);
        $this->payment(42, 301, 0, out: 10, refundOf: 41, date: '2026-09-04 10:00:00', gateway: '', description: 'Refund of Transaction ID tr_41');
        $this->payment(43, 301, 0, out: 10, refundOf: 41, date: '2026-09-05 10:00:00', gateway: '',
            description: 'Refund of Transaction ID tr_41 to Credit Balance');
        $this->history('2026-09-05', 'Credit from Refund of Invoice ID 301', 10);
        $this->balance(10);

        $this->import();

        $payment = InvoiceTransaction::query()->findOrFail(41);
        $this->assertSame(['10.00', '10.00'], [(string) $payment->refunded_amount, (string) $payment->credited_amount],
            'one refund in cash, one to credit');
        $refund = MoneyEvent::query()->where('kind', 'refund')->sole();
        $this->assertSame(['10.00', '2026-09-04 10:00:00'], [(string) $refund->amount, $refund->occurred_at->toDateTimeString()]);
        $this->assertSame(0, MoneyEvent::query()->where('cause', MoneyTrail::CAUSE_OPENING_BALANCE)->count(), 'the history explains the balance');
    }

    /**
     * ADV-523-R2-2. A refund to credit of a Mass Pay payment is credited twice. WHMCS writes «Credit from Refund of
     * Invoice ID 900» (the parent, the invoice the payment is on there), but here the payment is on a child (802), so
     * importRefunds never finds the row: the refund is recognised by its description alone and the tblcredit row is not
     * marked used. history() then journals that row again as a refund_to_credit credit_in: the ADV-523-1 double count,
     * for Mass Pay (whose refunds ADV-523-3 brought in). The moves add up to 10 for a balance of 5, and an
     * opening_balance adjustment takes the 5 away, listed to staff as a mismatch.
     */
    public function test_a_refund_to_credit_of_a_mass_pay_payment_is_credited_once(): void
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

        $this->assertSame(5.0, (float) MoneyEvent::query()->where('cause', MoneyTrail::CAUSE_REFUND_TO_CREDIT)->sum('amount'), 'one refund to credit, of 5');
        $this->assertSame(0, MoneyEvent::query()->where('cause', MoneyTrail::CAUSE_OPENING_BALANCE)->count(), 'the history explains the balance');
    }
}
