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
 * Adversarial review of #523 (the money journal of a WHMCS import): WHMCS data shapes for which the import writes a
 * journal, a payment or a balance that is not what happened, or that a later live refund mishandles. Each test fails on
 * b8ac2f60 for the finding it names.
 */
class ImportMoneyJournalAdversarialTest extends TestCase
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
     * ADV-523-1. A WHMCS refund «to credit balance» (refundInvoicePayment with «Add to Client's Credit Balance»: a
     * tblaccounts row with amountout and refundid, plus «Credit from Refund of Invoice ID N» in tblcredit) is no money
     * out: the customer got credit, not cash. The import journals it twice: a `refund` event (money that left, as if
     * by the gateway or bank) and a `credit_in` refund_to_credit. Live, ExtensionHelper::refundToCredit records it as
     * credited_amount on the payment and a credit_in tied to it, with no refund event.
     */
    public function test_a_refund_to_credit_is_no_money_out(): void
    {
        $this->invoice(201, 'Paid', 50);
        $this->payment(21, 201, 50);
        $this->payment(22, 201, 0, out: 20, refundOf: 21, date: '2026-09-05 10:00:00', gateway: '',
            description: 'Refund of Transaction ID tr_21 to Credit Balance');
        $this->history('2026-09-05', 'Credit from Refund of Invoice ID 201', 20);
        $this->balance(20);

        $this->import();

        $this->assertSame(0, MoneyEvent::query()->where('kind', 'refund')->count(), 'no money left: it became credit');
        $payment = InvoiceTransaction::query()->findOrFail(21);
        $this->assertSame(['0.00', '20.00'], [(string) $payment->refunded_amount, (string) $payment->credited_amount],
            'as refundToCredit records it: the payment\'s credited part, not a refund');
        $this->assertSame([], $this->findings());
    }

    /**
     * ADV-523-2. An overpayment later partly refunded. WHMCS: a 50 payment of a 40 invoice gave 10 to credit
     * («Invoice #N Overpayment»), then staff refunded the 40 the invoice kept. overpayments() runs after the refunds and
     * measures the overpayment net of them, so the refund eats the overpayment: the payment gets no credited_amount, the
     * customer's 10 is journaled as an unexplained opening_balance adjustment (and listed to staff as a mismatch), the
     * invoice's refund note is 30 for a refund of 40, and the payment keeps 10 «refundable» that already became credit:
     * staff can refund it again in cash (ExtensionHelper::refundLocally) while the customer keeps the 10 credit.
     */
    public function test_a_refund_does_not_eat_an_earlier_overpayment(): void
    {
        $this->invoice(202, 'Paid', 40);
        $this->payment(23, 202, 50);
        $this->history('2026-09-03', 'Invoice #202 Overpayment', 10, 202);
        $this->payment(24, 202, 0, out: 40, refundOf: 23, date: '2026-09-05 10:00:00');
        $this->balance(10);

        $this->import();

        $payment = InvoiceTransaction::query()->findOrFail(23);
        $this->assertSame(['40.00', '10.00'], [(string) $payment->refunded_amount, (string) $payment->credited_amount]);
        $this->assertSame(0.0, (float) $payment->refundable_amount, 'nothing left to give back: 40 refunded, 10 became credit');
        $this->assertSame(0, MoneyEvent::query()->where('cause', MoneyTrail::CAUSE_OPENING_BALANCE)->count(), 'the overpayment explains the balance');
        $this->assertSame(-40.0, (float) DB::table('adjustment_notes')->where('invoice_id', 202)->sum('amount'), 'the note is the refund WHMCS made');
        $this->assertSame([], $this->findings());
    }

    /**
     * ADV-523-3. A refund of a Mass Pay payment. importRefunds runs inside importPayments, before importMassPay writes
     * the parent's payment (on its child), so the refund is never put on it: no refunded_amount, no refund event, and
     * the whole payment stays refundable here (staff can give the same money back a second time). The importer only
     * warns «refund ... was not imported»; the journal says no money ever went out.
     */
    public function test_a_refund_of_a_mass_pay_payment_is_on_it(): void
    {
        $this->invoice(801, 'Paid', 10, credit: 10, due: '2026-09-12');
        $this->invoice(802, 'Paid', 15, credit: 15, due: '2026-09-11');
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (900, {$this->clientId}, '', '2026-09-01', '2026-09-10', '2026-09-03', 'Paid', 25, 0, 0)");
        $this->item(900, 'Invoice', 801, 10);
        $this->item(900, 'Invoice', 802, 15);
        $this->payment(31, 900, 25);
        $this->payment(32, 900, 0, out: 5, refundOf: 31, date: '2026-09-06 10:00:00');
        $this->balance(0);

        $this->import();

        $payment = InvoiceTransaction::query()->findOrFail(31);
        $this->assertSame('5.00', (string) $payment->refunded_amount, 'what WHMCS gave back of it');
        $this->assertSame(5.0, (float) MoneyEvent::query()->where('kind', 'refund')->where('invoice_transaction_id', 31)->sum('amount'));
    }

    /**
     * ADV-523-4. Same-day credit: tblcredit.date is a DATE in WHMCS, so «Credit Applied to Invoice» dates the credit
     * payment at 00:00 of the day, before the overpayment (a payment at 10:00) that gave the credit it spent. Customers
     * whose overpayment is applied to their next invoice at once (WHMCS does it automatically) get a history whose
     * balance runs below zero (credit_balance_after -40), which the docs say never happens; the credit payment is also
     * dated before the money that funded it.
     */
    public function test_credit_spent_the_day_it_came_never_runs_below_zero(): void
    {
        $this->invoice(203, 'Paid', 60);
        $this->payment(25, 203, 100, date: '2026-09-03 10:00:00');
        $this->history('2026-09-03', 'Invoice #203 Overpayment', 40, 203);
        $this->invoice(204, 'Paid', 40, credit: 40, paid: '2026-09-03 10:00:05');
        $this->history('2026-09-03', 'Credit Applied to Invoice #204', -40, 204);
        $this->balance(0);

        $this->import();

        $this->assertSame([], MoneyEvent::query()->where('credit_balance_after', '<', 0)->orderBy('id')
            ->get()->map(fn (MoneyEvent $event) => "{$event->kind->value} {$event->cause} {$event->amount} at {$event->occurred_at}: balance {$event->credit_balance_after}")->all());
    }

    /**
     * ADV-523-5. An Add Funds invoice paid with WHMCS's datepaid 0000-00-00 (old WHMCS data, the myip import): its
     * paid_at is null, so purchases() journals the credit it bought «now» (and marks its line applied now), after the
     * credit payments that spent that credit years ago. The history runs below zero until today, and the purchase is
     * dated the day of the import although its payment (tblaccounts) has its real date.
     */
    public function test_a_credit_purchase_without_a_paid_date_is_dated_by_its_payment(): void
    {
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (99, {$this->clientId}, '', '2026-08-01', '2026-08-01', '0000-00-00 00:00:00', 'Paid', 10, 0, 0)");
        $this->item(99, 'AddFunds', 0, 10);
        $this->payment(26, 99, 10, date: '2026-08-01 09:00:00');
        $this->history('2026-08-01', 'Add Funds Invoice #99', 10);
        $this->invoice(205, 'Paid', 10, credit: 10, paid: '2026-09-02 12:00:00');
        $this->history('2026-09-02', 'Credit Applied to Invoice #205', -10, 205);
        $this->balance(0);

        $this->import();

        $purchase = MoneyEvent::query()->where('cause', MoneyTrail::CAUSE_PURCHASE)->sole();
        $this->assertSame('2026-08-01', $purchase->occurred_at->toDateString(), 'when its payment came, not the day of the import');
        $this->assertSame(0, MoneyEvent::query()->where('credit_balance_after', '<', 0)->count(), 'the credit was there before it was spent');
        $this->assertSame(Invoice::STATUS_PAID, Invoice::query()->findOrFail(99)->status);
    }
}
