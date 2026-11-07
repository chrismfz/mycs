<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
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
 * Review of the sweep's importer fixes (fix/sweep-import), independent reviewer. Synthetic data only. Each test fails
 * on fix/sweep-import for the gap it names.
 */
class RevImportTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblconfiguration (setting TEXT, value TEXT)');
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR')");
        $this->whmcs->exec('CREATE TABLE tblusers (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, password TEXT,
            second_factor TEXT DEFAULT \'\', second_factor_config TEXT, language TEXT DEFAULT \'\', email_verified_at TEXT,
            created_at TEXT DEFAULT \'2024-01-01 00:00:00\', updated_at TEXT DEFAULT \'2024-01-01 00:00:00\')');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT, credit REAL DEFAULT 0, firstname TEXT, lastname TEXT,
            email TEXT, country TEXT DEFAULT \'GR\', status TEXT DEFAULT \'Active\', datecreated TEXT DEFAULT \'2024-01-01\',
            created_at TEXT DEFAULT \'2024-01-01 00:00:00\', updated_at TEXT DEFAULT \'2024-01-01 00:00:00\')');
        $this->whmcs->exec('CREATE TABLE tblusers_clients (id INTEGER PRIMARY KEY, auth_user_id INT, client_id INT, owner INT, permissions TEXT DEFAULT \'\',
            created_at TEXT, last_login TEXT)');
        $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL DEFAULT 0, notes TEXT DEFAULT \'\')');
        $this->whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT, amount REAL)');
        $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, amountin REAL)');
    }

    private function command(): ImportFromWhmcs
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $this->output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));

        return $command;
    }

    /** @param  list<string>  $steps */
    private function import(array $steps): void
    {
        $command = $this->command();
        foreach ($steps as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
    }

    /**
     * IMPORT-5, review. numberKey() claims to compare numbers "as the unique key compares them", but MariaDB's
     * utf8mb4_uca1400_ai_ci (config/database.php) is accent-insensitive as well: «ΤΙΜ-0412» and «ΤΊΜ-0412» (or «NX-ÉTÉ-1»
     * and «NX-ETE-1») are one value to invoices_number_unique. numberKey keeps both, so on MariaDB the batch insert hits
     * a duplicate key and migrateInBatch stops the import on the cut-over day. The later one should come without its
     * number, and be named, like the case and trailing-space ones.
     */
    public function test_numbers_that_differ_only_by_an_accent_are_one_number_to_the_unique_key(): void
    {
        $this->whmcs->exec("INSERT INTO tblusers (id, first_name, last_name, email, password) VALUES (1, 'A', 'One', 'one@example.test', 'x')");
        $this->whmcs->exec("INSERT INTO tblclients (id, currency, firstname, lastname, email) VALUES (6, 1, 'A', 'One', 'one@example.test')");
        $this->whmcs->exec('INSERT INTO tblusers_clients (auth_user_id, client_id, owner) VALUES (1, 6, 1)');
        $this->whmcs->exec("INSERT INTO tblinvoices (id, userid, invoicenum, date, duedate, datepaid, status, total) VALUES
            (100, 6, 'ΤΙΜ-0412', '2026-10-01', '2026-10-10', '2026-10-02 00:00:00', 'Paid', 10),
            (101, 6, 'ΤΊΜ-0412', '2026-10-01', '2026-10-10', '2026-10-02 00:00:00', 'Paid', 10),
            (102, 6, 'NX-ÉTÉ-1', '2026-10-01', '2026-10-10', '2026-10-02 00:00:00', 'Paid', 10),
            (103, 6, 'NX-ETE-1', '2026-10-01', '2026-10-10', '2026-10-02 00:00:00', 'Paid', 10)");

        $this->import(['importUsers', 'importInvoices']);

        $this->assertSame(['100' => 'ΤΙΜ-0412', '101' => null, '102' => 'NX-ÉTÉ-1', '103' => null],
            DB::table('invoices')->orderBy('id')->pluck('number', 'id')->mapWithKeys(fn ($number, $id) => [(string) $id => $number])->all(),
            'an accent-only variant kept its number: invoices_number_unique (uca1400_ai_ci) takes it for the first, so the MariaDB insert fails');
        $this->assertStringContainsString('#101, #103', $this->output->fetch());
    }
}
