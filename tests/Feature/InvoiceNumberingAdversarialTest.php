<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\User;
use App\Providers\SettingsProvider;
use App\Services\Invoice\PayInvoices;
use App\Services\Invoice\RecordPaymentService;
use Carbon\Carbon;
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
 * Adversarial review of PR #238 (Δ2 numbering). Failing tests are findings; test_holds_… pin behaviour that is right.
 */
class InvoiceNumberingAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clientId = User::factory()->create()->client()->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, array{0: mixed, 1: string}>  $settings */
    private function settings(array $settings): void
    {
        foreach ($settings as $key => [$value, $type]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $type]);
        }
        SettingsProvider::reload();
    }

    private function counter(): int
    {
        return (int) Setting::query()->where('key', 'invoice_number')->whereNull('settingable_type')->value('value');
    }

    private function proforma(int $counter = 100): void
    {
        $this->settings(['invoice_proforma' => [true, 'boolean'], 'invoice_number_format' => ['{year}-{number}', 'string'], 'invoice_number' => [$counter, 'string']]);
    }

    private function invoice(string $status = Invoice::STATUS_UNPAID, float $price = 10): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->clientId, 'status' => $status, 'currency_code' => 'USD']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => $price, 'description' => 'Hosting']);

        return $invoice->fresh();
    }

    private function pay(Invoice $invoice, float $amount = 10): Invoice
    {
        ExtensionHelper::addPayment($invoice->id, null, $amount, null, 'tx_' . $invoice->id . '_' . $amount, currency: 'USD');

        return $invoice->fresh();
    }

    // ---- Findings ----------------------------------------------------------------------------------------------------

    /**
     * ADV-1. A bank transfer of 31/12 recorded by staff on 02/01 (Mark Paid with its date): the proforma takes the year
     * the payment was recorded, not the year of the payment (Δ2: «το έτος της πληρωμής»). formatInvoiceNumber uses now().
     */
    public function test_a_backdated_payment_numbers_the_proforma_with_the_year_it_was_paid(): void
    {
        $this->proforma(6818);
        Carbon::setTestNow('2026-12-20 10:00:00');
        $invoice = $this->invoice();

        Carbon::setTestNow('2027-01-02 09:00:00');
        (new RecordPaymentService)->recordByStaff($invoice, null, 10, 'bank-3112', paidAt: Carbon::parse('2026-12-31 15:00:00'));

        $invoice = $invoice->fresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame('2026-6819', $invoice->number, 'paid on 31/12/2026: the year of its payment');
    }

    /**
     * ADV-2. By counter: an invoice created cancelled (API store accepts status=cancelled, the admin form too) takes a
     * number on creation: a number on a cancelled invoice and a gap. The listener's own doc: «a cancelled … never takes one».
     */
    public function test_an_invoice_created_cancelled_takes_no_number(): void
    {
        $this->settings(['invoice_number_format' => ['INV-{number}', 'string'], 'invoice_number' => [41, 'string']]);

        $cancelled = $this->invoice(Invoice::STATUS_CANCELLED);

        $this->assertNull($cancelled->number, 'never issued: no number');
        $this->assertSame(41, $this->counter(), 'no gap');
    }

    /**
     * ADV-3. Created without a status (the column default makes it a draft): Creating sees null, not «draft», and gives
     * it a number. A draft with a number, and the number lost if the draft is deleted.
     */
    public function test_an_invoice_created_without_a_status_is_a_draft_and_takes_no_number(): void
    {
        $this->settings(['invoice_number_format' => ['INV-{number}', 'string'], 'invoice_number' => [41, 'string']]);

        $invoice = Invoice::query()->create(['client_id' => $this->clientId, 'currency_code' => 'USD'])->fresh();

        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->status);
        $this->assertNull($invoice->number, 'a draft is not issued');
        $this->assertSame(41, $this->counter());
    }

    /**
     * ADV-4. number = id → counter (myip after the cut-over decides on a sequence, or the box is simply unticked): the
     * counter was never moved while is_id was on, so the next counter number is an id-number already used. The
     * collision happens in the payment's transaction: UniqueConstraintViolation after the gateway charged.
     */
    public function test_leaving_number_is_id_never_fails_a_payment_on_a_taken_number(): void
    {
        $this->settings(['invoice_number_is_id' => [true, 'boolean'], 'invoice_number_format' => ['{number}', 'string']]);
        $first = $this->invoice();
        $this->settings(['invoice_number' => [$first->id - 1, 'string']]); // as the importer or a fresh install leaves it
        $this->invoice(); // number = its id
        $this->invoice();

        $this->settings(['invoice_number_is_id' => [false, 'boolean'], 'invoice_proforma' => [true, 'boolean']]);
        $unpaid = $this->invoice();

        $paid = $this->pay($unpaid);
        $this->assertSame(Invoice::STATUS_PAID, $paid->status);
        $this->assertNotNull($paid->number);
        $this->assertSame(1, Invoice::query()->where('number', $paid->number)->count(), 'never a number given twice');
    }

    /**
     * ADV-5. counter → number = id: after the importer the counter is max id + 1, so counter numbers run one ahead of
     * the ids (invoice N is «N+1»). Ticking «number = id» then gives the next invoice (id N+1) a number already taken:
     * creating it fails (unique index) in Created.
     */
    public function test_switching_to_number_is_id_never_fails_creating_an_invoice(): void
    {
        $this->settings(['invoice_number_format' => ['{number}', 'string']]);
        $probe = $this->invoice(Invoice::STATUS_DRAFT); // learns the next id
        $this->settings(['invoice_number' => [$probe->id + 1, 'string']]);
        $byCounter = $this->invoice();
        $this->assertSame((string) ($byCounter->id + 1), $byCounter->number, 'precondition: counter numbers one ahead of the ids');

        $this->settings(['invoice_number_is_id' => [true, 'boolean']]);
        $next = $this->invoice();

        $this->assertNotNull($next->number);
        $this->assertSame(1, Invoice::query()->where('number', $next->number)->count());
    }

    // ---- The WHMCS importer --------------------------------------------------------------------------------------------

    private function whmcs(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $pdo->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $pdo->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        $pdo->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT)');
        $pdo->exec('INSERT INTO tblcurrencies VALUES (1, \'USD\')');
        $pdo->exec("INSERT INTO tblclients VALUES ({$this->clientId}, 1)");

        return $pdo;
    }

    private function whmcsInvoice(PDO $pdo, int $id, string $status, string $invoicenum = ''): void
    {
        $pdo->exec("INSERT INTO tblinvoices VALUES ({$id}, {$this->clientId}, '{$invoicenum}', '2026-09-01', '2026-09-10', '2026-09-03', '{$status}', 10, 0, 0)");
        $pdo->exec("INSERT INTO tblinvoiceitems VALUES ({$id}, {$id}, {$this->clientId}, 'Hosting', 0, 'Line', 10, 0)");
    }

    private function import(PDO $pdo): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $pdo);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        foreach (['importInvoices', 'importInvoiceItems'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
        SettingsProvider::reload();
    }

    /**
     * ADV-6 (myip). WHMCS without sequential numbering leaves invoicenum empty: the number is the id. Imported paid (or
     * cancelled, refunded) invoices never change status again, so the listener never numbers them: the whole history
     * of myip has no number although the setting says number = id. The PR leaves «continuation» to the importer, which
     * does not do it.
     */
    public function test_an_imported_invoice_is_numbered_by_its_id_when_number_is_id(): void
    {
        $this->settings(['invoice_number_is_id' => [true, 'boolean']]);
        $pdo = $this->whmcs();
        $this->whmcsInvoice($pdo, 32470, 'Paid');
        $this->whmcsInvoice($pdo, 32471, 'Unpaid');

        $this->import($pdo);

        $this->assertSame('32470', Invoice::query()->findOrFail(32470)->number, 'a paid invoice of WHMCS keeps its id as its number');
        $this->assertSame('32471', $this->pay(Invoice::query()->findOrFail(32471))->number);
    }

    /**
     * ADV-7 (nixpal). WHMCS proforma: the paid ones carry invoicenum «2026-6818», the unpaid ones none. The importer sets
     * the counter to max(id) + 1, not to the last WHMCS number: the next paid proforma jumps from 2026-6818 to
     * 2026-<id + 2>. CLAUDE.md «Αρίθμηση»: nixpal continues `{YEAR}-{NUMBER}`.
     */
    public function test_after_import_a_nixpal_proforma_continues_the_whmcs_sequence(): void
    {
        $this->proforma(0);
        Carbon::setTestNow('2026-10-20 10:00:00');
        $pdo = $this->whmcs();
        $this->whmcsInvoice($pdo, 9000, 'Paid', '2026-6818');
        $this->whmcsInvoice($pdo, 9001, 'Unpaid');

        $this->import($pdo);
        $paid = $this->pay(Invoice::query()->findOrFail(9001));

        $this->assertSame(Invoice::STATUS_PAID, $paid->status);
        $this->assertSame('2026-6819', $paid->number, 'continues from the last WHMCS number, no jump');
    }

    /**
     * ADV-8. The counter holds the last number used (the listener increments, then uses it), but the importer writes
     * «highest id + 1» into it: the first new number after the import skips one (max id 900 → 902; 901 is never given).
     */
    public function test_after_import_no_number_is_skipped(): void
    {
        $this->settings(['invoice_number_format' => ['{number}', 'string']]);
        $pdo = $this->whmcs();
        $this->whmcsInvoice($pdo, 900, 'Paid', '900');

        $this->import($pdo);
        $next = $this->invoice();

        $this->assertSame('901', $next->number);
    }

    /**
     * ADV-9. Two WHMCS invoices with the same invoicenum (WHMCS does not enforce it unique): the second is imported with
     * no number, silently. A paid invoice without a number, nobody told.
     */
    public function test_an_imported_paid_invoice_never_loses_its_number_silently(): void
    {
        $pdo = $this->whmcs();
        $this->whmcsInvoice($pdo, 700, 'Paid', '2025-10');
        $this->whmcsInvoice($pdo, 701, 'Paid', '2025-10');

        $this->import($pdo);

        $this->assertNotNull(Invoice::query()->findOrFail(701)->number, 'a paid invoice of WHMCS keeps a number (or the import stops and says why)');
    }

    /** ADV-10. A given number can be overwritten through the model (number is fillable, nothing guards it). */
    public function test_a_given_number_never_changes(): void
    {
        $this->proforma(10);
        $paid = $this->pay($this->invoice());
        $given = $paid->number;

        try {
            $paid->update(['number' => 'OTHER-1']);
        } catch (\Throwable) {
            // refused: fine
        }

        $this->assertSame($given, $paid->fresh()->number);
    }

    // ---- Holds -----------------------------------------------------------------------------------------------------------

    public function test_holds_a_partially_paid_proforma_takes_its_number_only_once_fully_paid(): void
    {
        $this->proforma(10);
        Carbon::setTestNow('2026-10-20 10:00:00');
        $invoice = $this->invoice();

        $invoice = $this->pay($invoice, 4);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertNull($invoice->number);
        $this->assertSame(10, $this->counter());

        $invoice = $this->pay($invoice, 6);
        $this->assertSame('2026-11', $invoice->number);
        $this->assertSame(11, $this->counter());
    }

    public function test_holds_an_overpaid_proforma_takes_one_number(): void
    {
        $this->proforma(10);
        Carbon::setTestNow('2026-10-20 10:00:00');

        $paid = $this->pay($this->invoice(), 25);

        $this->assertSame([Invoice::STATUS_PAID, '2026-11', 11], [$paid->status, $paid->number, $this->counter()]);
    }

    public function test_holds_a_payment_rolled_back_gives_its_number_back(): void
    {
        $this->proforma(10);
        Carbon::setTestNow('2026-10-20 10:00:00');
        $invoice = $this->invoice();

        try {
            DB::transaction(function () use ($invoice) {
                $this->pay($invoice);
                $this->assertSame('2026-11', $invoice->fresh()->number);
                throw new \RuntimeException('later step failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertNull($invoice->fresh()->number);
        $this->assertSame(10, $this->counter(), 'the counter rolled back with it');
        $this->assertSame('2026-11', $this->pay($invoice)->number, 'given now, once');
    }

    public function test_holds_one_staff_payment_of_several_proformas_numbers_each_once(): void
    {
        $this->proforma(10);
        Carbon::setTestNow('2026-10-20 10:00:00');
        $a = $this->invoice();
        $b = $this->invoice();

        (new PayInvoices)->recordByStaff([$a->id, $b->id], null, 20, 'bank-1');

        $numbers = [$a->fresh()->number, $b->fresh()->number];
        sort($numbers);
        $this->assertSame(['2026-11', '2026-12'], $numbers);
        $this->assertSame(12, $this->counter());
    }

    public function test_holds_a_paid_proforma_keeps_its_number_when_its_payment_is_deleted(): void
    {
        $this->proforma(10);
        Carbon::setTestNow('2026-10-20 10:00:00');
        $paid = $this->pay($this->invoice());

        (new RecordPaymentService)->deleteTransaction($paid->transactions()->sole());
        $this->assertSame(Invoice::STATUS_UNPAID, $paid->fresh()->status);
        $this->assertSame('2026-11', $paid->fresh()->number);

        $again = $this->pay($paid->fresh());
        $this->assertSame('2026-11', $again->number);
        $this->assertSame(11, $this->counter(), 'paid again: no second number');
    }

    public function test_holds_a_reopened_cancelled_proforma_is_numbered_when_paid(): void
    {
        $this->proforma(10);
        Carbon::setTestNow('2026-10-20 10:00:00');
        $invoice = $this->invoice();
        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);

        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, 10, 'bank-2', reopen: true);

        $this->assertSame([Invoice::STATUS_PAID, '2026-11'], [$invoice->fresh()->status, $invoice->fresh()->number]);
    }

    public function test_holds_number_is_id_never_numbers_a_draft_or_moves_the_counter(): void
    {
        $this->settings(['invoice_number_is_id' => [true, 'boolean'], 'invoice_number' => [5, 'string']]);
        $draft = $this->invoice(Invoice::STATUS_DRAFT);
        $this->assertNull($draft->number);

        $paid = $this->pay($this->invoice());

        $this->assertSame((string) $paid->id, $paid->number);
        $this->assertSame(5, $this->counter());
    }
}
