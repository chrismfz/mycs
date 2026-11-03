<?php

namespace Tests\Feature;

use App\Console\Commands\WhmcsParity as ParityCommand;
use App\Models\Domain;
use App\Models\Extension;
use App\Models\Invoice;
use App\Models\Product;
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
class WhmcsRecordParityReviewTest extends TestCase
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
            // The importer keeps a payment's id (tblaccounts.id): the same on both sides, whatever MariaDB's counter is at.
            $paymentId = DB::table('invoice_transactions')->insertGetId(['invoice_id' => $invoice->id, 'amount' => 10, 'is_credit_transaction' => false,
                'status' => 'succeeded', 'created_at' => now(), 'updated_at' => now()]);
            $this->whmcs->exec("INSERT INTO tblinvoices (id, userid, status, duedate, subtotal, total) VALUES ({$invoice->id}, {$client->id}, 'Paid', '2026-11-10', 10, 10)");
            $this->whmcs->exec("INSERT INTO tblaccounts (id, userid, invoiceid, amountin) VALUES ({$paymentId}, {$client->id}, {$invoice->id}, 10)");
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

    /** R1: cPanel connects to its `ip` setting whatever DNS says (PanelConnection): one pointing elsewhere sends every action there. */
    public function test_review_a_cpanel_ip_pointing_elsewhere_is_seen(): void
    {
        Extension::query()->where('whmcs_id', 3)->sole()->settings()->create(['key' => 'ip', 'value' => '198.51.100.7']);

        $this->assertSame(1, $this->records()['Service servers']['count'] ?? 0);
    }

    /** R2: a host that merely contains WHMCS's address (192.0.2.1 in 192.0.2.10) is another server. */
    public function test_review_a_host_that_only_contains_the_address_is_seen(): void
    {
        $this->whmcs->exec("UPDATE tblservers SET hostname = '', ipaddress = '192.0.2.1' WHERE id = 3");
        Extension::query()->where('whmcs_id', 3)->sole()->settings()->where('key', 'host')->update(['value' => 'https://192.0.2.10:2087']);

        $this->assertSame(1, $this->records()['Service servers']['count'] ?? 0);
    }

    /** R3: a payment on another invoice of the same account: the account's sums agree, the invoices do not. */
    public function test_review_a_payment_on_another_invoice_of_the_same_account_is_seen(): void
    {
        $a = $this->clients[0];
        $first = (int) DB::table('invoices')->where('client_id', $a)->value('id');
        $second = Invoice::factory()->create(['client_id' => $a, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-11-10']);
        $second->items()->delete();
        $second->items()->create(['description' => 'Hosting', 'price' => 10, 'quantity' => 1]);
        DB::table('invoices')->where('id', $second->id)->update(['status' => 'paid']);
        $this->whmcs->exec("INSERT INTO tblinvoices (id, userid, status, duedate, subtotal, total) VALUES ({$second->id}, {$a}, 'Paid', '2026-11-10', 10, 10)");
        $this->whmcs->exec("INSERT INTO tblaccounts (userid, invoiceid, amountin) VALUES ({$a}, {$second->id}, 10)");
        // Both payments here on the first invoice.
        DB::table('invoice_transactions')->insert(['invoice_id' => $first, 'amount' => 10, 'is_credit_transaction' => false, 'status' => 'succeeded',
            'created_at' => now(), 'updated_at' => now()]);

        $this->assertGreaterThan(0, array_sum(array_map(fn (array $r) => $r['count'], $this->records())));
    }

    /** R4: WHMCS leaves a payment's customer 0 at times; the importer puts it on its invoice's account: no difference. */
    public function test_review_a_payment_whmcs_left_without_its_customer_is_no_difference(): void
    {
        $this->whmcs->exec("UPDATE tblaccounts SET userid = 0 WHERE userid = {$this->clients[0]}");

        $this->assertSame(0, $this->records()['Account paid']['count']);
    }

    /** R5: a pending service is created on its server when paid: on another server here it is the wrong one. */
    public function test_review_a_pending_service_on_another_server_is_seen(): void
    {
        $other = Extension::query()->create(['name' => 'cp2', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $other->forceFill(['whmcs_id' => 4])->save();
        $this->whmcs->exec("UPDATE tblhosting SET domainstatus = 'Pending'");
        $this->service->forceFill(['status' => 'pending', 'server_id' => $other->id])->save();

        $this->assertSame(1, $this->records()['Service servers']['count'] ?? 0);
    }

    /** R6: a service whose WHMCS server has no server here, set by staff to the right one: still a difference, forever. */
    public function test_review_a_server_staff_set_by_hand_is_no_difference(): void
    {
        $this->whmcs->exec('UPDATE tblhosting SET server = 7');
        $this->whmcs->exec("INSERT INTO tblservers (id, name, hostname, ipaddress) VALUES (7, 'pve1', 'pve1.example.test', '192.0.2.70')");
        $byHand = Extension::query()->create(['name' => 'pve1', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        $byHand->settings()->create(['key' => 'host', 'value' => 'https://pve1.example.test:8006']);
        $this->service->forceFill(['server_id' => $byHand->id])->save();

        $this->assertSame(0, $this->records()['Service servers']['count']);
    }

    /** R7: a refund to credit is the payment's credited part here (importRefunds), WHMCS's money out there: no difference. */
    public function test_review_a_refund_to_credit_is_no_difference(): void
    {
        $a = $this->clients[0];
        $invoice = (int) DB::table('invoices')->where('client_id', $a)->value('id');
        $this->whmcs->exec("INSERT INTO tblaccounts (userid, invoiceid, amountout) VALUES ({$a}, {$invoice}, 10)");
        DB::table('invoice_transactions')->where('invoice_id', $invoice)->update(['credited_amount' => 10]);

        $this->assertSame(0, $this->records()['Account refunded']['count']);
    }

    /** R8: a domain ended in WHMCS but live here (it would renew) is not compared. */
    public function test_review_a_domain_live_here_but_cancelled_in_whmcs_is_seen(): void
    {
        $domain = Domain::factory()->create(['client_id' => $this->clients[0], 'sld' => 'carol', 'fqdn' => 'carol.test', 'expires_at' => '2027-01-01', 'recurring_amount' => 10]);
        $this->whmcs->exec("INSERT INTO tbldomains (id, userid, domain, expirydate, recurringamount, registrar, status) VALUES ({$domain->id}, {$this->clients[0]}, 'carol.test', '2027-01-01', 10, '', 'Cancelled')");

        $this->assertSame(1, array_sum(array_map(fn (array $r) => $r['count'], $this->records())));
    }

    /** R9: a service on another billing cycle here (its plan) renews for another period: not compared. */
    public function test_review_a_service_on_another_cycle_is_seen(): void
    {
        $annual = Product::query()->find($this->service->product_id)->plans()->create(['name' => 'Annually', 'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'year']);
        $this->service->forceFill(['plan_id' => $annual->id])->save(); // WHMCS: Monthly (tblhosting has no billingcycle in this fixture)

        $this->assertSame(1, array_sum(array_map(fn (array $r) => $r['count'], $this->records())));
    }
}
