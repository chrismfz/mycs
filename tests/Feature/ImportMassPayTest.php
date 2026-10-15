<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentGroup;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * PR F3: WHMCS Mass Pay parents in the importer. The parent is not an invoice here; its payment lands on the child due
 * first and pays the others as allocations; it stays a paid payment group with its WHMCS id.
 */
class ImportMassPayTest extends TestCase
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
        static $id = 5000;
        $id++;
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES ({$id}, {$invoice}, {$this->clientId}, '{$type}', {$relid}, 'Line', {$amount}, 0)");
    }

    private function payment(int $id, int $invoice, float $in, float $out = 0): void
    {
        $this->whmcs->exec("INSERT INTO tblaccounts VALUES ({$id}, {$this->clientId}, {$invoice}, 'stripe', '2026-09-03 10:00:00', {$in}, {$out}, 0, 'ch_{$id}')");
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

    /** Two children of 10.00 (due the 12th) and 15.00 (due the 11th), paid with one 25.00 payment on parent 900. */
    private function paidParent(): void
    {
        $this->invoice(801, 'Paid', 10, 10, '2026-09-12');
        $this->item(801, 'Hosting', 0, 10);
        $this->invoice(802, 'Paid', 15, 15, '2026-09-11');
        $this->item(802, 'Hosting', 0, 15);
        $this->invoice(900, 'Paid', 25);
        $this->item(900, 'Invoice', 801, 10);
        $this->item(900, 'Invoice', 802, 15);
        $this->payment(7001, 900, 25);
    }

    public function test_the_parent_is_not_an_invoice_and_its_payment_pays_the_children(): void
    {
        $this->paidParent();

        $this->import();

        $this->assertNull(Invoice::query()->find(900), 'a Mass Pay parent holds no value of its own');
        $payment = InvoiceTransaction::query()->findOrFail(7001);
        $this->assertSame([802, '25.00', '10.00'], [(int) $payment->invoice_id, (string) $payment->amount, (string) $payment->credited_amount],
            'on the child due first, the rest as what it could not take');
        $allocation = InvoiceTransaction::query()->where('source_transaction_id', 7001)->sole();
        $this->assertSame([801, '10.00', true], [(int) $allocation->invoice_id, (string) $allocation->amount, (bool) $allocation->is_credit_transaction]);
        $this->assertSame('2026-09-03', $allocation->created_at->toDateString(), 'dated as the payment');

        $group = PaymentGroup::query()->where('whmcs_invoice_id', 900)->sole();
        $this->assertSame([PaymentGroup::STATUS_PAID, 802, 7001, '25.00', 'EUR'],
            [$group->status, (int) $group->invoice_id, (int) $group->invoice_transaction_id, (string) $group->total, $group->currency_code]);
        $this->assertSame([802, 801], $group->invoices->pluck('id')->all());
    }

    public function test_a_new_invoice_never_takes_a_parents_number(): void
    {
        $this->paidParent();

        $this->import();

        $this->assertSame(901, (int) Setting::query()->where('key', 'invoice_number')->value('value'));
    }

    public function test_a_cancelled_parent_is_dropped_and_its_children_stay_as_they_are(): void
    {
        $this->invoice(811, 'Unpaid', 10, 0);
        $this->item(811, 'Hosting', 0, 10);
        $this->invoice(910, 'Cancelled', 10);
        $this->item(910, 'Invoice', 811, 10);

        $this->import();

        $this->assertNull(Invoice::query()->find(910));
        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->findOrFail(811)->status);
        $this->assertSame(0, PaymentGroup::query()->count());
    }

    public function test_a_parent_paid_from_credit_and_a_refund_on_it_are_reported(): void
    {
        $this->invoice(821, 'Paid', 10, 10);
        $this->item(821, 'Hosting', 0, 10);
        $this->invoice(920, 'Paid', 10, 10);
        $this->item(920, 'Invoice', 821, 10);
        $this->payment(7020, 920, 0, 5);

        $this->import();

        $credit = InvoiceTransaction::query()->where('invoice_id', 821)->sole();
        $this->assertSame(['10.00', true, null], [(string) $credit->amount, (bool) $credit->is_credit_transaction, $credit->source_transaction_id]);
        $this->assertNull(InvoiceTransaction::query()->find(7020), 'a refund on a parent is left to staff');
        $this->assertStringContainsString('refund 7020', $this->output->fetch());
    }

    /** A parent whose children were all deleted in WHMCS stays an invoice with its payment: no money is lost. */
    public function test_a_parent_without_children_is_imported_as_an_invoice(): void
    {
        $this->invoice(930, 'Paid', 12);
        $this->item(930, 'Invoice', 999, 12);
        $this->payment(7030, 930, 12);

        $this->import();

        $this->assertSame(Invoice::STATUS_PAID, Invoice::query()->findOrFail(930)->status);
        $this->assertSame(930, (int) InvoiceTransaction::query()->findOrFail(7030)->invoice_id);
        $this->assertSame(0, PaymentGroup::query()->count());
    }
}
