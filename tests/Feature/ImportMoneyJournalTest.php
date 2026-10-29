<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditAutoApply;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * The money journal of a WHMCS import (WhmcsMoneyJournal): every payment, refund and credit move has its event, as money
 * recorded here does, so the import passes MoneyCheck and mycs:credits-check; a balance WHMCS moved around its own
 * history keeps WHMCS's figure, with one adjustment listed for staff.
 */
class ImportMoneyJournalTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private BufferedOutput $output;

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
        $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT, refundid INT DEFAULT 0)');
        $this->whmcs->exec('CREATE TABLE tblcredit (id INTEGER PRIMARY KEY, clientid INT, date TEXT, description TEXT, amount REAL, relid INT DEFAULT 0)');
        $this->whmcs->exec('INSERT INTO tblcurrencies VALUES (1, \'EUR\')');
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$this->clientId}, 1)");
    }

    private function invoice(int $id, string $status, float $amount, float $credit = 0, string $paid = '2026-09-03 10:00:00', string $due = '2026-09-10'): void
    {
        // WHMCS keeps the total net of the credit applied to it.
        $total = $amount - $credit;
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES ({$id}, {$this->clientId}, '', '2026-09-01', '{$due}', '{$paid}', '{$status}', {$total}, {$credit}, 0)");
        $this->item($id, 'Hosting', 0, $amount);
    }

    private function item(int $invoice, string $type, int $relid, float $amount): void
    {
        static $id = 7000;
        $id++;
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES ({$id}, {$invoice}, {$this->clientId}, '{$type}', {$relid}, 'Line', {$amount}, 0)");
    }

    private function payment(int $id, int $invoice, float $in, float $out = 0, int $refundOf = 0, string $date = '2026-09-03 10:00:00', ?int $userId = null): void
    {
        $userId ??= $this->clientId;
        $this->whmcs->exec("INSERT INTO tblaccounts VALUES ({$id}, {$userId}, {$invoice}, 'banktransfer', '{$date}', {$in}, {$out}, 0, 'tr_{$id}', {$refundOf})");
    }

    private function history(string $date, string $description, float $amount, int $relid = 0): void
    {
        $this->whmcs->prepare('INSERT INTO tblcredit (clientid, date, description, amount, relid) VALUES (?, ?, ?, ?, ?)')
            ->execute([$this->clientId, $date, $description, $amount, $relid]);
    }

    /** The customer's WHMCS balance, as importUsers brings it (tblclients.credit). */
    private function balance(float $amount): void
    {
        DB::table('credits')->insert(['client_id' => $this->clientId, 'currency_code' => 'EUR', 'amount' => $amount, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function import(): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $this->output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));
        foreach (['importInvoices', 'importInvoiceItems', 'importPayments', 'importMassPay', 'journalMoney'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
    }

    /** What MoneyCheck still finds (rule => subjects), and that every balance is its journal. */
    private function assertChecksPass(): void
    {
        $this->assertSame([], collect((new MoneyCheck)->run())->map(fn (array $finding) => "{$finding['rule']}: {$finding['subject']} {$finding['detail']}")->all());
        $this->artisan('mycs:credits-check')->assertSuccessful()->expectsOutputToContain('Every credit balance matches the journal.');
    }

    /** @return list<array{0: string, 1: ?string, 2: string}> kind, cause, amount of each event, in the journal's order */
    private function journal(): array
    {
        return MoneyEvent::query()->orderBy('id')->get()->map(fn (MoneyEvent $event) => [$event->kind->value, $event->cause, (string) $event->amount])->all();
    }

    public function test_a_payment_has_its_event_from_the_import(): void
    {
        $this->invoice(101, 'Paid', 20);
        $this->payment(1, 101, 20);

        $this->import();

        $event = MoneyEvent::query()->sole();
        $this->assertSame(['payment', 'import', 1, 101, $this->clientId, 'EUR', '20.00', '2026-09-03 10:00:00'],
            [$event->kind->value, $event->source, (int) $event->invoice_transaction_id, (int) $event->invoice_id, (int) $event->client_id,
                $event->currency_code, (string) $event->amount, $event->occurred_at->toDateTimeString()]);
        $this->assertChecksPass();
    }

    public function test_credit_applied_to_an_invoice_is_a_credit_payment_from_its_balance(): void
    {
        $this->invoice(102, 'Paid', 30, credit: 10);
        $this->payment(2, 102, 20);
        $this->history('2026-08-01', 'Add Funds Invoice #99', 10);
        $this->history('2026-09-02', 'Credit Applied to Invoice #102', -10, 102);
        // The credit came from an Add Funds invoice paid earlier.
        $this->invoice(99, 'Paid', 0, paid: '2026-08-01 09:00:00');
        $this->item(99, 'AddFunds', 0, 10);
        $this->payment(3, 99, 10, date: '2026-08-01 09:00:00');

        $this->import();

        $credit = InvoiceTransaction::query()->where('invoice_id', 102)->where('is_credit_transaction', true)->sole();
        $this->assertSame(['10.00', null, '2026-09-02'], [(string) $credit->amount, $credit->source_transaction_id, $credit->created_at->toDateString()],
            'the customer\'s own credit, on the day WHMCS applied it');
        $this->assertSame(0.0, (float) Invoice::query()->findOrFail(102)->remaining);
        $this->assertSame([['payment', null, '10.00'], ['credit_in', 'purchase', '10.00'], ['credit_out', 'credit_payment', '10.00'],
            ['payment', null, '10.00'], ['payment', null, '20.00']], $this->journal(), 'in time order, the purchase before the credit it paid');
        $this->assertNotNull(DB::table('invoice_items')->where('invoice_id', 99)->where('reference_type', Credit::class)->value('applied_at'), 'the purchase gave its credit once');
        $this->assertSame(0, Credit::query()->count(), 'no balance left: none in WHMCS either');
        $this->assertChecksPass();
    }

    public function test_an_overpayment_is_the_newest_payments_credit(): void
    {
        $this->invoice(103, 'Paid', 25);
        $this->payment(4, 103, 20, date: '2026-09-02 10:00:00');
        $this->payment(5, 103, 10, date: '2026-09-03 10:00:00');
        $this->history('2026-09-03', 'Invoice #103 Overpayment', 5, 103);
        $this->balance(5);

        $this->import();

        $this->assertSame(['0.00', '5.00'], InvoiceTransaction::query()->whereIn('id', [4, 5])->orderBy('id')->pluck('credited_amount')->map(fn ($amount) => (string) $amount)->all());
        $overpayment = MoneyEvent::query()->where('cause', MoneyTrail::CAUSE_OVERPAYMENT)->sole();
        $this->assertSame([5, '5.00', '5.00'], [(int) $overpayment->invoice_transaction_id, (string) $overpayment->amount, (string) $overpayment->credit_balance_after]);
        $this->assertChecksPass();
    }

    public function test_a_refund_has_its_event_and_a_note_keeps_the_invoice_settled(): void
    {
        $this->invoice(104, 'Paid', 50);
        $this->payment(6, 104, 50);
        $this->payment(7, 104, 0, out: 20, refundOf: 6, date: '2026-09-05 10:00:00');

        $this->import();

        $this->assertNull(InvoiceTransaction::query()->find(7), 'a refund\'s own row is no payment');
        $refund = MoneyEvent::query()->where('kind', 'refund')->sole();
        $this->assertSame([6, '20.00', '2026-09-05 10:00:00'], [(int) $refund->invoice_transaction_id, (string) $refund->amount, $refund->occurred_at->toDateTimeString()]);
        $note = DB::table('adjustment_notes')->where('invoice_id', 104)->sole();
        $this->assertSame([-20.0, 1, 'Refund for transaction #6 (tr_6)'], [(float) $note->amount, (int) $note->is_refund, $note->description]);
        $this->assertChecksPass();
    }

    public function test_a_second_payment_given_back_leaves_the_invoice_paid_without_a_note(): void
    {
        $this->invoice(105, 'Paid', 40);
        $this->payment(8, 105, 40);
        $this->payment(9, 105, 40);
        $this->payment(10, 105, 0, out: 40, refundOf: 9);

        $this->import();

        $this->assertSame(0, DB::table('adjustment_notes')->count());
        $this->assertSame('40.00', (string) InvoiceTransaction::query()->findOrFail(9)->refunded_amount);
        $this->assertChecksPass();
    }

    public function test_an_invoice_whmcs_marked_refunded_with_no_money_owes_nothing(): void
    {
        $this->invoice(106, 'Refunded', 15);

        $this->import();

        $note = DB::table('adjustment_notes')->where('invoice_id', 106)->sole();
        $this->assertSame([-15.0, 0], [(float) $note->amount, (int) $note->is_refund]);
        $this->assertChecksPass();
    }

    public function test_staff_moves_come_from_whmcs_history_with_their_description_apart(): void
    {
        $this->history('2026-07-01', 'Bank transfer kept as credit', 30);
        $this->history('2026-07-02', 'Credit from Refund of Invoice ID 5', 4);
        $this->history('2026-07-03', 'Upgrade/Downgrade Credit', 6);
        $this->history('2026-07-04', 'Taken back by staff', -10);
        $this->balance(30);

        $this->import();

        $this->assertSame([['credit_in', 'staff_adjustment', '30.00'], ['credit_in', 'refund_to_credit', '4.00'], ['credit_in', 'downgrade', '6.00'],
            ['credit_out', 'staff_adjustment', '10.00']], $this->journal());
        $first = MoneyEvent::query()->orderBy('id')->firstOrFail();
        $this->assertSame('WHMCS: Bank transfer kept as credit', DB::table('money_event_notes')->where('money_event_id', $first->id)->value('note'),
            'free text apart from the journal, so it can be cleared');
        $this->assertSame(['30.00', '34.00', '40.00', '30.00'], MoneyEvent::query()->orderBy('id')->pluck('credit_balance_after')->map(fn ($amount) => (string) $amount)->all());
        $this->assertChecksPass();
    }

    public function test_credit_whmcs_has_no_record_of_opens_the_history_and_is_listed(): void
    {
        // Paid wholly from credit that tblcredit never got (staff set it by hand in WHMCS).
        $this->invoice(107, 'Paid', 12, credit: 12, paid: '2026-06-10 12:00:00');
        $this->history('2026-08-01', 'Bank transfer kept as credit', 5);
        $this->balance(5);

        $this->import();

        $this->assertSame([['credit_in', 'opening_balance', '12.00'], ['credit_out', 'credit_payment', '12.00'], ['payment', null, '12.00'],
            ['credit_in', 'staff_adjustment', '5.00']], $this->journal(), 'before the first move, so no balance ever runs below zero');
        $opening = MoneyEvent::query()->where('cause', MoneyTrail::CAUSE_OPENING_BALANCE)->sole();
        $this->assertSame(['2026-06-10 12:00:00', '12.00'], [$opening->occurred_at->toDateTimeString(), (string) $opening->credit_balance_after]);
        $this->assertStringContainsString('WHMCS balance 5.00, what its history adds up to -7.00', (string) DB::table('money_event_notes')->where('money_event_id', $opening->id)->value('note'));
        $this->assertStringContainsString('1 customer(s) whose WHMCS credit balance is not what its history adds up to', $this->output->fetch());
        $this->assertChecksPass();
    }

    public function test_credit_gone_from_whmcs_without_a_record_goes_now(): void
    {
        $this->history('2024-01-01', 'Bank transfer kept as credit', 20);
        $this->balance(0); // staff emptied it in WHMCS's database

        $this->import();

        $gone = MoneyEvent::query()->where('cause', MoneyTrail::CAUSE_OPENING_BALANCE)->sole();
        $this->assertSame(['credit_out', '20.00', '0.00'], [$gone->kind->value, (string) $gone->amount, (string) $gone->credit_balance_after]);
        $this->assertTrue($gone->occurred_at->isToday(), 'after its history: it went at some point WHMCS does not say');
        $this->assertChecksPass();
    }

    public function test_a_negative_invoice_gave_its_customer_credit(): void
    {
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (108, {$this->clientId}, '', '2026-09-01', '2026-09-10', '2026-09-03', 'Paid', -0.6, -0.6, 0)");
        $this->item(108, 'Item', 0, -0.6);
        $this->history('2026-09-03', 'Credit Applied to Invoice #108', 0.6, 108);
        $this->balance(0.6);

        $this->import();

        $event = MoneyEvent::query()->sole();
        $this->assertSame(['credit_in', MoneyTrail::CAUSE_INVOICE_REDUCED, '0.60', 108], [$event->kind->value, $event->cause, (string) $event->amount, (int) $event->invoice_id]);
        $this->artisan('mycs:credits-check')->assertSuccessful();
    }

    public function test_mass_pay_journals_its_payment_and_allocations_and_a_childs_own_credit(): void
    {
        // Parent 900 of 802 (15, due first) and 801 (10, of which 4 from the customer's own credit); paid 21 at once.
        $this->invoice(801, 'Paid', 10, credit: 10, due: '2026-09-12');
        $this->invoice(802, 'Paid', 15, credit: 15, due: '2026-09-11');
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (900, {$this->clientId}, '', '2026-09-01', '2026-09-10', '2026-09-03', 'Paid', 21, 0, 0)");
        $this->item(900, 'Invoice', 801, 6);
        $this->item(900, 'Invoice', 802, 15);
        $this->payment(11, 900, 21);
        $this->history('2026-08-01', 'Bank transfer kept as credit', 4);
        $this->balance(0);

        $this->import();

        $allocation = InvoiceTransaction::query()->where('source_transaction_id', 11)->sole();
        $own = InvoiceTransaction::query()->where('invoice_id', 801)->where('is_credit_transaction', true)->whereNull('source_transaction_id')->sole();
        $this->assertSame([801, '6.00', '4.00'], [(int) $allocation->invoice_id, (string) $allocation->amount, (string) $own->amount],
            'the parent paid 6 of the child, the customer\'s own credit the other 4');
        $grouped = MoneyEvent::query()->where('group_reference', 'transaction:11')->orderBy('id')->get()->map(fn (MoneyEvent $event) => [$event->kind->value, (string) $event->amount])->all();
        $this->assertSame([['credit_out', '6.00'], ['payment', '6.00']], $grouped, 'as RecordPaymentService::spendCredit groups a sale\'s shares');
        $this->assertChecksPass();
    }

    public function test_only_money_on_an_imported_invoice_is_a_payment(): void
    {
        $this->invoice(109, 'Paid', 10);
        $this->payment(12, 109, 10, userId: 0); // WHMCS leaves a payment's own customer 0 at times
        $this->payment(13, 109, 0); // marked paid: a row of nothing
        $this->payment(14, 0, 7); // on no invoice

        $this->import();

        $this->assertSame([12], InvoiceTransaction::query()->pluck('id')->all());
        $this->assertStringContainsString('WHMCS tblaccounts #14', $this->output->fetch());
        $this->assertChecksPass();
    }

    /**
     * Review of #523: staff take an imported credit payment off an open invoice after the cut-over; the daily pass must
     * not pay it straight back (CreditAutoApply::eligible saw only source «credit» reversals).
     */
    public function test_an_imported_credit_payment_staff_took_off_is_not_paid_back(): void
    {
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
        $this->invoice(201, 'Unpaid', 30, credit: 10);
        $this->history('2026-08-01', 'Bank transfer kept as credit', 10);
        $this->history('2026-09-02', 'Credit Applied to Invoice #201', -10, 201);
        $this->balance(0);
        $this->import();

        (new RecordPaymentService)->deleteTransaction(InvoiceTransaction::query()->where('invoice_id', 201)->where('is_credit_transaction', true)->sole());
        $this->assertSame(10.0, (float) Credit::query()->where('client_id', $this->clientId)->value('amount'), 'the credit came back');

        (new CreditAutoApply)->forCustomer($this->clientId, 'EUR');

        $this->assertSame(0, InvoiceTransaction::query()->where('invoice_id', 201)->where('is_credit_transaction', true)->count(),
            'the credit stays where staff put it');
    }

    /** Review of #523: each WHMCS refund is its own event, on its own date, as each refund here is. */
    public function test_each_refund_is_its_own_event(): void
    {
        $this->invoice(110, 'Paid', 50);
        $this->payment(15, 110, 50);
        $this->payment(16, 110, 0, out: 10, refundOf: 15, date: '2026-09-05 10:00:00');
        $this->payment(17, 110, 0, out: 5, refundOf: 15, date: '2026-09-07 10:00:00');

        $this->import();

        $this->assertSame([['10.00', '2026-09-05 10:00:00'], ['5.00', '2026-09-07 10:00:00']], MoneyEvent::query()->where('kind', 'refund')->orderBy('id')
            ->get()->map(fn (MoneyEvent $event) => [(string) $event->amount, $event->occurred_at->toDateTimeString()])->all());
        $this->assertSame('15.00', (string) InvoiceTransaction::query()->findOrFail(15)->refunded_amount);
        $this->assertChecksPass();
    }

    /** Review of #523: money out on no payment (a chargeback, a refund typed by hand) is named, not dropped silently. */
    public function test_money_out_on_no_payment_is_named(): void
    {
        $this->invoice(111, 'Paid', 20);
        $this->payment(18, 111, 20);
        $this->payment(19, 111, 0, out: 20);

        $this->import();

        $this->assertStringContainsString('Money out on no payment imported', $output = $this->output->fetch());
        $this->assertStringContainsString('tblaccounts #19', $output);
        $this->assertSame('0.00', (string) InvoiceTransaction::query()->findOrFail(18)->refunded_amount);
    }

    /** A payment WHMCS dated 0000-00-00 is journaled on its invoice's date, never the day of the import. */
    public function test_a_payment_without_a_date_takes_its_invoices(): void
    {
        $this->invoice(112, 'Paid', 20);
        $this->payment(20, 112, 20, date: '0000-00-00 00:00:00');

        $this->import();

        $this->assertSame('2026-09-01', MoneyEvent::query()->where('kind', 'payment')->sole()->occurred_at->toDateString());
    }

    /** Review of #523: a source query that fails for any reason but a missing table stops the import, never reads as «no history». */
    public function test_a_failing_history_query_stops_the_import(): void
    {
        // A history whose every read fails (an integer overflow), not one that is missing.
        $this->whmcs->exec('DROP TABLE tblcredit');
        $this->whmcs->exec("CREATE VIEW tblcredit AS SELECT abs(-9223372036854775807 - 1) AS id, 1 AS clientid, '2026-09-01' AS date,
            'Credit Applied to Invoice #1' AS description, -1 AS amount, 1 AS relid");

        $this->expectException(PDOException::class);
        $this->import();
    }

    /**
     * WHMCS gave a second payment to credit (Overpayment), then took that credit back («Removal of Credit from Invoice
     * #N») to refund the payment in cash: the refund takes the payment's credited part, as one here could only after the
     * credit came back.
     */
    public function test_a_cash_refund_of_an_overpayment_takes_back_its_credit(): void
    {
        $this->invoice(113, 'Paid', 60);
        $this->payment(21, 113, 60);
        $this->payment(22, 113, 90, date: '2026-09-04 10:00:00');
        $this->history('2026-09-04', 'Invoice #113 Overpayment', 90, 113);
        $this->history('2026-09-06', 'Removal of Credit from Invoice #113', -90, 113);
        $this->payment(23, 113, 0, out: 90, refundOf: 22, date: '2026-09-06 10:00:00');
        $this->balance(0);

        $this->import();

        $payment = InvoiceTransaction::query()->findOrFail(22);
        $this->assertSame(['90.00', '0.00'], [(string) $payment->refunded_amount, (string) $payment->credited_amount]);
        $this->assertSame([['payment', null, '60.00'], ['payment', null, '90.00'], ['credit_in', 'overpayment', '90.00'],
            ['credit_out', 'overpayment', '90.00'], ['refund', null, '90.00']], $this->journal());
        $this->assertSame(0, DB::table('adjustment_notes')->count(), 'the invoice kept its 60');
        $this->assertChecksPass();
    }
}
