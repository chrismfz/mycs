<?php

namespace Tests\Feature;

use App\Console\Commands\WhmcsParity as ParityCommand;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\Extension;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Import\WhmcsRecordParity;
use App\Services\Money\MoneyTrail;
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

    /** A «Pending» WHMCS order comes without a due date (S10-CRON-BILLING-1): no difference; with any date here, one. */
    public function test_a_pending_order_has_no_due_date_here(): void
    {
        $this->whmcs->exec("UPDATE tblhosting SET domainstatus = 'Pending', nextduedate = '2026-12-10'");
        $this->service->forceFill(['status' => 'pending', 'expires_at' => null])->save();
        $this->assertSame(0, $this->records()['Services']['count']);

        $this->service->forceFill(['expires_at' => '2027-01-10'])->save();
        $this->assertContains("service #{$this->service->id}: due 2026-12-10 in WHMCS, 2027-01-10 here", $this->records()['Services']['differ']);

        // An active one without a date is still a difference.
        $this->whmcs->exec("UPDATE tblhosting SET domainstatus = 'Active'");
        $this->service->forceFill(['status' => 'active', 'expires_at' => null])->save();
        $this->assertSame(1, $this->records()['Services']['count']);
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

    /** A cluster's nodes (pvewhmcs: Pegasus and Vega) are one server here: a service on either node, on it, is no difference. */
    public function test_a_service_on_another_node_of_a_cluster_is_on_its_one_server(): void
    {
        $this->whmcs->exec('ALTER TABLE tblservers ADD COLUMN type TEXT');
        $this->whmcs->exec("INSERT INTO tblservers (id, name, hostname, ipaddress, type) VALUES (54, 'Vega', 'vega.example.test', '192.0.2.54', 'pvewhmcs'),
            (61, 'Pegasus', 'pegasus.example.test', '192.0.2.61', 'pvewhmcs')");
        $cluster = Extension::query()->create(['name' => 'Vega', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        $cluster->forceFill(['whmcs_id' => 54])->save();
        $cluster->settings()->create(['key' => 'host', 'value' => 'https://vega.example.test:8006']); // Vega's address, for Pegasus's service too
        $this->whmcs->exec("UPDATE tblhosting SET server = 61 WHERE id = {$this->service->id}");
        $this->service->forceFill(['server_id' => $cluster->id, 'whmcs_server_id' => 61])->save();

        $this->assertSame(0, $this->records()['Service servers']['count']);

        // On any other server it still differs.
        $this->service->forceFill(['server_id' => Extension::query()->where('whmcs_id', 3)->value('id')])->save();
        $this->assertSame(1, $this->records()['Service servers']['count']);
    }

    /** A server here pointing elsewhere than WHMCS's of the same id. */
    public function test_a_server_pointing_elsewhere_is_seen(): void
    {
        Extension::query()->where('whmcs_id', 3)->sole()->settings()->where('key', 'host')->update(['value' => 'https://cp9.example.test:2087']);

        $this->assertStringContainsString('here points to https://cp9.example.test:2087', $this->records()['Service servers']['differ'][0]);
    }

    /** @return int how many records differ, over every check */
    private function differing(): int
    {
        return array_sum(array_map(fn (array $result) => $result['count'], $this->records()));
    }

    /** Review of #590: a server's settings are stored as App\Models\Server's (the catalog, the admin): those are the ones read. */
    public function test_a_servers_own_settings_are_the_ones_compared(): void
    {
        $server = Extension::query()->where('whmcs_id', 3)->sole();
        DB::table('settings')->insert(['key' => 'host', 'value' => 'https://cp9.example.test:2087', 'type' => 'string', 'encrypted' => false,
            'settingable_type' => Server::class, 'settingable_id' => $server->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertStringContainsString('here points to https://cp9.example.test:2087', $this->records()['Service servers']['differ'][0]);
    }

    /** M1: the host is compared whole: one that only contains WHMCS's name is another server; an IP for its host is WHMCS's IP. */
    public function test_a_host_is_compared_whole(): void
    {
        $host = Extension::query()->where('whmcs_id', 3)->sole()->settings()->where('key', 'host');
        $host->update(['value' => 'https://old-cp1.example.test:2087']);
        $this->assertSame(1, $this->records()['Service servers']['count']);

        $host->update(['value' => 'https://192.0.2.10:2087']);
        $this->assertSame(0, $this->records()['Service servers']['count']);
    }

    /** M2: a Mass Pay parent's payment lands on one of its children; on another invoice it is a difference. */
    public function test_a_mass_pay_payment_lands_on_one_of_its_children(): void
    {
        [$a] = $this->clients;
        $child = (int) DB::table('invoices')->where('client_id', $a)->value('id');
        // The parent's id clear of every invoice of both sides, and of the one made below: the fixture's WHMCS invoices
        // carry MyCS's ids, and MariaDB's counter does not restart per test (a fixed 900 collided on CI, #624).
        $parent = (int) DB::table('invoices')->max('id') + 1000;
        $this->whmcs->exec("INSERT INTO tblinvoices (id, userid, status, duedate, subtotal, total) VALUES ({$parent}, {$a}, 'Paid', '2026-11-10', 10, 10)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, type, relid, amount) VALUES ({$parent}, 'Invoice', {$child}, 10)");
        $this->whmcs->exec("UPDATE tblaccounts SET invoiceid = {$parent} WHERE invoiceid = {$child}");
        $this->assertSame(0, $this->differing());

        $other = Invoice::factory()->create(['client_id' => $a, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $payment = (int) DB::table('invoice_transactions')->where('invoice_id', $child)->value('id');
        DB::table('invoice_transactions')->where('id', $payment)->update(['invoice_id' => $other->id]);

        $records = $this->records();
        $this->assertSame(1, $records['Payments']['count']);
        $this->assertStringContainsString("on Mass Pay invoice #{$parent} in WHMCS (its invoices #{$child}), here on invoice #{$other->id}", $records['Payments']['differ'][0]);
    }

    /** M7: a payment on no invoice here is not imported (importPayments): a note, never a difference, and out of the account's sums. */
    public function test_a_payment_left_out_by_the_importers_rule_is_a_note(): void
    {
        $this->whmcs->exec("INSERT INTO tblaccounts (userid, invoiceid, amountin) VALUES ({$this->clients[0]}, 0, 15.80)");

        $parity = new WhmcsRecordParity($this->whmcs);
        $this->assertSame(0, array_sum(array_map(fn (array $result) => $result['count'], $parity->run())));
        $this->assertStringContainsString("account #{$this->clients[0]}, 15.80 on invoice #0: on no invoice here", implode("\n", $parity->notes()));
    }

    /** M7: a refund to credit is the money event refund_to_credit here; a payment's credited part alone (an overpayment) is not one. */
    public function test_a_refund_to_credit_is_its_money_event(): void
    {
        $a = $this->clients[0];
        $this->whmcs->exec('ALTER TABLE tblaccounts ADD COLUMN refundid INTEGER DEFAULT 0');
        $invoice = (int) DB::table('invoices')->where('client_id', $a)->value('id');
        $payment = (int) DB::table('invoice_transactions')->where('invoice_id', $invoice)->value('id');
        $this->whmcs->exec("INSERT INTO tblaccounts (userid, invoiceid, amountout, refundid) VALUES ({$a}, {$invoice}, 4, {$payment})");
        DB::table('invoice_transactions')->where('id', $payment)->update(['credited_amount' => 4]);
        $this->assertSame(1, $this->records()['Account refunded']['count']);

        DB::table('money_events')->insert(['kind' => 'credit_in', 'amount' => 4, 'currency_code' => 'USD', 'client_id' => $a, 'invoice_id' => $invoice,
            'invoice_transaction_id' => $payment, 'source' => MoneyTrail::SOURCE_IMPORT, 'cause' => MoneyTrail::CAUSE_REFUND_TO_CREDIT, 'occurred_at' => now()]);
        $this->assertSame(0, $this->records()['Account refunded']['count']);
    }

    /** L5: an invoice's number and the day it was paid; a number two WHMCS invoices have is the first's (a note for the other). */
    public function test_an_invoices_number_and_paid_day_are_compared(): void
    {
        [$a, $b] = $this->clients;
        $this->whmcs->exec("ALTER TABLE tblinvoices ADD COLUMN invoicenum TEXT DEFAULT ''");
        $this->whmcs->exec('ALTER TABLE tblinvoices ADD COLUMN datepaid TEXT');
        DB::table('invoices')->update(['number' => null]);
        $this->assertSame(0, $this->differing());

        $first = (int) DB::table('invoices')->where('client_id', $a)->value('id');
        $second = (int) DB::table('invoices')->where('client_id', $b)->value('id');
        $this->whmcs->exec("UPDATE tblinvoices SET invoicenum = '2026-7', datepaid = '2026-11-01 10:00:00' WHERE id = {$first}");
        $records = $this->records();
        $this->assertSame(1, $records['Invoices']['count']);
        $this->assertContains("invoice #{$first}: number «2026-7» in WHMCS, «» here", $records['Invoices']['differ']);
        $this->assertContains("invoice #{$first}: paid 2026-11-01 in WHMCS, never here", $records['Invoices']['differ']);

        DB::table('invoices')->where('id', $first)->update(['number' => '2026-7', 'paid_at' => '2026-11-01 10:00:00']);
        $this->whmcs->exec("UPDATE tblinvoices SET invoicenum = '2026-7' WHERE id = {$second}");
        $parity = new WhmcsRecordParity($this->whmcs);
        $this->assertSame(0, $parity->run()['Invoices']['count']);
        $this->assertStringContainsString("invoice #{$second}: its number «2026-7» is another WHMCS invoice's too", implode("\n", $parity->notes()));
    }

    /** M3, L3: a domain's status as the importer maps it (Expired is live), its name normalised; the registrar of one live here. */
    public function test_a_domains_status_is_compared_and_an_expired_one_is_live(): void
    {
        $domain = Domain::factory()->create(['client_id' => $this->clients[0], 'sld' => 'carol', 'fqdn' => 'carol.test', 'expires_at' => '2026-10-01',
            'recurring_amount' => 10, 'status' => DomainStatus::Expired]);
        $this->whmcs->exec("INSERT INTO tbldomains (id, userid, domain, expirydate, recurringamount, registrar, status)
            VALUES ({$domain->id}, {$this->clients[0]}, 'Carol.TEST.', '2026-10-01', 10, '', 'Expired')");
        $this->assertSame(0, $this->differing());

        $this->whmcs->exec("UPDATE tbldomains SET registrar = 'openprovider'");
        $this->assertSame(["domain #{$domain->id} (carol.test): registrar openprovider in WHMCS, none here"], $this->records()['Domain registrars']['differ']);

        $this->whmcs->exec("UPDATE tbldomains SET registrar = '', status = 'Active'");
        $this->assertSame(["domain #{$domain->id} (carol.test): active in WHMCS (Active), expired here"], $this->records()['Domains']['differ']);
    }

    /** M3: of two live WHMCS rows of one name, the importer keeps one live, the other cancelled: a note. */
    public function test_a_domain_live_twice_in_whmcs_is_a_note(): void
    {
        $kept = Domain::factory()->create(['client_id' => $this->clients[0], 'sld' => 'dave', 'fqdn' => 'dave.test', 'expires_at' => '2027-01-01', 'recurring_amount' => 10]);
        $other = Domain::factory()->create(['client_id' => $this->clients[0], 'sld' => 'dave', 'fqdn' => 'dave.test', 'expires_at' => '2026-01-01', 'recurring_amount' => 10,
            'status' => DomainStatus::Cancelled]);
        foreach ([[$kept->id, '2027-01-01'], [$other->id, '2026-01-01']] as [$id, $expires]) {
            $this->whmcs->exec("INSERT INTO tbldomains (id, userid, domain, expirydate, recurringamount, registrar, status) VALUES ({$id}, {$this->clients[0]}, 'dave.test', '{$expires}', 10, '', 'Active')");
        }

        $parity = new WhmcsRecordParity($this->whmcs);
        $this->assertSame(0, array_sum(array_map(fn (array $result) => $result['count'], $parity->run())));
        $this->assertStringContainsString("domain #{$other->id} (dave.test): Active in WHMCS, also live as #{$kept->id}", implode("\n", $parity->notes()));
    }

    /**
     * WG-1, code review of #641: WHMCS's amount is without its account's group discount, billed as a line of its own;
     * the import takes it off (10.00 at 10%: 9.00), which is no difference. The full amount kept here is one.
     */
    public function test_a_services_price_is_compared_less_its_groups_discount(): void
    {
        $this->whmcs->exec('ALTER TABLE tblclients ADD COLUMN groupid INTEGER DEFAULT 0');
        $this->whmcs->exec('CREATE TABLE tblclientgroups (id INTEGER PRIMARY KEY, discountpercent REAL)');
        $this->whmcs->exec('INSERT INTO tblclientgroups VALUES (3, 10.00)');
        $this->whmcs->exec("UPDATE tblclients SET groupid = 3 WHERE id = {$this->clients[0]}");
        $this->service->forceFill(['price' => 9])->save();
        $this->assertSame([], $this->records()['Services']['differ']);

        $this->service->forceFill(['price' => 10])->save();
        $this->assertSame(["service #{$this->service->id}: 9.00 in WHMCS, 10.00 here"], $this->records()['Services']['differ']);
    }

    /** M6: a WHMCS status the importer's map does not know (imported pending) is a difference. */
    public function test_an_unknown_service_status_is_a_difference(): void
    {
        $this->whmcs->exec("UPDATE tblhosting SET domainstatus = 'Frozen'");
        $this->service->forceFill(['status' => 'pending'])->save();

        $this->assertContains("service #{$this->service->id}: WHMCS status «Frozen» unknown, imported pending: check it", $this->records()['Services']['differ']);
    }

    /** M4: a live service with no plan renews on none; an ended one is no difference. */
    public function test_a_live_service_without_a_plan_is_a_difference(): void
    {
        $this->service->forceFill(['plan_id' => null])->save();
        $this->assertSame(1, $this->records()['Services']['count']);

        $this->whmcs->exec("UPDATE tblhosting SET domainstatus = 'Terminated'");
        $this->service->forceFill(['status' => 'terminated'])->save();
        $this->assertSame(0, $this->differing());
    }

    /** L1: none in WHMCS: none here, or its product's server; never another. */
    public function test_with_no_server_in_whmcs_only_its_products_is_right(): void
    {
        $this->whmcs->exec('UPDATE tblhosting SET server = 0');
        $this->assertSame(1, $this->records()['Service servers']['count']);

        Product::query()->whereKey($this->service->product_id)->update(['server_id' => $this->service->server_id]);
        $this->assertSame(0, $this->records()['Service servers']['count']);
    }

    /** M8: an SSL certificate moved to its Offline SSL server (#532) belongs there, whatever WHMCS's server says. */
    public function test_an_offline_ssl_certificate_belongs_on_its_offline_server(): void
    {
        $offline = Extension::query()->create(['name' => 'TheSSLStore (offline)', 'extension' => 'OfflineSsl', 'type' => 'server', 'enabled' => true]);
        Product::query()->whereKey($this->service->product_id)->update(['server_id' => $offline->id]);
        $this->assertStringContainsString("on server #{$offline->id} (TheSSLStore (offline)) expected, here on server #{$this->service->server_id} (cp1)",
            $this->records()['Service servers']['differ'][0]);

        $this->service->forceFill(['server_id' => $offline->id])->save();
        $this->assertSame(0, $this->records()['Service servers']['count']);
    }

    /** M8: a server set by hand for a WHMCS server not imported, pointing elsewhere, is the wrong one. */
    public function test_a_server_set_by_hand_elsewhere_is_a_difference(): void
    {
        $this->whmcs->exec('UPDATE tblhosting SET server = 7');
        $this->whmcs->exec("INSERT INTO tblservers (id, name, hostname, ipaddress) VALUES (7, 'pve1', 'pve1.example.test', '192.0.2.70')");
        $byHand = Extension::query()->create(['name' => 'pve2', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        $byHand->settings()->create(['key' => 'host', 'value' => 'https://pve2.example.test:8006']);
        $this->service->forceFill(['server_id' => $byHand->id])->save();

        $this->assertSame(["service #{$this->service->id}: WHMCS server pve1 has no server here; here on server #{$byHand->id} (pve2), which points to https://pve2.example.test:8006"],
            $this->records()['Service servers']['differ']);
    }

    /** L4: «Records» counts records: one service differing twice is one. */
    public function test_a_record_differing_twice_counts_once(): void
    {
        $this->service->forceFill(['price' => 12, 'expires_at' => '2027-01-01'])->save();

        $records = $this->records();
        $this->assertSame(1, $records['Services']['checked']);
        $this->assertSame(1, $records['Services']['count']);
        $this->assertCount(2, $records['Services']['differ']);
    }
}
