<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Exceptions\DisplayException;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentGroup;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use Throwable;

/**
 * PR F3 adversarial review: WHMCS Mass Pay parents in the importer, against inconsistent or unusual WHMCS data.
 * Synthetic data only (no real customers). Failing tests are findings (F3-ADV-n); test_held_* are attacks that hold.
 */
class ImportMassPayAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private BufferedOutput $output;

    private int $clientId;

    private int $otherClientId;

    private int $itemId = 5000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clientId = User::factory()->create()->client()->id;
        $this->otherClientId = User::factory()->create()->client()->id;
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $this->whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT)');
        $this->whmcs->exec('INSERT INTO tblcurrencies VALUES (1, \'EUR\')');
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$this->clientId}, 1)");
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$this->otherClientId}, 1)");
    }

    private function invoice(int $id, string $status, float $total, float $credit = 0, string $due = '2026-09-10', ?int $client = null): void
    {
        $client ??= $this->clientId;
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES ({$id}, {$client}, '', '2026-09-01', '{$due}', '2026-09-03', '{$status}', {$total}, {$credit}, 0)");
    }

    private function item(int $invoice, string $type, int $relid, float $amount, ?int $client = null): void
    {
        $client ??= $this->clientId;
        $id = ++$this->itemId;
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES ({$id}, {$invoice}, {$client}, '{$type}', {$relid}, 'Line {$id}', {$amount}, 0)");
    }

    private function payment(int $id, int $invoice, float $in, float $out = 0, string $date = '2026-09-03 10:00:00'): void
    {
        $this->whmcs->exec("INSERT INTO tblaccounts VALUES ({$id}, {$this->clientId}, {$invoice}, 'stripe', '{$date}', {$in}, {$out}, 0, 'ch_{$id}')");
    }

    /** A child invoice of $total with one line, paid in WHMCS from the Mass Pay credit ($credit). */
    private function child(int $id, float $total, float $credit, string $due = '2026-09-10', ?int $client = null, string $status = 'Paid'): void
    {
        $this->invoice($id, $status, $total, $credit, $due, $client);
        $this->item($id, 'Hosting', 0, $total, $client);
    }

    private function import(?int $batchSize = null): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        if ($batchSize !== null) {
            (new ReflectionProperty($command, 'batchSize'))->setValue($command, $batchSize);
        }
        $this->output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));
        foreach (['importInvoices', 'importInvoiceItems', 'importPayments', 'importMassPay'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
    }

    private function remaining(int $invoiceId): string
    {
        return number_format((float) Invoice::query()->findOrFail($invoiceId)->remaining, 2, '.', '');
    }

    /** Money left as credit on a payment that no allocation used (what the payment says became account credit). */
    private function unallocated(int $paymentId): int
    {
        $payment = InvoiceTransaction::query()->findOrFail($paymentId);

        return RecordPaymentService::toCents($payment->credited_amount)
            - (int) InvoiceTransaction::query()->where('source_transaction_id', $paymentId)->get()->sum(fn ($t) => RecordPaymentService::toCents($t->amount));
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Findings
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * F3-ADV-1: the allocations get auto-increment ids while the later parents' payments are inserted with their WHMCS
     * ids afterwards. When the newest WHMCS payments are Mass Pay payments (9000, 9001), parent 900's allocation takes
     * id 9001 and parent 901's payment 9001 then collides: the import aborts half-way (no transaction, no try/catch).
     */
    public function test_adv1_allocation_ids_do_not_collide_with_a_later_parents_payment(): void
    {
        $this->child(801, 10, 10, '2026-09-12');
        $this->child(802, 15, 15, '2026-09-11');
        $this->invoice(900, 'Paid', 25);
        $this->item(900, 'Invoice', 801, 10);
        $this->item(900, 'Invoice', 802, 15);
        $this->payment(9000, 900, 25);

        $this->child(803, 10, 10);
        $this->invoice(901, 'Paid', 10);
        $this->item(901, 'Invoice', 803, 10);
        $this->payment(9001, 901, 10, 0, '2026-09-04 10:00:00');

        try {
            $this->import();
        } catch (Throwable $e) {
            $this->fail('The import aborted: ' . $e->getMessage());
        }

        $payment = InvoiceTransaction::query()->findOrFail(9001);
        $this->assertSame([803, '10.00', null], [(int) $payment->invoice_id, (string) $payment->amount, $payment->source_transaction_id],
            'payment 9001 is the WHMCS payment of parent 901, not an allocation of 9000');
        $this->assertSame('0.00', $this->remaining(801));
        $this->assertSame('0.00', $this->remaining(803));
    }

    /**
     * F3-ADV-2: a child in two paid parents. WHMCS applied the Mass Pay credit to it once (credit 10); the second
     * parent's money went to account credit (tblclients.credit, imported as the balance). Each parent takes
     * min(line, child credit) on its own, so the child is paid twice: 10.00 paid over, the second payment counted both
     * on the child and in the imported balance.
     */
    public function test_adv2_a_child_in_two_paid_parents_is_not_paid_twice(): void
    {
        $this->child(801, 10, 10);
        $this->invoice(900, 'Paid', 10);
        $this->item(900, 'Invoice', 801, 10);
        $this->payment(7001, 900, 10);
        $this->invoice(901, 'Paid', 10);
        $this->item(901, 'Invoice', 801, 10);
        $this->payment(7002, 901, 10, 0, '2026-09-05 10:00:00');

        $this->import();

        $this->assertSame('0.00', $this->remaining(801), 'the child is paid once, not paid over');
    }

    /** F3-ADV-2 (b): the same child twice in one parent: shares are summed per line, each capped only by the child's credit. */
    public function test_adv2b_a_child_listed_twice_in_one_parent_is_not_paid_twice(): void
    {
        $this->child(801, 10, 10);
        $this->invoice(900, 'Paid', 20);
        $this->item(900, 'Invoice', 801, 10);
        $this->item(900, 'Invoice', 801, 10);
        $this->payment(7001, 900, 20);

        $this->import();

        $this->assertSame('0.00', $this->remaining(801), 'the child is paid once, not paid over');
    }

    /**
     * F3-ADV-3: a parent of customer A whose «Invoice» line points at customer B's invoice (inconsistent WHMCS data, or
     * a merged/moved account). The importer never compares customers: A's payment lands on B's invoice and the paid
     * group (client A) lists B's invoice.
     */
    public function test_adv3_a_parent_never_pays_another_customers_invoice(): void
    {
        $this->child(801, 10, 10, '2026-09-10', $this->otherClientId);
        $this->child(802, 15, 15, '2026-09-11');
        $this->invoice(900, 'Paid', 25);
        $this->item(900, 'Invoice', 801, 10);
        $this->item(900, 'Invoice', 802, 15);
        $this->payment(7001, 900, 25);

        $this->import();

        foreach (InvoiceTransaction::query()->where('id', 7001)->orWhere('source_transaction_id', 7001)->get() as $transaction) {
            $this->assertSame($this->clientId, (int) Invoice::query()->findOrFail($transaction->invoice_id)->client_id,
                "transaction #{$transaction->id} of customer A's payment sits on another customer's invoice #{$transaction->invoice_id}");
        }
        $group = PaymentGroup::query()->where('whmcs_invoice_id', 900)->first();
        if ($group !== null) {
            $this->assertSame([$this->clientId], $group->invoices->pluck('client_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
                'a group only holds its own customer\'s invoices');
        }
    }

    /**
     * F3-ADV-4: a parent counts as a parent when a child row exists in WHMCS, but the payment only lands on a child that
     * was imported. A nested parent (a child that is itself a Mass Pay parent, so never an invoice here) leaves the
     * outer parent with no child: neither an invoice nor a group, its payment dropped with only a warning.
     */
    public function test_adv4_a_payment_on_a_parent_of_a_parent_is_not_lost(): void
    {
        $this->child(801, 10, 10);
        $this->invoice(850, 'Paid', 10, 10); // inner parent, paid from the credit the outer one gave
        $this->item(850, 'Invoice', 801, 10);
        $this->invoice(900, 'Paid', 10);
        $this->item(900, 'Invoice', 850, 10);
        $this->payment(7001, 900, 10);

        $this->import();

        $this->assertNotNull(InvoiceTransaction::query()->find(7001), 'the customer paid 10.00 (payment 7001): it is somewhere');
    }

    /**
     * F3-ADV-4 (b): the same when the only child belongs to a customer that was not imported (no currency, or a deleted
     * client): the parent is no invoice (a child row exists) and no group (no child imported). Unlike a parent whose
     * children were deleted, its payment is dropped.
     */
    public function test_adv4b_a_payment_whose_children_were_not_imported_is_not_lost(): void
    {
        // No tblclients row: not imported (its lines are left out here only because the sqlite test enforces foreign keys).
        $this->invoice(801, 'Paid', 10, 10, '2026-09-10', 424242);
        $this->invoice(900, 'Paid', 10);
        $this->item(900, 'Invoice', 801, 10);
        $this->payment(7001, 900, 10);

        $this->import();

        $this->assertNotNull(InvoiceTransaction::query()->find(7001), 'the customer paid 10.00 (payment 7001): it is somewhere');
    }

    /**
     * F3-ADV-5: an unpaid parent with a partial payment (WHMCS takes part payments on a Mass Pay invoice; also
     * «Payment Pending», «Collections»). Neither the parent nor its payment is imported: 10.00 the customer paid
     * disappears, only a warning says so.
     */
    public function test_adv5_a_partial_payment_on_an_unpaid_parent_is_not_lost(): void
    {
        $this->child(801, 10, 0, '2026-09-12', null, 'Unpaid');
        $this->child(802, 15, 0, '2026-09-11', null, 'Unpaid');
        $this->invoice(900, 'Unpaid', 25);
        $this->item(900, 'Invoice', 801, 10);
        $this->item(900, 'Invoice', 802, 15);
        $this->payment(7001, 900, 10);

        $this->import();

        $this->assertNotNull(InvoiceTransaction::query()->find(7001), 'the customer paid 10.00 (payment 7001): it is somewhere');
    }

    /**
     * F3-ADV-6: a paid parent with a line that is not an «Invoice» (staff added a fee or a service to it). All the
     * parent's lines are dropped, so the 5.00 the customer paid for that line shows on the payment as money that became
     * account credit (credited_amount, not allocated), though WHMCS consumed it and never credited it: the payment
     * claims credit that is in no balance, and deleting it later takes 5.00 of real credit from the customer.
     */
    public function test_adv6_a_non_invoice_line_on_a_parent_does_not_become_phantom_credit(): void
    {
        $this->child(801, 10, 10, '2026-09-12');
        $this->child(802, 15, 15, '2026-09-11');
        $this->invoice(900, 'Paid', 30);
        $this->item(900, 'Invoice', 801, 10);
        $this->item(900, 'Invoice', 802, 15);
        $this->item(900, 'LateFee', 0, 5);
        $this->payment(7001, 900, 30);

        $this->import();

        $this->assertSame(0, $this->unallocated(7001), 'the 5.00 line was paid, not turned into account credit');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Held
    // ---------------------------------------------------------------------------------------------------------------

    /** An overpayment stays on the payment as credit (WHMCS put it in the account balance): nothing paid over. */
    public function test_held_an_overpayment_is_credit_and_no_child_is_paid_over(): void
    {
        $this->child(801, 10, 10, '2026-09-12');
        $this->child(802, 15, 15, '2026-09-11');
        $this->invoice(900, 'Paid', 25);
        $this->item(900, 'Invoice', 801, 10);
        $this->item(900, 'Invoice', 802, 15);
        $this->payment(7001, 900, 30);

        $this->import();

        $this->assertSame(['0.00', '0.00'], [$this->remaining(801), $this->remaining(802)]);
        $this->assertSame(500, $this->unallocated(7001));
    }

    /** Two payments and account credit on one parent: each child paid exactly once, in due order, nothing left over. */
    public function test_held_several_payments_and_credit_pay_each_child_once(): void
    {
        $this->child(801, 10, 10, '2026-09-12');
        $this->child(802, 15, 15, '2026-09-11');
        $this->child(803, 5, 5, '2026-09-13');
        $this->invoice(900, 'Paid', 30, 5);
        $this->item(900, 'Invoice', 801, 10);
        $this->item(900, 'Invoice', 802, 15);
        $this->item(900, 'Invoice', 803, 5);
        $this->payment(7001, 900, 12);
        $this->payment(7002, 900, 13, 0, '2026-09-04 10:00:00');

        $this->import();

        $this->assertSame(['0.00', '0.00', '0.00'], [$this->remaining(801), $this->remaining(802), $this->remaining(803)]);
        $this->assertSame([0, 0], [$this->unallocated(7001), $this->unallocated(7002)]);
    }

    /** Small batches (LIMIT/OFFSET): parent lines and payments are skipped in every batch, children kept in every batch. */
    public function test_held_batches_skip_the_parent_everywhere(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->child(801 + $i, 10, 10, '2026-09-1' . $i);
        }
        $this->invoice(900, 'Paid', 60);
        for ($i = 0; $i < 6; $i++) {
            $this->item(900, 'Invoice', 801 + $i, 10);
        }
        $this->payment(7001, 900, 60);
        $this->payment(7000, 801, 0); // another row so tblaccounts has more than a batch
        $this->payment(6999, 802, 0);

        $this->import(2);

        $this->assertNull(Invoice::query()->find(900));
        $this->assertSame(0, \App\Models\InvoiceItem::query()->where('invoice_id', 900)->count());
        for ($i = 0; $i < 6; $i++) {
            $this->assertSame('0.00', $this->remaining(801 + $i));
        }
    }

    /** A child that is not paid in WHMCS takes nothing; the money it would have had stays credit, no child paid over. */
    public function test_held_an_unpaid_child_takes_nothing(): void
    {
        $this->child(801, 10, 0, '2026-09-10', null, 'Unpaid');
        $this->child(802, 15, 15, '2026-09-11');
        $this->invoice(900, 'Paid', 25);
        $this->item(900, 'Invoice', 801, 10);
        $this->item(900, 'Invoice', 802, 15);
        $this->payment(7001, 900, 25);

        $this->import();

        $this->assertSame(['10.00', '0.00'], [$this->remaining(801), $this->remaining(802)]);
        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->findOrFail(801)->status);
    }

    /** Runtime: the imported payment paid other children, so staff cannot delete it and leave the allocations orphaned. */
    public function test_held_the_imported_payment_cannot_be_deleted_under_its_allocations(): void
    {
        $this->child(801, 10, 10, '2026-09-12');
        $this->child(802, 15, 15, '2026-09-11');
        $this->invoice(900, 'Paid', 25);
        $this->item(900, 'Invoice', 801, 10);
        $this->item(900, 'Invoice', 802, 15);
        $this->payment(7001, 900, 25);
        $this->import();

        $this->expectException(DisplayException::class);
        (new RecordPaymentService)->deleteTransaction(InvoiceTransaction::query()->findOrFail(7001));
    }

    /** A negative or zero payment row on a parent brings no money and creates none. */
    public function test_held_zero_and_negative_rows_bring_nothing(): void
    {
        $this->child(801, 10, 10);
        $this->invoice(900, 'Paid', 10, 10);
        $this->item(900, 'Invoice', 801, 10);
        $this->payment(7001, 900, 0);
        $this->payment(7002, 900, -10);

        $this->import();

        $this->assertNull(InvoiceTransaction::query()->find(7001));
        $this->assertNull(InvoiceTransaction::query()->find(7002));
        $this->assertSame('0.00', $this->remaining(801));
    }
}
