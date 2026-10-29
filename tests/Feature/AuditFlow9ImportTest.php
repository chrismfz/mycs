<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
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
 * Pre-launch audit, flow 9 (the WHMCS importer, main @ 67ad3c09): adversarial tests. Anonymised fixtures only.
 */
class AuditFlow9ImportTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clientId = User::factory()->create()->client()->id;
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblconfiguration (setting TEXT PRIMARY KEY, value TEXT)');
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $this->whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT)');
        $this->whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR')");
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$this->clientId}, 1)");
    }

    private function invoice(int $id, string $number, string $status, string $date, string $due, string $paid = '0000-00-00 00:00:00'): void
    {
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES ({$id}, {$this->clientId}, '{$number}', '{$date}', '{$due}', '{$paid}', '{$status}', 10, 0, 0)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, userid, type, relid, description, amount, taxed) VALUES ({$id}, {$this->clientId}, 'Item', 0, 'Line', 10, 0)");
    }

    private function import(): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        foreach (['importInvoices', 'importInvoiceItems'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
    }

    /**
     * AUD9-1 (High, numbering): nixpal numbers paid invoices `{YEAR}-{NUMBER}` from WHMCS's own counter
     * (SequentialInvoiceNumberValue, the next number: 6860 in the copy of 2026-10-29), apart from the ids (max 7269). The
     * importer sets MyCS's counter to the highest id, so the first invoice paid after the cut-over is 2026-7270: ~410
     * numbers skipped in the real (paid) invoices' sequence, which CLAUDE.md requires without a jump.
     */
    public function test_nixpals_next_paid_invoice_continues_whmcs_sequence_without_a_jump(): void
    {
        $this->whmcs->exec("INSERT INTO tblconfiguration VALUES ('SequentialInvoiceNumbering', '1'), ('SequentialInvoiceNumberFormat', '{YEAR}-{NUMBER}'),
            ('SequentialInvoiceNumberValue', '6860')");
        $year = now()->format('Y');
        $this->invoice(7268, '', 'Unpaid', now()->subDays(3)->toDateString(), now()->addDays(4)->toDateString());
        $this->invoice(7269, "{$year}-6859", 'Paid', now()->subDays(2)->toDateString(), now()->addDays(5)->toDateString(), now()->subDays(2)->toDateTimeString());

        $this->import();
        // nixpal's numbering, as settings-map.md sets it at the cut-over.
        $this->storeSettings(['invoice_number_format' => '{year}-{number}', 'invoice_proforma' => true, 'invoice_number_is_id' => false]);

        ExtensionHelper::addPayment(7268, null, 10.00, null, 'tx-aud9-1');
        $this->assertSame("{$year}-6860", Invoice::query()->findOrFail(7268)->number, 'the first invoice paid after the import takes the next number of WHMCS\'s sequence');
        $this->assertSame(6860, (int) Setting::query()->where('key', 'invoice_number')->whereNull('settingable_type')->value('value'),
            'the counter holds the last number given');
    }

    /**
     * AUD9-2 (High, data lost / lifecycle): WHMCS's `tblinvoices.duedate` is not imported; every invoice comes with
     * `due_at` null. An open invoice is then never overdue (Invoice::whereOverdue needs due_at), so PaymentReminders sends
     * none of the overdue notices (myip 1,3,5) or the «due in N days» one, while the cron still suspends and terminates
     * its service by `expires_at`: customers are cut off without the reminders WHMCS would have sent. Dunning's own rule
     * (COALESCE(due_at, created_at)) takes the issue date instead, 7 (nixpal) or 14 (myip) days early.
     */
    public function test_an_unpaid_invoices_due_date_comes_with_it(): void
    {
        $this->invoice(501, '', 'Unpaid', now()->subDays(20)->toDateString(), now()->subDays(6)->toDateString());
        $this->invoice(502, '', 'Unpaid', now()->subDays(2)->toDateString(), now()->addDays(12)->toDateString());

        $this->import();

        $this->assertSame(now()->subDays(6)->toDateString(), Invoice::query()->findOrFail(501)->due_at?->toDateString(), 'WHMCS duedate');
        $this->assertSame(now()->addDays(12)->toDateString(), Invoice::query()->findOrFail(502)->due_at?->toDateString(), 'WHMCS duedate');
        $this->assertSame([501], Invoice::whereOverdue(Invoice::query())->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'overdue as in WHMCS: the reminders find it; the one not due yet is not');
    }
}
