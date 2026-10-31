<?php

namespace Tests\Feature;

use App\Console\Commands\WhmcsReplayCompare as ReplayCommand;
use App\Services\Import\WhmcsReplayCompare;
use PDO;
use Tests\TestCase;

/**
 * `mycs:replay-compare` (ROADMAP 9β, 3): a simulation's ledger against what WHMCS did between two copies of its database.
 * Invented data.
 */
class ReplayCompareTest extends TestCase
{
    private PDO $before;

    private PDO $after;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->before, $this->after] = [$this->whmcs(), $this->whmcs()];
        // Before: one invoice, one service due, one unpaid invoice; after: what the cron did on the day.
        foreach ([$this->before, $this->after] as $pdo) {
            $pdo->exec("INSERT INTO tblinvoices VALUES (100, 7, '2026-11-01', 10, 0, 0, 0, 'Unpaid', '0000-00-00 00:00:00')");
            $pdo->exec("INSERT INTO tblhosting VALUES (1, 7, 'Active', '2026-11-01'), (2, 8, 'Active', '2026-10-20')");
            $pdo->exec('INSERT INTO tblaccounts VALUES (50, 100, 0, 0)');
            $pdo->exec("INSERT INTO tblemails VALUES (500, 7, 'Customer Invoice')");
        }
    }

    private function whmcs(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, duedate TEXT, subtotal REAL, tax REAL, tax2 REAL, credit REAL, status TEXT, last_capture_attempt TEXT)',
            'CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT, nextduedate TEXT)',
            'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, domain TEXT, status TEXT, expirydate TEXT, nextduedate TEXT)',
            'CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, invoiceid INT, amountin REAL, amountout REAL)',
            'CREATE TABLE tblemails (id INTEGER PRIMARY KEY, userid INT, subject TEXT)',
            'CREATE TABLE tblemailtemplates (id INTEGER PRIMARY KEY, type TEXT, subject TEXT)',
        ] as $sql) {
            $pdo->exec($sql);
        }

        return $pdo;
    }

    /** What WHMCS did: an invoice for client 9, a payment of invoice 100, service 1 renewed, service 2 suspended, two mails. */
    private function whmcsDid(): void
    {
        $this->after->exec("INSERT INTO tblinvoices VALUES (101, 9, '2026-11-08', 3.0, 0.6, 0, 0, 'Unpaid', '0000-00-00 00:00:00')");
        $this->after->exec("UPDATE tblinvoices SET status = 'Paid', last_capture_attempt = '2026-11-01 09:00:05' WHERE id = 100");
        $this->after->exec('INSERT INTO tblaccounts VALUES (51, 100, 10, 0)');
        $this->after->exec("UPDATE tblhosting SET nextduedate = '2026-12-01' WHERE id = 1");
        $this->after->exec("UPDATE tblhosting SET domainstatus = 'Suspended' WHERE id = 2");
        $this->after->exec("INSERT INTO tblemails VALUES (501, 9, 'Customer Invoice'), (502, 7, 'Invoice Payment Confirmation'), (503, 7, '[Ticket ID: 2026-11-01-1] Hello')");
    }

    /** @return list<array{day: string, event: string, ref: string, detail: string}> what the simulation did, the same */
    private function same(): array
    {
        return [
            ['day' => '2026-11-01', 'event' => 'invoice created', 'ref' => 'invoice #105', 'detail' => 'client #9, total 3.60, due 2026-11-08'],
            ['day' => '2026-11-01', 'event' => 'card charged', 'ref' => 'invoice #100', 'detail' => 'client #7, 10.00'],
            ['day' => '2026-11-01', 'event' => 'payment', 'ref' => 'invoice #100', 'detail' => 'paid 10.00 (succeeded)'],
            ['day' => '2026-11-01', 'event' => 'invoice status', 'ref' => 'invoice #100', 'detail' => 'unpaid → paid'],
            ['day' => '2026-11-01', 'event' => 'service renewed', 'ref' => 'service #1', 'detail' => '2026-11-01 → 2026-12-01'],
            ['day' => '2026-11-01', 'event' => 'service status', 'ref' => 'service #2', 'detail' => 'active → suspended'],
            ['day' => '2026-11-01', 'event' => 'mail', 'ref' => 'client #9', 'detail' => 'new_invoice_created (sent)'],
            ['day' => '2026-11-01', 'event' => 'mail', 'ref' => 'client #7', 'detail' => 'invoice_paid (sent)'],
        ];
    }

    public function test_the_same_day_is_the_same_whatever_the_ids(): void
    {
        $this->whmcsDid();

        $result = (new WhmcsReplayCompare($this->before, $this->after, $this->same()))->run();

        foreach ($result as $name => $group) {
            $this->assertSame([], $group['only_whmcs'], $name);
            $this->assertSame([], $group['only_mycs'], $name);
        }
        $this->assertSame(1, $result['invoices made']['both']);
        $this->assertSame(2, $result['mails']['both'], 'the ticket mail is not the cron\'s');
    }

    public function test_what_only_one_side_did_is_listed(): void
    {
        $this->whmcsDid();
        $ledger = array_values(array_filter($this->same(), fn (array $l) => !in_array($l['event'], ['payment', 'invoice status', 'card charged'], true) && $l['detail'] !== 'active → suspended'));
        $ledger[] = ['day' => '2026-11-01', 'event' => 'invoice created', 'ref' => 'invoice #106', 'detail' => 'client #11, total 5.00, due 2026-11-09'];
        $ledger[] = ['day' => '2026-11-01', 'event' => 'mail', 'ref' => 'client #11', 'detail' => 'invoice_overdue (sent)'];

        $result = (new WhmcsReplayCompare($this->before, $this->after, $ledger))->run();

        $this->assertSame(['invoice #100, 10.00'], $result['payments']['only_whmcs']);
        $this->assertSame(['service #2, active → suspended'], $result['services: status']['only_whmcs']);
        $this->assertSame(['client #11, total 5.00, due 2026-11-09 (here #106)'], $result['invoices made']['only_mycs']);
        $this->assertSame(['client #11: 1× overdue notice'], $result['mails']['only_mycs']);
        $this->assertSame(['invoice #100, unpaid → paid'], $result['invoice status']['only_whmcs']);
        $this->assertSame(['invoice #100'], $result['card charges']['only_whmcs']);
    }

    public function test_the_command_prints_the_groups_and_fails_while_anything_differs(): void
    {
        $this->whmcsDid();
        $csv = sys_get_temp_dir() . '/replay-' . uniqid() . '.csv';
        $handle = fopen($csv, 'w');
        fputcsv($handle, ['day', 'event', 'ref', 'detail']);
        foreach (array_slice($this->same(), 0, 2) as $line) {
            fputcsv($handle, $line);
        }
        fclose($handle);
        ReplayCommand::$connect = fn (string $dsn) => str_contains($dsn, 'dbname=before') ? $this->before : $this->after;
        putenv('WHMCS_SOURCE_PASSWORD=not-asked');

        try {
            $this->artisan('mycs:replay-compare', ['before' => 'before', 'after' => 'after', '--ledger' => $csv, '--source-user' => 'u', '--source-host' => 'h'])
                ->expectsOutputToContain('Only WHMCS did')
                ->assertExitCode(1);
        } finally {
            putenv('WHMCS_SOURCE_PASSWORD');
            ReplayCommand::$connect = null;
            @unlink($csv);
        }
    }

    public function test_it_exits_2_when_the_comparison_could_not_be_made(): void
    {
        $this->artisan('mycs:replay-compare', ['before' => 'a', 'after' => 'b', '--ledger' => '/nonexistent.csv', '--source-user' => 'u', '--source-host' => 'h'])->assertExitCode(2);
    }
}
