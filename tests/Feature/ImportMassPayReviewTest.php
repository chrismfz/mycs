<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Exceptions\DisplayException;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentGroup;
use App\Models\User;
use App\Services\Invoice\MassPay;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Independent code review of PR F3 (WHMCS Mass Pay parents in the importer). Failing tests are findings (CR-n); tests
 * ending in _guard pin behaviour that is correct.
 */
class ImportMassPayReviewTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private BufferedOutput $output;

    private int $clientId;

    private int $itemId = 6000;

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
            amountout REAL, fees REAL, transid TEXT)');
        $this->whmcs->exec('INSERT INTO tblcurrencies VALUES (1, \'EUR\')');
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$this->clientId}, 1)");
    }

    private function invoice(int $id, string $status, float $total, float $credit = 0, string $due = '2026-09-10'): void
    {
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES ({$id}, {$this->clientId}, '', '2026-09-01', '{$due}', '2026-09-03', '{$status}', {$total}, {$credit}, 0)");
    }

    private function item(int $invoice, string $type, int $relid, float $amount): void
    {
        $id = ++$this->itemId;
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES ({$id}, {$invoice}, {$this->clientId}, '{$type}', {$relid}, 'Line', {$amount}, 0)");
    }

    private function payment(int $id, int $invoice, float $in, float $out = 0, string $date = '2026-09-03 10:00:00'): void
    {
        $this->whmcs->exec("INSERT INTO tblaccounts VALUES ({$id}, {$this->clientId}, {$invoice}, 'stripe', '{$date}', {$in}, {$out}, 0, 'ch_{$id}')");
    }

    /** A child invoice with one hosting line. */
    private function child(int $id, string $status, float $total, float $credit, string $due = '2026-09-10'): void
    {
        $this->invoice($id, $status, $total, $credit, $due);
        $this->item($id, 'Hosting', 0, $total);
    }

    private function import(): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $this->output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));
        foreach (['importInvoices', 'importInvoiceItems', 'importPayments', 'importMassPay'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
    }

    /** What is on an invoice (its transactions net of what became credit), in cents. */
    private function onInvoice(int $invoiceId): int
    {
        return (int) InvoiceTransaction::query()->where('invoice_id', $invoiceId)->get()
            ->sum(fn (InvoiceTransaction $t) => RecordPaymentService::toCents($t->amount) - RecordPaymentService::toCents($t->refunded_amount)
                - RecordPaymentService::toCents($t->credited_amount));
    }

    /**
     * CR-1: the allocation rows are inserted without an id while each parent is processed, so they take max(id)+1. When
     * a later parent's payment (its id kept from tblaccounts) is above every payment imported so far, as for the newest
     * Mass Pays at cut-over, its id is already taken by an allocation: duplicate key, the import stops.
     */
    public function test_cr1_allocation_ids_do_not_collide_with_a_later_parents_payment_id(): void
    {
        $this->child(801, 'Paid', 10, 10, '2026-09-12');
        $this->child(802, 'Paid', 15, 15, '2026-09-11');
        $this->child(803, 'Paid', 10, 10, '2026-09-12');
        $this->child(804, 'Paid', 15, 15, '2026-09-11');
        $this->invoice(900, 'Paid', 25);
        $this->item(900, 'Invoice', 801, 10);
        $this->item(900, 'Invoice', 802, 15);
        $this->invoice(901, 'Paid', 25);
        $this->item(901, 'Invoice', 803, 10);
        $this->item(901, 'Invoice', 804, 15);
        $this->child(799, 'Paid', 5, 0);
        $this->payment(7000, 799, 5); // an ordinary payment before them
        $this->payment(7001, 900, 25);
        $this->payment(7002, 901, 25);

        $this->import();

        $this->assertSame(804, (int) InvoiceTransaction::query()->findOrFail(7002)->invoice_id, 'the second Mass Pay payment keeps its id');
        $this->assertSame(2, InvoiceTransaction::query()->whereIn('source_transaction_id', [7001, 7002])->count());
    }

    /**
     * CR-2: a child in two paid parents (a customer re-created the Mass Pay and paid both). WHMCS applied credit to it
     * once (tblinvoices.credit = 10); the second payment stayed account credit, which is already in the imported
     * balance (tblclients.credit). The planner gives the child its full share from each parent: 20 on a 10 invoice,
     * and the second 10 is counted twice (on the invoice and in the credit balance).
     */
    public function test_cr2_a_child_in_two_paid_parents_is_paid_once(): void
    {
        $this->child(801, 'Paid', 10, 10);
        $this->invoice(900, 'Paid', 10);
        $this->item(900, 'Invoice', 801, 10);
        $this->invoice(901, 'Paid', 10);
        $this->item(901, 'Invoice', 801, 10);
        $this->payment(7001, 900, 10, 0, '2026-09-03 10:00:00');
        $this->payment(7002, 901, 10, 0, '2026-09-04 10:00:00');

        $this->import();

        $this->assertSame(1000, $this->onInvoice(801), 'WHMCS applied 10.00 to the child, not 20.00');
    }

    /**
     * CR-3: a parent that is not paid but has money on it (a partial payment, or one cancelled after a payment). Before
     * F3 it was imported as an invoice with its payment; now the payment is dropped with only a warning: the money is
     * gone from MyCS.
     */
    public function test_cr3_a_payment_on_an_unpaid_parent_is_not_lost(): void
    {
        $this->child(841, 'Unpaid', 10, 0);
        $this->child(842, 'Unpaid', 10, 0);
        $this->invoice(940, 'Unpaid', 20);
        $this->item(940, 'Invoice', 841, 10);
        $this->item(940, 'Invoice', 842, 10);
        $this->payment(7040, 940, 5);

        $this->import();

        $this->assertNotNull(InvoiceTransaction::query()->find(7040), 'a 5.00 payment received in WHMCS must still exist in MyCS');
    }

    /**
     * CR-4: the group's total is the parent's tblinvoices.total, not what its invoices hold. They differ whenever the
     * parent's total is not the sum of its lines (WHMCS keeps the total net of the credit applied to it, to be checked
     * on the real data: the myip «σύνολο ≠ γραμμές» cases). F2 treats total = Σ pivot amounts as the group's invariant.
     */
    public function test_cr4_the_groups_total_is_what_its_invoices_hold(): void
    {
        $this->child(851, 'Paid', 10, 10, '2026-09-11');
        $this->child(852, 'Paid', 15, 15, '2026-09-12');
        $this->invoice(950, 'Paid', 20, 5); // lines 25, 5 of it from account credit, 20 paid
        $this->item(950, 'Invoice', 851, 10);
        $this->item(950, 'Invoice', 852, 15);
        $this->payment(7050, 950, 20);

        $this->import();

        $this->assertSame(0, $this->onInvoice(851) + $this->onInvoice(852) - 2500, 'both children are paid in full');
        $group = PaymentGroup::query()->where('whmcs_invoice_id', 950)->sole();
        $this->assertSame(
            RecordPaymentService::toCents($group->total),
            (int) $group->invoices->sum(fn (Invoice $invoice) => RecordPaymentService::toCents($invoice->getRelation('pivot')->amount)),
        );
    }

    /** A child in a cancelled parent and in a paid one (a re-created Mass Pay): paid once, by the paid one. */
    public function test_a_child_in_a_cancelled_and_a_paid_parent_guard(): void
    {
        $this->child(861, 'Paid', 10, 10);
        $this->invoice(960, 'Cancelled', 10);
        $this->item(960, 'Invoice', 861, 10);
        $this->invoice(961, 'Paid', 10);
        $this->item(961, 'Invoice', 861, 10);
        $this->payment(7061, 961, 10);

        $this->import();

        $this->assertSame(1000, $this->onInvoice(861));
        $this->assertSame([961], PaymentGroup::query()->pluck('whmcs_invoice_id')->map(fn ($id) => (int) $id)->all());
        $this->assertNull(Invoice::query()->find(960));
    }

    /** Money in = money placed: each child is paid in full, nothing left on the parent's payment. */
    public function test_children_are_paid_in_full_and_the_money_adds_up_guard(): void
    {
        $this->child(871, 'Paid', 10, 10, '2026-09-12');
        $this->child(872, 'Paid', 15, 15, '2026-09-11');
        $this->child(873, 'Paid', 7.33, 7.33, '2026-09-11');
        $this->invoice(970, 'Paid', 32.33);
        $this->item(970, 'Invoice', 871, 10);
        $this->item(970, 'Invoice', 872, 15);
        $this->item(970, 'Invoice', 873, 7.33);
        $this->payment(7071, 970, 20, 0, '2026-09-03 10:00:00');
        $this->payment(7072, 970, 12.33, 0, '2026-09-04 10:00:00');

        $this->import();

        foreach ([871 => 1000, 872 => 1500, 873 => 733] as $id => $cents) {
            $this->assertSame($cents, $this->onInvoice($id), "child #{$id}");
            $this->assertSame(0.0, (float) Invoice::query()->findOrFail($id)->remaining, "child #{$id} has nothing left");
        }
        $payments = InvoiceTransaction::query()->whereIn('id', [7071, 7072])->get();
        $allocated = InvoiceTransaction::query()->whereIn('source_transaction_id', [7071, 7072])->get()->sum(fn ($t) => RecordPaymentService::toCents($t->amount));
        $this->assertSame((int) $payments->sum(fn ($t) => RecordPaymentService::toCents($t->credited_amount)), (int) $allocated, 'what became credit is exactly what was allocated');
        $this->assertSame(['2026-09-03', '2026-09-04'], $payments->sortBy('id')->map(fn ($t) => $t->created_at->toDateString())->values()->all());
    }

    /** An imported paid group never meets the runtime Mass Pay code: not a group paid by its payment, stale for PayTogether. */
    public function test_an_imported_paid_group_does_not_interfere_with_the_runtime_code_guard(): void
    {
        $this->child(881, 'Paid', 10, 10, '2026-09-12');
        $this->child(882, 'Paid', 15, 15, '2026-09-11');
        $this->invoice(980, 'Paid', 25);
        $this->item(980, 'Invoice', 881, 10);
        $this->item(980, 'Invoice', 882, 15);
        $this->payment(7081, 980, 25);

        $this->import();

        $lead = Invoice::query()->findOrFail(882);
        $payment = InvoiceTransaction::query()->findOrFail(7081);
        $group = PaymentGroup::query()->where('whmcs_invoice_id', 980)->sole();
        $this->assertNull(MassPay::groupPaidBy($lead, $group->ulid, $payment));
        $this->assertNotNull(MassPay::staleness($group));

        // The payment paid another invoice: it cannot be deleted on its own.
        $this->expectException(DisplayException::class);
        (new RecordPaymentService)->deleteTransaction($payment);
    }
}
