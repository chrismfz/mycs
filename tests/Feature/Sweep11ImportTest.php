<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Client;
use App\Models\CronStat;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\DomainRenewalService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Nixpal\Licensing\Tests\WhmcsSchema;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use Throwable;

/**
 * Sweep 11, adversarial verification of «import». Made-up rows only.
 *
 * S11-IMPORT-1: a domain renewal WHMCS invoiced and staff DELETED (its line left with no tblinvoices row) is billed
 * again by the first cron; its twin on a Cancelled invoice is given up (sweep 4 C-FIRST-RUN-3), and the same shape on a
 * service or an addon stops the import (cancelledRenewals).
 * S11-IMPORT-2: the import empties an instance that has been live (its own daily runs, its own invoices) on the same
 * --confirm-database it took before the cut-over.
 */
class Sweep11ImportTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['settings.default_currency' => 'USD']);
        $this->client = User::factory()->create()->client();
    }

    protected function tearDown(): void
    {
        ImportFromWhmcs::$connect = null;
        putenv('WHMCS_SOURCE_PASSWORD');
        parent::tearDown();
    }

    /** WHMCS's domain tables as ImportDomainsTest has them, with nextinvoicedate, and its invoices. */
    private function whmcsWithDomains(): void
    {
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT, prefix TEXT, suffix TEXT, format INT, rate REAL, "default" INT)',
            "INSERT INTO tblcurrencies VALUES (1, 'EUR', '', '€', 3, 1, 1)",
            'CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)',
            "INSERT INTO tblclients VALUES ({$this->client->id}, 1)",
            'CREATE TABLE tblconfiguration (setting TEXT, value TEXT)',
            "INSERT INTO tblconfiguration VALUES ('DateFormat', 'DD/MM/YYYY')",
            'CREATE TABLE tbldomainpricing (id INTEGER PRIMARY KEY, extension TEXT, autoreg TEXT, eppcode INT, dnsmanagement INT, emailforwarding INT,
                idprotection INT, grace_period INT, redemption_grace_period INT, "order" INT)',
            "INSERT INTO tbldomainpricing VALUES (3, '.com', 'openprovider', 1, 1, 1, 1, 30, 30, 3)",
            'CREATE TABLE tblpricing (id INTEGER PRIMARY KEY, type TEXT, relid INT, currency INT, tsetupfee REAL, msetupfee REAL, qsetupfee REAL, ssetupfee REAL,
                asetupfee REAL, bsetupfee REAL, monthly REAL, quarterly REAL, semiannually REAL, annually REAL, biennially REAL, triennially REAL)',
            'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, orderid INT, type TEXT, registrationdate TEXT, domain TEXT, firstpaymentamount REAL,
                recurringamount REAL, registrar TEXT, registrationperiod INT, expirydate TEXT, status TEXT, nextduedate TEXT, nextinvoicedate TEXT, additionalnotes TEXT,
                dnsmanagement INT, emailforwarding INT, idprotection INT, donotrenew INT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE tbldomainsadditionalfields (id INTEGER PRIMARY KEY, domainid INT, name TEXT, value TEXT)',
            'CREATE TABLE tbldomains_extra (id INTEGER PRIMARY KEY, domain_id INT, name TEXT, value TEXT)',
            'CREATE TABLE wHandles (id INTEGER PRIMARY KEY, user_id INT, registrar TEXT, type TEXT, handle TEXT, data TEXT)',
            'CREATE TABLE wDomain_handle (id INTEGER PRIMARY KEY, type TEXT, domain_id INT, handle_id INT)',
            'CREATE TABLE tbldomainreminders (id INTEGER PRIMARY KEY, domain_id INT, date TEXT, recipients TEXT, type INT, days_before_expiry INT)',
            'CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT, nextduedate TEXT, nextinvoicedate TEXT)',
            'CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
                status TEXT, total REAL, credit REAL, taxrate REAL)',
            'CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
                amount REAL, taxed INT, duedate TEXT)',
            'CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
                amountout REAL, fees REAL, transid TEXT)',
        ] as $sql) {
            $this->whmcs->exec($sql);
        }
    }

    /** An Active WHMCS domain that renews, expiring (and due) on $expiry; WHMCS invoiced that renewal (nextinvoicedate a year on). */
    private function whmcsDomain(int $id, string $name, string $expiry): void
    {
        $nextDue = $expiry;
        $invoiced = date('Y-m-d', strtotime($nextDue . ' +1 year'));
        $this->whmcs->exec("INSERT INTO tbldomains VALUES ({$id}, {$this->client->id}, 0, 'Register', '2020-03-01', '{$name}', 10, 12.5, 'openprovider', 1,
            '{$expiry}', 'Active', '{$nextDue}', '{$invoiced}', '', 0, 0, 0, 0, '2020-03-01 10:00:00', '2026-03-01 10:00:00')");
    }

    /** The importer's own steps for domains and their invoices; what it said, on one line. */
    private function importDomainsAndInvoices(): array
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output = new BufferedOutput));
        foreach (['importDomains', 'importInvoices', 'importInvoiceItems'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
        $refused = (new ReflectionMethod($command, 'cancelledRenewals'))->invoke($command);

        return [(string) preg_replace('/\s+/', ' ', $output->fetch()), $refused];
    }

    /**
     * S11-IMPORT-1. WHMCS invoiced the renewal of two domains expiring in 10 days (nextinvoicedate a year past
     * nextduedate) and staff took both invoices back: one Cancelled, one deleted (its line is left, with no invoice).
     * WHMCS bills neither again. Here the cancelled one is given up; the deleted one must not be billed quietly by the
     * first cron either: given up, or the import refuses it before the dump, or names it.
     */
    public function test_import1_a_domain_renewal_whose_whmcs_invoice_was_deleted_is_not_billed_again_quietly(): void
    {
        $this->freezeTime();
        $this->whmcsWithDomains();
        $expiry = today()->addDays(10)->toDateString();
        $invoiced = today()->subDays(20)->toDateString();
        $this->whmcsDomain(81, 'deleted.example.com', $expiry);
        $this->whmcsDomain(82, 'cancelled.example.com', $expiry);
        $this->whmcsDomain(83, 'never.example.com', $expiry);
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (2, {$this->client->id}, '', '{$invoiced}', '{$expiry}', '0000-00-00 00:00:00', 'Cancelled', 12.5, 0, 0)");
        // #999 has no tblinvoices row: deleted in WHMCS, its line stays behind.
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES
            (21, 999, {$this->client->id}, 'Domain', 81, 'Domain Renewal - deleted.example.com - 1 Year/s', 12.5, 0, '{$expiry}'),
            (22, 2, {$this->client->id}, 'Domain', 82, 'Domain Renewal - cancelled.example.com - 1 Year/s', 12.5, 0, '{$expiry}')");

        [$said, $refused] = $this->importDomainsAndInvoices();

        $domains = Domain::query()->findMany([81, 82, 83])->keyBy('id');
        $this->assertSame([$expiry, $expiry, $expiry], $domains->map(fn (Domain $d) => $d->expires_at->toDateString())->values()->all());
        $this->assertTrue($domains[81]->auto_renew);
        $until = today()->addDays(15);
        $billing = app(RenewalBilling::class);

        // The twin: cancelled in WHMCS, given up here, never billed again (sweep 4 C-FIRST-RUN-3).
        $this->assertNotNull(InvoiceItem::query()->findOrFail(22)->forgone_at);
        $this->assertSame(0, DB::transaction(fn () => $billing->billDomainRenewal(82, $until)), 'the cancelled twin is given up');
        // The control: a domain WHMCS never invoiced is billed, as it should be.
        $this->assertNull(DomainRenewalService::billedLine($domains[83], $domains[83]->expires_at->copy()->startOfDay()));

        $before = Invoice::query()->count();
        $billed = DB::transaction(fn () => $billing->billDomainRenewal(81, $until));
        $named = str_contains($said, 'deleted.example.com') || preg_match('/#81\b/', $said) === 1;
        $stopped = $refused !== [];

        $this->assertTrue($billed === 0 || $named || $stopped,
            'A domain renewal WHMCS invoiced and staff deleted comes with no line and no word: the first cron bills it again ('
            . (Invoice::query()->count() - $before) . ' new unpaid invoice), credit or a saved card pays it and the year is bought at the registrar.'
            . ' Its twin on a Cancelled invoice is given up; a service or an addon in this shape stops the import. The import said: ' . $said);
    }

    /**
     * S11-IMPORT-2. The instance has been live: its own daily run, an invoice made here after the import. The documented
     * command, with the same --confirm-database it took before the cut-over, must not empty it.
     */
    public function test_import2_the_import_does_not_empty_an_instance_that_has_been_live_on_the_same_confirmation(): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        WhmcsSchema::create($whmcs);
        foreach (['tblconfiguration', 'tblcurrencies'] as $table) {
            $whmcs->exec("CREATE TABLE IF NOT EXISTS {$table} (id INTEGER PRIMARY KEY)");
        }
        ImportFromWhmcs::$connect = fn () => $whmcs;
        putenv('WHMCS_SOURCE_PASSWORD=secret');

        // Three days live: the daily run's marks and counts, and an invoice of this instance's own.
        foreach ([2, 1, 0] as $daysAgo) {
            CronStat::create(['key' => 'invoices_created', 'value' => 1, 'date' => today()->subDays($daysAgo)->toDateString()]);
        }
        Setting::updateOrCreate(['key' => 'last_cron_run', 'settingable_type' => CronStat::class], ['value' => now()->toDateTimeString(), 'type' => 'string']);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID]);
        $this->assertNull(ImportFromWhmcs::running(), 'no import is half way');
        $target = (string) DB::connection()->getDatabaseName();

        $code = null;
        try {
            $code = $this->withoutMockingConsoleOutput()->artisan('app:import-from-whmcs', ['dbname' => 'whmcs_copy', '--source-user' => 'reader',
                '--source-host' => 'db.example.test', '--confirm-database' => $target]);
        } catch (Throwable) {
            // the tiny source fails somewhere past the emptying: what matters is below
        }

        $this->assertTrue(DB::table('invoices')->where('id', $invoice->id)->exists() && DB::table('cron_stats')->count() === 3,
            'The import emptied an instance that had run its own daily cron for three days and held an invoice of its own (exit ' . var_export($code, true)
            . '): --confirm-database is the same name before and after the cut-over, and nothing else asks.');
    }
}
