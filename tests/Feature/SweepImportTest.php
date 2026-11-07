<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use Illuminate\Console\ManuallyFailedException;
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
 * Sweep of the importer (2026-11-07), adversarial verifier. Synthetic data only. Each test fails on main (00268d7f) for
 * the finding it names.
 */
class SweepImportTest extends TestCase
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
     * IMPORT-2. WHMCS client #7 has no owner login (its tblusers_clients row is gone): importUsers skips it with no word,
     * but importInvoices only asks WHMCS for the client's currency, so its unpaid invoice comes with client_id 7, an
     * account that is not here (the importer runs with foreign_key_checks=0). After the cut-over PaymentReminders::open()
     * takes it (whereNotNull('client_id')) and `$invoice->client->remindedWhenOverdue()` on null stops the run.
     */
    public function test_an_account_skipped_for_want_of_an_owner_leaves_no_invoice_behind(): void
    {
        $this->whmcs->exec("INSERT INTO tblusers (id, first_name, last_name, email, password) VALUES (1, 'A', 'One', 'one@example.test', 'x')");
        $this->whmcs->exec("INSERT INTO tblclients (id, currency, firstname, lastname, email) VALUES (6, 1, 'A', 'One', 'one@example.test'),
            (7, 1, 'B', 'Two', 'two@example.test')");
        $this->whmcs->exec('INSERT INTO tblusers_clients (auth_user_id, client_id, owner) VALUES (1, 6, 1)'); // none for #7
        $this->whmcs->exec("INSERT INTO tblinvoices (id, userid, invoicenum, date, duedate, datepaid, status, total) VALUES
            (100, 6, '', '2026-10-01', '2026-10-10', '0000-00-00 00:00:00', 'Unpaid', 10),
            (101, 7, '', '2026-10-01', '2026-10-10', '0000-00-00 00:00:00', 'Unpaid', 20)");

        // As the importer runs (SET foreign_key_checks=0); inside the test transaction SQLite takes only a deferral.
        DB::statement(DB::getDriverName() === 'sqlite' ? 'PRAGMA defer_foreign_keys = ON' : 'SET foreign_key_checks = 0');
        $this->import(['importUsers', 'importInvoices']);

        $this->assertSame([6], DB::table('clients')->pluck('id')->map(fn ($id) => (int) $id)->all(), 'account #7 skipped');
        $orphans = DB::table('invoices')->whereNotIn('client_id', DB::table('clients')->select('id'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame([], $orphans, 'an invoice of an account that is not here');
    }

    /**
     * IMPORT-3. handle() prints only the SQLSTATE of a failed query («its message carries its values: emails, hashes»),
     * but migrateInBatch catches the QueryException first and fails with its whole message: Laravel's QueryException is
     * the full `insert into "users" (...) values (...)` with every row's email and password hash, printed by the
     * command (ManuallyFailedException is not a PDOException, so handle()'s own catch never sees it).
     */
    public function test_a_failed_batch_insert_never_prints_its_rows(): void
    {
        $hash = '$2y$10$abcdefghijklmnopqrstuuSecretHashOfTheTestLogin0123456789';
        $this->whmcs->exec("INSERT INTO tblusers (id, first_name, last_name, email, password) VALUES
            (1, 'A', 'One', 'same@example.test', '{$hash}'), (2, 'B', 'Two', 'same@example.test', 'other')");

        try {
            $this->import(['importLogins']);
            $this->fail('the duplicate email was expected to stop the import');
        } catch (ManuallyFailedException $e) {
            $printed = $e->getMessage(); // what Command::run prints as the error
        }

        $this->assertStringNotContainsString('same@example.test', $printed, 'a login\'s email printed to the console');
        $this->assertStringNotContainsString($hash, $printed, 'a password hash printed to the console');
    }

    /** IMPORT-2, the fixer's: the skipped account is named, so it is fixed in WHMCS before the final import. */
    public function test_an_account_without_owner_is_named(): void
    {
        $this->whmcs->exec("INSERT INTO tblclients (id, currency, firstname, lastname, email) VALUES (7, 1, 'B', 'Two', 'two@example.test')");

        $this->import(['importUsers']);

        $printed = $this->output->fetch();
        $this->assertStringContainsString('without an owner login', $printed);
        $this->assertStringContainsString('#7', $printed);
        $this->assertStringNotContainsString('two@example.test', $printed);
    }

    /**
     * IMPORT-5. Two numbers the unique key takes for one (MariaDB: case-insensitive, trailing spaces ignored) never both
     * come: the later one is imported without, and named.
     */
    public function test_numbers_the_unique_key_takes_for_one_keep_the_first_and_are_named(): void
    {
        $this->whmcs->exec("INSERT INTO tblusers (id, first_name, last_name, email, password) VALUES (1, 'A', 'One', 'one@example.test', 'x')");
        $this->whmcs->exec("INSERT INTO tblclients (id, currency, firstname, lastname, email) VALUES (6, 1, 'A', 'One', 'one@example.test')");
        $this->whmcs->exec('INSERT INTO tblusers_clients (auth_user_id, client_id, owner) VALUES (1, 6, 1)');
        $this->whmcs->exec("INSERT INTO tblinvoices (id, userid, invoicenum, date, duedate, datepaid, status, total) VALUES
            (100, 6, 'NX-0412', '2026-10-01', '2026-10-10', '2026-10-02 00:00:00', 'Paid', 10),
            (101, 6, 'NX-0412 ', '2026-10-01', '2026-10-10', '2026-10-02 00:00:00', 'Paid', 10),
            (102, 6, 'nx-0412', '2026-10-01', '2026-10-10', '2026-10-02 00:00:00', 'Paid', 10),
            (103, 6, 'NX-0413', '2026-10-01', '2026-10-10', '2026-10-02 00:00:00', 'Paid', 10)");

        $this->import(['importUsers', 'importInvoices']);

        $this->assertSame(['100' => 'NX-0412', '101' => null, '102' => null, '103' => 'NX-0413'],
            DB::table('invoices')->orderBy('id')->pluck('number', 'id')->mapWithKeys(fn ($number, $id) => [(string) $id => $number])->all());
        $this->assertStringContainsString('#101, #102', $this->output->fetch());
    }
}
