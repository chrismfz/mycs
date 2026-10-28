<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Adversarial review of #477 (seeders write what the migrations write; counters start at 0).
 */
class Adv477Test extends TestCase
{
    use RefreshDatabase;

    /**
     * ADV-2 (P2). The counter holds the LAST number given (the PR's own wording, `settings-map.md`, and the 2025-04-25
     * migration that sets it to MAX(id)). The WHMCS importer still sets it to MAX(id) + 1, so in counter mode the first
     * invoice after the import skips a number. Scenario: WHMCS invoices 1..3 numbered 1..3, format `{number}`, import,
     * the first new invoice → 4, but it gets 5.
     */
    public function test_the_first_invoice_after_a_whmcs_import_takes_the_next_number_not_one_past_it(): void
    {
        config(['settings.invoice_number_format' => '{number}', 'settings.invoice_number_padding' => 1,
            'settings.invoice_number_is_id' => false, 'settings.invoice_proforma' => false]);
        $clientId = User::factory()->create()->client()->id;
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        $whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT)');
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");
        $whmcs->exec("INSERT INTO tblclients VALUES ({$clientId}, 1)");
        foreach ([1, 2, 3] as $id) {
            $whmcs->exec("INSERT INTO tblinvoices VALUES ({$id}, {$clientId}, '{$id}', '2026-09-01', '2026-09-10', '2026-09-03', 'Paid', 10, 0, 0)");
        }

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new ReflectionMethod($command, 'importInvoices'))->invoke($command);

        $invoice = Invoice::factory()->create(['client_id' => $clientId, 'status' => Invoice::STATUS_UNPAID]);

        $this->assertSame('4', $invoice->fresh()->number, 'the counter holds the last number given (3): the next is 4');
    }

    /**
     * ADV-3 (P2). SeedersCoverMigrationDataTest claims «a new migration that writes rows puts them in a seeder too, or this
     * fails», but it compares only three tables and only some of their columns. A migration seeding a role or a currency
     * (both tables a fresh install fills), or setting a setting's `encrypted` flag or a department's `polling`/`whmcs_id`,
     * would not be noticed, and a schema-dump install would lose it. Scenario: every table that a fresh migrate + seed
     * fills, and every column of those besides ids and timestamps, should be in the guard's comparison.
     */
    public function test_the_guard_compares_every_table_and_column_a_fresh_install_fills(): void
    {
        // What the guard compares, as it reads it (since the fix: every table, every column but ids and timestamps).
        $guard = new SeedersCoverMigrationDataTest('guard');
        $guarded = [];
        foreach ((new ReflectionMethod($guard, 'rows'))->invoke($guard) as $name => $rows) {
            $guarded[$name] = array_keys((array) json_decode($rows[0], true));
        }
        $missing = [];
        foreach (Schema::getTableListing(Schema::getCurrentSchemaName(), false) as $table) {
            if ($table === 'migrations' || DB::table($table)->count() === 0) {
                continue;
            }
            if (!isset($guarded[$table])) {
                $missing[] = $table;

                continue;
            }
            foreach (array_diff(Schema::getColumnListing($table), ['id', 'created_at', 'updated_at', 'scope'], $guarded[$table]) as $column) {
                $missing[] = "{$table}.{$column}";
            }
        }

        $this->assertSame([], $missing, 'filled by a fresh install but not compared by SeedersCoverMigrationDataTest');
    }
}
