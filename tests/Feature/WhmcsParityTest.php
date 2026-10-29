<?php

namespace Tests\Feature;

use App\Console\Commands\WhmcsParity as ParityCommand;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * mycs:parity (docs/importer.md, «Parity»): WHMCS's copy and this instance side by side, on the same rules, exit 1 on a
 * difference. An invoice's amount is what was billed before any credit (WHMCS's total is after it), Mass Pay parents left
 * out, money and credit counted on their own.
 */
class WhmcsParityTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE tblclients (id INTEGER PRIMARY KEY, credit REAL DEFAULT 0)',
            'CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, domainstatus TEXT)',
            'CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, status TEXT, subtotal REAL, tax REAL DEFAULT 0, tax2 REAL DEFAULT 0, credit REAL DEFAULT 0, total REAL)',
            'CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INTEGER, type TEXT, amount REAL)',
            'CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, invoiceid INTEGER, amountin REAL DEFAULT 0, amountout REAL DEFAULT 0, fees REAL DEFAULT 0)',
            'CREATE TABLE tbltickets (id INTEGER PRIMARY KEY)',
            'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY)',
        ] as $sql) {
            $this->whmcs->exec($sql);
        }
        ParityCommand::$connect = fn () => $this->whmcs;
        putenv('WHMCS_SOURCE_PASSWORD=secret');
    }

    protected function tearDown(): void
    {
        ParityCommand::$connect = null;
        putenv('WHMCS_SOURCE_PASSWORD');
        parent::tearDown();
    }

    private function parity()
    {
        return $this->artisan('mycs:parity', ['dbname' => 'whmcs_copy', '--source-user' => 'reader', '--source-host' => 'db.example.test']);
    }

    /** One customer, one paid invoice of 24.80 (20 + 4.80 tax), half paid by credit (WHMCS's total is after it). */
    private function sameOnBothSides(): Invoice
    {
        $this->whmcs->exec('INSERT INTO tblclients (id, credit) VALUES (1, 5)');
        $this->whmcs->exec("INSERT INTO tblinvoices (id, status, subtotal, tax, credit, total) VALUES (10, 'Paid', 20, 4.80, 12.40, 12.40)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, type, amount) VALUES (10, 'Hosting', 20)");
        $this->whmcs->exec('INSERT INTO tblaccounts (invoiceid, amountin) VALUES (10, 12.40)');
        // A Mass Pay parent: no invoice here.
        $this->whmcs->exec("INSERT INTO tblinvoices (id, status, subtotal, total) VALUES (11, 'Paid', 24.80, 24.80)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, type, amount) VALUES (11, 'Invoice', 24.80)");

        $user = User::factory()->create();
        $client = $user->client();
        DB::table('clients')->where('id', '!=', $client->id)->delete();
        DB::table('credits')->insert(['client_id' => $client->id, 'currency_code' => 'USD', 'amount' => 5, 'created_at' => now(), 'updated_at' => now()]);
        // Created unpaid (MT-1c), then paid as the importer writes it: straight into its row.
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->delete();
        $invoice->items()->create(['description' => 'Hosting', 'price' => 20, 'quantity' => 1, 'taxed' => true, 'tax_rate' => 24, 'tax_amount' => 4.80]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid']);
        DB::table('invoice_transactions')->insert([
            ['invoice_id' => $invoice->id, 'amount' => 12.40, 'is_credit_transaction' => false, 'status' => 'succeeded', 'created_at' => now(), 'updated_at' => now()],
            ['invoice_id' => $invoice->id, 'amount' => 12.40, 'is_credit_transaction' => true, 'status' => 'succeeded', 'created_at' => now(), 'updated_at' => now()],
        ]);

        return $invoice;
    }

    public function test_the_same_numbers_on_both_sides_are_no_difference(): void
    {
        $this->sameOnBothSides();

        $this->parity()->assertSuccessful()->expectsOutputToContain('No difference.');
    }

    public function test_what_differs_is_shown_and_the_exit_is_a_failure(): void
    {
        $this->sameOnBothSides();
        $this->whmcs->exec('INSERT INTO tblaccounts (invoiceid, amountin, fees) VALUES (10, 0, 0.75)');
        $this->whmcs->exec('INSERT INTO tbldomains (id) VALUES (1), (2)');

        $this->artisan('mycs:parity', ['dbname' => 'whmcs_copy', '--source-user' => 'reader', '--source-host' => 'db.example.test', '--only-differences' => true])
            ->assertFailed()
            ->expectsTable(['', 'WHMCS', 'MyCS', 'Difference'], [['Gateway fees', '0.75', '0.00', '-0.75'], ['Domains', '2', '0', '-2']])
            ->expectsOutputToContain('2 line(s) differ');
    }

    public function test_without_the_source_or_with_one_that_is_not_whmcs_nothing_is_compared(): void
    {
        $this->artisan('mycs:parity', ['dbname' => 'whmcs_copy'])->assertFailed()->expectsOutputToContain('--source-user');

        ParityCommand::$connect = fn () => new PDO('sqlite::memory:');
        $this->parity()->assertFailed()->expectsOutputToContain('Not a WHMCS database');
    }
}
