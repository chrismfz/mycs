<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\User;
use App\Providers\SettingsProvider;
use App\Services\Import\WhmcsCustomFieldImport;
use App\Services\Invoice\RecordPaymentService;
use Carbon\Carbon;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Adversarial verification, flow «f-tax-documents» (sweep 2, 2026-11-07). Each test fails for the finding it names.
 */
class Sweep2FTaxDocumentsTest extends TestCase
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

    private function invoice(): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->clientId, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => 10, 'description' => 'Hosting']);

        return $invoice->fresh();
    }

    /** Paid proformas of 2026 already numbered 2026-1 .. 2026-$last (as a year of real invoices). */
    private function numbered2026(int $last): void
    {
        $now = now();
        foreach (array_chunk(range(1, $last), 250) as $chunk) {
            DB::table('invoices')->insert(array_map(fn (int $n) => ['client_id' => $this->clientId, 'number' => "2026-{$n}",
                'status' => Invoice::STATUS_PAID, 'currency_code' => 'USD', 'created_at' => $now, 'updated_at' => $now], $chunk));
        }
    }

    /**
     * nixpal on 1 January 2027: the counter set back to 0 (Settings allows it: «a new {year} restarts the count»), 2027-1
     * and 2027-2 issued; then a proforma paid by a transfer of 31/12/2026 (its year is the payment's, ADV-1 of #238).
     */
    private function newYearWithBackdatedProforma(int $last2026): Invoice
    {
        $this->settings(['invoice_proforma' => [true, 'boolean'], 'invoice_number_format' => ['{year}-{number}', 'string'],
            'invoice_number' => [$last2026, 'string']]);
        $this->numbered2026($last2026);
        Carbon::setTestNow('2026-12-30 10:00:00');
        $backdated = $this->invoice();

        Carbon::setTestNow('2027-01-01 09:00:00');
        $this->settings(['invoice_number' => [0, 'string']]);
        foreach ([1, 2] as $n) {
            $paid = $this->invoice();
            (new RecordPaymentService)->recordByStaff($paid, null, 10, "tx-2027-{$n}");
            $this->assertSame("2027-{$n}", $paid->fresh()->number, 'precondition: the new year counts from 1');
        }
        $this->assertSame(2, $this->counter());

        Carbon::setTestNow('2027-01-02 09:00:00');

        return $backdated;
    }

    /**
     * F-TAX-DOCUMENTS-1 (gap). The backdated payment walks 2026-3, 2026-4, … to the first free 2026 number and saves that
     * as the counter: the next 2027 invoice jumps past 2027-3 .. 2027-6, numbers never issued.
     */
    public function test_f1_a_backdated_proforma_after_a_yearly_reset_leaves_no_gap_in_the_new_year(): void
    {
        $backdated = $this->newYearWithBackdatedProforma(5);

        (new RecordPaymentService)->recordByStaff($backdated, null, 10, 'bank-3112', paidAt: Carbon::parse('2026-12-31 15:00:00'));
        $this->assertSame('2026-6', $backdated->fresh()->number, 'precondition: the year of its payment, its first free number');

        $next = $this->invoice();
        (new RecordPaymentService)->recordByStaff($next, null, 10, 'tx-2027-3');

        $this->assertSame('2027-3', $next->fresh()->number, 'the 2027 sequence goes on from 2027-2, no hole');
    }

    /**
     * F-TAX-DOCUMENTS-1 (unique key). With more than 1000 numbers of 2026 past the counter, the loop gives up on a number
     * that is taken and assigns it anyway: the payment fails on invoices_number_unique, and every retry the same way.
     */
    public function test_f1_a_backdated_proforma_payment_never_fails_on_the_unique_key(): void
    {
        $backdated = $this->newYearWithBackdatedProforma(1005);

        (new RecordPaymentService)->recordByStaff($backdated, null, 10, 'bank-3112', paidAt: Carbon::parse('2026-12-31 15:00:00'));

        $backdated = $backdated->fresh();
        $this->assertSame(Invoice::STATUS_PAID, $backdated->status);
        $this->assertSame(1, Invoice::query()->where('number', $backdated->number)->count());
    }

    /**
     * F-TAX-DOCUMENTS-2. WHMCS deleted its top invoice(s): an orphan line and a payment still name invoice 12, while
     * MAX(tblinvoices.id) is 10. The importer takes 10 as the highest number given (the counter and, on MariaDB, the
     * AUTO_INCREMENT both come from it), so 11 and 12 are given again to new invoices (number = id on myip).
     */
    public function test_f2_the_ids_of_deleted_whmcs_invoices_are_never_given_again(): void
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
        $pdo->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");
        $pdo->exec("INSERT INTO tblclients VALUES ({$this->clientId}, 1)");
        $pdo->exec("INSERT INTO tblinvoices VALUES (10, {$this->clientId}, '', '2026-09-01', '2026-09-10', '2026-09-03', 'Paid', 10, 0, 0)");
        $pdo->exec("INSERT INTO tblinvoiceitems VALUES (10, 10, {$this->clientId}, 'Hosting', 0, 'Line', 10, 0)");
        // Invoice 12, deleted in WHMCS after its line and its payment were written.
        $pdo->exec("INSERT INTO tblinvoiceitems VALUES (11, 12, {$this->clientId}, 'AddFunds', 0, 'Add Funds', 10, 0)");
        $pdo->exec("INSERT INTO tblaccounts VALUES (1, {$this->clientId}, 12, 'banktransfer', '2026-09-05', 10, 0, 0, 'ref-12')");

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $pdo);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new ReflectionMethod($command, 'importInvoices'))->invoke($command);

        $this->assertGreaterThanOrEqual(12, $this->counter(), 'invoice 12 existed in WHMCS (its line and payment say so): never given again');
    }

    /**
     * F-TAX-DOCUMENTS-4. nixpal keeps an EU VAT number in tblclients.tax_id; the importer writes it to contacts.tax_id
     * (the ΑΦΜ) only, and no custom field moves it: vat_number stays empty, so VIES never checks it and no reverse charge
     * can ever apply (docs/eu-vat.md «Τι μένει» lists it).
     */
    public function test_f4_an_eu_vat_number_in_whmcs_tax_id_reaches_vat_number(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $pdo->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR')");
        $pdo->exec('CREATE TABLE tblusers (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, password TEXT,
            second_factor TEXT, second_factor_config TEXT, email_verified_at TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT,
            tax_id TEXT, phonenumber TEXT, address1 TEXT, address2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT,
            taxexempt INT, separateinvoices INT, disableautocc INT, credit REAL, currency INT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE tblusers_clients (id INTEGER PRIMARY KEY, auth_user_id INT, client_id INT, owner INT, permissions TEXT,
            last_login TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, packageid INT, domainstatus TEXT)');
        $pdo->exec('CREATE TABLE tblcustomfields (id INTEGER PRIMARY KEY, type TEXT, relid INT, fieldname TEXT, fieldtype TEXT)');
        $pdo->exec('CREATE TABLE tblcustomfieldsvalues (id INTEGER PRIMARY KEY, fieldid INT, relid INT, value TEXT)');
        $pdo->prepare('INSERT INTO tblusers VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([901, 'First', 'Last', 'login901@example.test', Hash::make('Whmcs-Pass-1'), '', null, null, '2024-01-01 00:00:00', '2025-01-01 00:00:00']);
        $pdo->prepare("INSERT INTO tblclients VALUES (901, 'First', 'Last', '', 'billing901@example.test', 'DE123456789', NULL, NULL, NULL,
            NULL, NULL, NULL, 'DE', 1, 0, 0, 0, 1, '2024-01-01 00:00:00', '2025-01-01 00:00:00')")->execute();
        $pdo->exec('INSERT INTO tblusers_clients (auth_user_id, client_id, owner) VALUES (901, 901, 1)');

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $pdo);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new ReflectionMethod($command, 'importUsers'))->invoke($command);
        (new WhmcsCustomFieldImport($pdo))->run();

        $contact = Contact::query()->where('primary_of_client_id', 901)->sole();
        $this->assertSame('DE123456789', $contact->tax_id, 'precondition: it is in the ΑΦΜ field');
        $this->assertSame('DE123456789', $contact->vat_number, 'the EU VAT number is the one VIES and the reverse charge read');
    }
}
