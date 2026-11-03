<?php

namespace Tests\Feature;

use App\Console\Commands\WhmcsParity as ParityCommand;
use App\Models\Extension;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Import\WhmcsRecordParity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * mycs:parity --records (predeploy review 2026-11-02): record by record, so two errors that cancel out in the totals, or a
 * service pointing at another server or account, are seen.
 */
class WhmcsRecordParityTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    /** @var list<int> */
    private array $clients = [];

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE tblclients (id INTEGER PRIMARY KEY, credit REAL DEFAULT 0, currency INTEGER DEFAULT 1)',
            'CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)',
            'CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INTEGER, packageid INTEGER, domainstatus TEXT, amount REAL, nextduedate TEXT, server INTEGER DEFAULT 0, username TEXT, domain TEXT)',
            'CREATE TABLE tblservers (id INTEGER PRIMARY KEY, name TEXT, hostname TEXT, ipaddress TEXT)',
            'CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, status TEXT, duedate TEXT, subtotal REAL, tax REAL DEFAULT 0, tax2 REAL DEFAULT 0, credit REAL DEFAULT 0, total REAL)',
            'CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INTEGER, type TEXT, relid INTEGER DEFAULT 0, amount REAL)',
            'CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INTEGER, invoiceid INTEGER, amountin REAL DEFAULT 0, amountout REAL DEFAULT 0, fees REAL DEFAULT 0)',
            'CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, merged_ticket_id INTEGER DEFAULT 0)',
            'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INTEGER, domain TEXT, expirydate TEXT, recurringamount REAL, registrar TEXT, status TEXT)',
        ] as $sql) {
            $this->whmcs->exec($sql);
        }
        $this->whmcs->exec("INSERT INTO tblcurrencies (id, code) VALUES (1, 'USD')");
        ParityCommand::$connect = fn () => $this->whmcs;
        putenv('WHMCS_SOURCE_PASSWORD=secret');
        $this->sameOnBothSides();
    }

    protected function tearDown(): void
    {
        ParityCommand::$connect = null;
        putenv('WHMCS_SOURCE_PASSWORD');
        parent::tearDown();
    }

    /** Two accounts, an invoice of 10.00 each, paid; one cPanel service on WHMCS's server 3 (cp1.example.test). */
    private function sameOnBothSides(): void
    {
        DB::table('clients')->delete();
        foreach ([1, 2] as $n) {
            $client = User::factory()->create()->client();
            $this->clients[] = (int) $client->id;
            $this->whmcs->exec("INSERT INTO tblclients (id) VALUES ({$client->id})");
            $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-11-10']);
            $invoice->items()->delete();
            $invoice->items()->create(['description' => 'Hosting', 'price' => 10, 'quantity' => 1]);
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid']);
            DB::table('invoice_transactions')->insert(['invoice_id' => $invoice->id, 'amount' => 10, 'is_credit_transaction' => false, 'status' => 'succeeded',
                'created_at' => now(), 'updated_at' => now()]);
            $this->whmcs->exec("INSERT INTO tblinvoices (id, userid, status, duedate, subtotal, total) VALUES ({$invoice->id}, {$client->id}, 'Paid', '2026-11-10', 10, 10)");
            $this->whmcs->exec("INSERT INTO tblaccounts (userid, invoiceid, amountin) VALUES ({$client->id}, {$invoice->id}, 10)");
        }
        $server = Extension::query()->create(['name' => 'cp1', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $server->forceFill(['whmcs_id' => 3])->save();
        $server->settings()->create(['key' => 'host', 'value' => 'https://cp1.example.test:2087']);
        $this->whmcs->exec("INSERT INTO tblservers (id, name, hostname, ipaddress) VALUES (3, 'cp1', 'cp1.example.test', '192.0.2.10')");
        $product = $this->createProduct();
        $this->service = Service::factory()->create(['client_id' => $this->clients[0], 'product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'status' => 'active', 'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'expires_at' => '2026-12-10', 'server_id' => $server->id,
            'whmcs_server_id' => 3, 'username' => 'alice', 'domain' => 'alice.example.test']);
        $this->whmcs->exec("INSERT INTO tblhosting (id, userid, packageid, domainstatus, amount, nextduedate, server, username, domain)
            VALUES ({$this->service->id}, {$this->clients[0]}, {$product->product->id}, 'Active', 10, '2026-12-10', 3, 'alice', 'alice.example.test')");
    }

    /** @return array<string, array{checked: int, count: int, differ: list<string>}> */
    private function records(): array
    {
        return (new WhmcsRecordParity($this->whmcs))->run();
    }

    public function test_the_same_records_on_both_sides_are_no_difference(): void
    {
        $this->assertSame([], array_filter(array_map(fn (array $result) => $result['count'], $this->records())));
        $this->artisan('mycs:parity', ['dbname' => 'whmcs_copy', '--source-user' => 'reader', '--source-host' => 'db.example.test', '--records' => true])
            ->assertSuccessful()->expectsOutputToContain('No difference.');
    }

    /** One account billed 2.00 more, the other 2.00 less: the totals agree, the records do not. */
    public function test_two_errors_that_cancel_out_in_the_totals_are_seen(): void
    {
        [$a, $b] = $this->clients;
        $this->whmcs->exec("UPDATE tblinvoices SET subtotal = 12 WHERE userid = {$a}");
        $this->whmcs->exec("UPDATE tblinvoices SET subtotal = 8 WHERE userid = {$b}");

        $records = $this->records();
        $this->assertSame(2, $records['Account billed']['count']);
        $this->assertContains("account #{$a}: 12.00 in WHMCS, 10.00 here (USD)", $records['Account billed']['differ']);
        $this->artisan('mycs:parity', ['dbname' => 'whmcs_copy', '--source-user' => 'reader', '--source-host' => 'db.example.test', '--records' => true])
            ->assertFailed()->expectsOutputToContain('record(s)');
    }

    /** A live service on another server, or another account on it: the suspension would hit the wrong one. */
    public function test_a_service_on_another_server_or_account_is_seen(): void
    {
        $other = Extension::query()->create(['name' => 'cp2', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $other->forceFill(['whmcs_id' => 4])->save();
        $this->service->forceFill(['server_id' => $other->id, 'username' => 'bob'])->save();

        $records = $this->records();
        $this->assertSame(1, $records['Service servers']['count']);
        $this->assertStringContainsString('on WHMCS server cp1, here on server #' . $other->id, $records['Service servers']['differ'][0]);
        $this->assertSame(["service #{$this->service->id}: username «alice» in WHMCS, «bob» here"], $records['Service accounts']['differ']);
    }

    /** A server here pointing elsewhere than WHMCS's of the same id. */
    public function test_a_server_pointing_elsewhere_is_seen(): void
    {
        Extension::query()->where('whmcs_id', 3)->sole()->settings()->where('key', 'host')->update(['value' => 'https://cp9.example.test:2087']);

        $this->assertStringContainsString('here points to https://cp9.example.test:2087', $this->records()['Service servers']['differ'][0]);
    }
}
