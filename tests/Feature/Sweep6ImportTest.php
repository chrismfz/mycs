<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Import\WhmcsClientCurrency;
use App\Services\Import\WhmcsSecondFactor;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use Throwable;

/**
 * Sweep 6, adversarial verification of the importer. Synthetic data only. Each test fails for the reason in its name.
 */
class Sweep6ImportTest extends TestCase
{
    use RefreshDatabase;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function command(PDO $whmcs): ImportFromWhmcs
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $this->output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));

        return $command;
    }

    private function pdo(): PDO
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $whmcs;
    }

    /**
     * S6-IMPORT-1: an account whose tblclients.currency names no currency row (0: WHMCS's getCurrency falls back to the
     * default) is imported, but its invoices are dropped with a bare `continue` and nothing names it.
     */
    public function test_s6_import_1_an_account_with_currency_0_keeps_its_invoices_or_is_named(): void
    {
        $clientId = User::factory()->create()->client()->id;
        $whmcs = $this->pdo();
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR')");
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $whmcs->exec("INSERT INTO tblclients VALUES ({$clientId}, 0)");
        $whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL, notes TEXT)');
        $whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        $whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT)');
        $whmcs->exec("INSERT INTO tblinvoices VALUES (701, {$clientId}, '', '2026-11-01', '2026-11-10', NULL, 'Unpaid', 20, 0, 0, NULL)");
        // Control: another account with its currency set, its invoice imported.
        $otherId = User::factory()->create()->client()->id;
        $whmcs->exec("INSERT INTO tblclients VALUES ({$otherId}, 1)");
        $whmcs->exec("INSERT INTO tblinvoices VALUES (702, {$otherId}, '', '2026-11-01', '2026-11-10', NULL, 'Unpaid', 20, 0, 0, NULL)");

        $command = $this->command($whmcs);
        (new ReflectionMethod($command, 'importInvoices'))->invoke($command);
        $this->assertTrue(Invoice::query()->whereKey(702)->exists(), 'control: the other account\'s invoice is imported');

        $named = str_contains($this->output->fetch(), (string) $clientId);
        $this->assertTrue(Invoice::query()->whereKey(701)->exists() || $named,
            "the customer's unpaid invoice #701 is imported (in the default currency, as WHMCS bills it) or the account is named: it is silently dropped");
    }

    /**
     * S6-IMPORT-1 (b): the same account with credit: importUsers writes currency_code NULL into credits (NOT NULL) and
     * the import aborts after migrate:fresh emptied the target.
     */
    public function test_s6_import_1b_an_account_with_currency_0_and_credit_does_not_abort_the_import(): void
    {
        $whmcs = $this->pdo();
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR')");
        $whmcs->exec('CREATE TABLE tblusers (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, password TEXT,
            second_factor TEXT, second_factor_config TEXT, email_verified_at TEXT, created_at TEXT, updated_at TEXT)');
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT,
            tax_id TEXT, phonenumber TEXT, address1 TEXT, address2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT,
            taxexempt INT, separateinvoices INT, disableautocc INT, overideduenotices INT, credit REAL, currency INT, created_at TEXT, updated_at TEXT)');
        $whmcs->exec('CREATE TABLE tblusers_clients (id INTEGER PRIMARY KEY, auth_user_id INT, client_id INT, owner INT, permissions TEXT,
            last_login TEXT, created_at TEXT, updated_at TEXT)');
        $whmcs->prepare('INSERT INTO tblusers VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([7, 'Ada', 'Owner', 'ada@login.test', Hash::make('Whmcs-Pass-1'), '', null, null, '2024-01-01 00:00:00', '2025-01-01 00:00:00']);
        $whmcs->exec("INSERT INTO tblclients VALUES (300, 'Ada', 'Owner', '', 'billing@company.test', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'GR',
            0, 0, 0, 0, 5.00, 0, '2024-01-01 00:00:00', '2025-01-01 00:00:00')");
        $whmcs->exec("INSERT INTO tblusers_clients VALUES (1, 7, 300, 1, NULL, NULL, '2024-01-01 00:00:00', NULL)");

        $command = $this->command($whmcs);
        $command->secondFactor = new WhmcsSecondFactor(null);
        try {
            (new ReflectionMethod($command, 'importUsers'))->invoke($command);
        } catch (Throwable $e) {
            $this->fail('The import aborted on an account with currency 0 and 5.00 credit: ' . $e->getMessage());
        }
        $this->assertSame(1, Credit::query()->where('client_id', 300)->count(), 'its 5.00 credit is kept');
        $this->assertSame('EUR', Credit::query()->where('client_id', 300)->value('currency_code'), 'no default currency in the source: EUR');
        $this->assertStringContainsString('1 WHMCS account(s) whose currency names no currency, imported in the default currency EUR (#300).', $this->output->fetch());
    }

    /** S6-IMPORT-1: the one rule (owner's decision): the account's currency, else WHMCS's default currency, else EUR. */
    public function test_s6_import_1_an_account_without_a_currency_takes_the_default_currency_else_eur(): void
    {
        $whmcs = $this->pdo();
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT, `default` INT)');
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR', 0), (2, 'USD', 1), (3, 'GBP', 0)");
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $whmcs->exec('INSERT INTO tblclients VALUES (1, 3), (2, 0), (3, 9), (4, 1)');

        $currencies = new WhmcsClientCurrency($whmcs);
        $this->assertSame(['GBP', 'USD', 'USD', 'EUR'], array_map($currencies->code(...), [1, 2, 3, 4]));
        $this->assertSame([2, 3], $currencies->defaulted);

        $whmcs->exec('UPDATE tblcurrencies SET `default` = 0');
        $this->assertSame('EUR', (new WhmcsClientCurrency($whmcs))->code(2), 'no default currency: EUR');
    }

    /**
     * S6-IMPORT-2: an Unpaid Mass Pay parent with a partial payment is imported as an ordinary invoice billing its
     * Unpaid children again: the customer owes parent remainder + children, and nothing refuses or names it.
     */
    public function test_s6_import_2_an_unpaid_mass_pay_parent_with_a_part_payment_does_not_bill_its_children_twice(): void
    {
        $clientId = User::factory()->create()->client()->id;
        $whmcs = $this->pdo();
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR')");
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $whmcs->exec("INSERT INTO tblclients VALUES ({$clientId}, 1)");
        $whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        $whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT)');
        foreach ([[801, 10], [802, 15]] as [$child, $amount]) {
            $whmcs->exec("INSERT INTO tblinvoices VALUES ({$child}, {$clientId}, '', '2026-10-01', '2026-10-10', NULL, 'Unpaid', {$amount}, 0, 0)");
            $whmcs->exec('INSERT INTO tblinvoiceitems VALUES (' . ($child + 5000) . ", {$child}, {$clientId}, 'Hosting', 0, 'Line', {$amount}, 0)");
        }
        $whmcs->exec("INSERT INTO tblinvoices VALUES (900, {$clientId}, '', '2026-10-02', '2026-10-10', NULL, 'Unpaid', 25, 0, 0)");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (6001, 900, {$clientId}, 'Invoice', 801, 'Invoice #801', 10, 0)");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (6002, 900, {$clientId}, 'Invoice', 802, 'Invoice #802', 15, 0)");
        $whmcs->exec("INSERT INTO tblaccounts VALUES (7001, {$clientId}, 900, 'banktransfer', '2026-10-03 10:00:00', 10, 0, 0, 'wire-1')");

        // Owner's decision (2026-11-08, KISS): no rule of the importer's own for it. The import refuses to run on it, named
        // with its children, and staff settle it in WHMCS before the dump (cutover-runbook.md); the reviewer's assertion
        // on the balance after the import steps is replaced by this one.
        $command = $this->command($whmcs);
        $this->assertSame([900 => [801, 802]], (new ReflectionMethod($command, 'unsettledMassPay'))->invoke($command));

        $whmcs->exec('CREATE TABLE tblconfiguration (setting TEXT, value TEXT)');
        ImportFromWhmcs::$connect = fn () => $whmcs;
        putenv('WHMCS_SOURCE_PASSWORD=secret');
        try {
            $refused = 'WHMCS unpaid Mass Pay invoice(s) with a part payment or a line of another kind: #900 (children #801, #802).';
            $source = ['dbname' => 'whmcs_copy', '--source-user' => 'reader', '--source-host' => 'db.example.test'];
            $this->artisan('app:import-from-whmcs', $source + ['--dry-run' => true])->assertFailed()->expectsOutputToContain($refused);
            $this->artisan('app:import-from-whmcs', $source)->assertFailed()->expectsOutputToContain($refused)
                ->doesntExpectOutputToContain('--confirm-database');
        } finally {
            ImportFromWhmcs::$connect = null;
            putenv('WHMCS_SOURCE_PASSWORD');
        }
        $this->assertTrue(User::query()->exists(), 'nothing emptied');

        // A late fee on an unpaid Mass Pay that took no money: no parent by the importer's rule either, refused too.
        $whmcs->exec('DELETE FROM tblaccounts');
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (6003, 900, {$clientId}, 'LateFee', 0, 'Late fee', 2, 0)");
        $command = $this->command($whmcs);
        $this->assertSame([900 => [801, 802]], (new ReflectionMethod($command, 'unsettledMassPay'))->invoke($command));

        // Settled in WHMCS (no money on it, only its children's lines): a Mass Pay parent again, dropped by the import.
        $whmcs->exec('DELETE FROM tblinvoiceitems WHERE id = 6003');
        $command = $this->command($whmcs);
        $this->assertSame([], (new ReflectionMethod($command, 'unsettledMassPay'))->invoke($command));
    }

    /** A source with tblhosting, tblhostingaddons, tblinvoices and tblinvoiceitems for the cancelledRenewals check. */
    private function renewalsSource(): PDO
    {
        $whmcs = $this->pdo();
        $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT, billingcycle TEXT, nextduedate TEXT, nextinvoicedate TEXT)');
        $whmcs->exec('CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, hostingid INT, status TEXT, billingcycle TEXT, nextduedate TEXT, nextinvoicedate TEXT)');
        $whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, date TEXT, duedate TEXT, status TEXT)');
        $whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, amount REAL, duedate TEXT)');

        return $whmcs;
    }

    /**
     * S6-IMPORT-3: an Active addon whose 2026-11-01 renewal (an «Addon» line) was cancelled and nextinvoicedate moved to
     * 2026-12-01 is the owner's refused case for a service; cancelledRenewals reads only Hosting lines and tblhosting.
     */
    public function test_s6_import_3_a_cancelled_addon_renewal_refuses_the_import(): void
    {
        $whmcs = $this->renewalsSource();
        // The parent is paid ahead and billed normally; the addon shares its day of the month.
        $whmcs->exec("INSERT INTO tblhosting VALUES (10, 1, 'Active', 'Monthly', '2026-12-01', '2026-12-01')");
        $whmcs->exec("INSERT INTO tblhostingaddons VALUES (20, 10, 'Active', 'Monthly', '2026-11-01', '2026-12-01')");
        $whmcs->exec("INSERT INTO tblinvoices VALUES (500, 1, '2026-10-25', '2026-11-01', 'Cancelled')");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (1, 500, 1, 'Addon', 20, 5, '2026-11-01')");
        // Control: the same for a service (#12) is refused.
        $whmcs->exec("INSERT INTO tblhosting VALUES (12, 1, 'Active', 'Monthly', '2026-11-01', '2026-12-01')");
        $whmcs->exec("INSERT INTO tblinvoices VALUES (501, 1, '2026-10-25', '2026-11-01', 'Cancelled')");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (3, 501, 1, 'Hosting', 12, 5, '2026-11-01')");

        $command = $this->command($whmcs);
        $cancelled = (new ReflectionMethod($command, 'cancelledRenewals'))->invoke($command);

        $this->assertSame([12], array_slice($cancelled, 0, 1), 'control: the cancelled service renewal is refused');
        $this->assertNotSame([12], $cancelled, 'the addon whose renewal WHMCS invoiced and staff cancelled is refused as a service\'s is');
        $this->assertSame([12, 'addon #20'], $cancelled);

        // The same exclusions as a service: an addon imported ended with its service, and one billed again in WHMCS.
        $whmcs->exec("UPDATE tblhosting SET domainstatus = 'Terminated' WHERE id = 10");
        $this->assertSame([12], (new ReflectionMethod($command, 'cancelledRenewals'))->invoke($command));
        $whmcs->exec("UPDATE tblhosting SET domainstatus = 'Active' WHERE id = 10");
        $whmcs->exec('UPDATE tblhostingaddons SET nextinvoicedate = nextduedate WHERE id = 20');
        $this->assertSame([12], (new ReflectionMethod($command, 'cancelledRenewals'))->invoke($command));
    }

    /**
     * S6-IMPORT-4: a live service whose renewal invoice for nextduedate was deleted (its line kept, the invoice row gone)
     * and nextinvoicedate moved past it: WHMCS never bills that period again; the INNER JOIN on tblinvoices misses it.
     */
    public function test_s6_import_4_a_deleted_renewal_invoice_refuses_the_import(): void
    {
        $whmcs = $this->renewalsSource();
        $due = now()->subDays(3)->toDateString();
        $next = now()->addDays(27)->toDateString();
        $whmcs->exec("INSERT INTO tblhosting VALUES (11, 1, 'Active', 'Monthly', '{$due}', '{$next}')");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (2, 999, 1, 'Hosting', 11, 10, '{$due}')"); // invoice #999 deleted
        // Control: the same service shape (#12) with its renewal cancelled, not deleted, is refused.
        $whmcs->exec("INSERT INTO tblhosting VALUES (12, 1, 'Active', 'Monthly', '{$due}', '{$next}')");
        $whmcs->exec("INSERT INTO tblinvoices VALUES (501, 1, '{$due}', '{$due}', 'Cancelled')");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (3, 501, 1, 'Hosting', 12, 10, '{$due}')");

        $command = $this->command($whmcs);
        $cancelled = (new ReflectionMethod($command, 'cancelledRenewals'))->invoke($command);

        $this->assertContains(12, $cancelled, 'control: the cancelled renewal is refused');

        $this->assertContains(11, $cancelled, 'a renewal invoice staff deleted is refused as one they cancelled');

        // No false positives: an old deleted invoice of another period (#13, never invoiced for its due date, its next
        // invoice date moved by hand), and one whose period was invoiced again after the deleted one (#14).
        $old = now()->subYear()->toDateString();
        $whmcs->exec("INSERT INTO tblhosting VALUES (13, 1, 'Active', 'Monthly', '{$due}', '{$next}')");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (4, 998, 1, 'Hosting', 13, 10, '{$old}')");
        $whmcs->exec("INSERT INTO tblhosting VALUES (14, 1, 'Active', 'Monthly', '{$due}', '{$next}')");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (5, 997, 1, 'Hosting', 14, 10, '{$due}')");
        $whmcs->exec("INSERT INTO tblinvoices VALUES (502, 1, '{$due}', '{$due}', 'Unpaid')");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (6, 502, 1, 'Hosting', 14, 10, '{$due}')");
        $this->assertSame([11, 12], (new ReflectionMethod($command, 'cancelledRenewals'))->invoke($command));
    }
}
