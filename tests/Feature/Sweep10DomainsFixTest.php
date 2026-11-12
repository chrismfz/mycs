<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Sweep 10, what the import says (S10-IMPORT-1: the duplicate unpaid renewal named; S10-DOMAINS-1 and -2, known: the
 * domains and lines to look at by hand at the cut-over), on WHMCS's domains as the importer writes them (the harness of
 * Sweep10DomainsTest). Example names; Http is faked.
 */
class Sweep10DomainsFixTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Cache::flush();
        config(['settings.default_currency' => 'USD', 'settings.domain_reminder_days' => '30,7,1,-3', 'settings.domain_billing_enabled' => true,
            'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false, 'audit.console' => true]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->client = User::factory()->create()->client();
        $this->client->primaryContact->forceFill(['email' => 'owner@example.test'])->save();
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
            "INSERT INTO tbldomainpricing VALUES (1, '.gr', 'grepp', 1, 0, 0, 0, 15, 15, 1), (2, '.com.gr', 'grepp', 1, 0, 0, 0, 15, 15, 2),
                (3, '.com', 'openprovider', 1, 1, 1, 1, 30, 30, 3)",
            'CREATE TABLE tblpricing (id INTEGER PRIMARY KEY, type TEXT, relid INT, currency INT, tsetupfee REAL, msetupfee REAL, qsetupfee REAL, ssetupfee REAL,
                asetupfee REAL, bsetupfee REAL, monthly REAL, quarterly REAL, semiannually REAL, annually REAL, biennially REAL, triennially REAL)',
            'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, orderid INT, type TEXT, registrationdate TEXT, domain TEXT, firstpaymentamount REAL,
                recurringamount REAL, registrar TEXT, registrationperiod INT, expirydate TEXT, status TEXT, nextduedate TEXT, additionalnotes TEXT,
                dnsmanagement INT, emailforwarding INT, idprotection INT, donotrenew INT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE tbldomainsadditionalfields (id INTEGER PRIMARY KEY, domainid INT, name TEXT, value TEXT)',
            'CREATE TABLE tbldomains_extra (id INTEGER PRIMARY KEY, domain_id INT, name TEXT, value TEXT)',
            'CREATE TABLE wHandles (id INTEGER PRIMARY KEY, user_id INT, registrar TEXT, type TEXT, handle TEXT, data TEXT)',
            'CREATE TABLE wDomain_handle (id INTEGER PRIMARY KEY, type TEXT, domain_id INT, handle_id INT)',
            'CREATE TABLE tbldomainreminders (id INTEGER PRIMARY KEY, domain_id INT, date TEXT, recipients TEXT, type INT, days_before_expiry INT)',
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function domain(int $id, string $name, string $status, string $registrar, array $extra = []): void
    {
        $row = array_merge(['userid' => $this->client->id, 'orderid' => 0, 'type' => 'Register', 'registrationdate' => '2020-03-01', 'domain' => $name,
            'firstpaymentamount' => 10, 'recurringamount' => 12.5, 'registrar' => $registrar, 'registrationperiod' => 1, 'expirydate' => '2027-03-01',
            'status' => $status, 'nextduedate' => $extra['expirydate'] ?? '2027-03-01', 'additionalnotes' => '', 'dnsmanagement' => 0, 'emailforwarding' => 0,
            'idprotection' => 0, 'donotrenew' => 0, 'created_at' => '2020-03-01 10:00:00', 'updated_at' => '2026-03-01 10:00:00'], $extra);
        $columns = implode(', ', array_keys($row));
        $this->whmcs->prepare("INSERT INTO tbldomains (id, {$columns}) VALUES (?, " . implode(', ', array_fill(0, count($row), '?')) . ')')
            ->execute([$id, ...array_values($row)]);
    }

    /** A WHMCS invoice with its lines: [id, type, relid, description, amount, duedate]. */
    private function invoice(int $id, string $status, ?string $datepaid, array $lines, ?int $userid = null): void
    {
        $this->whmcs->prepare('INSERT INTO tblinvoices VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0)')
            // Each invoice on its own date, as WHMCS has them (its lines are dated by it here: R2-4).
            ->execute([$id, $userid ?? $this->client->id, '', $datepaid !== null ? substr($datepaid, 0, 10) : ($lines[0][5] ?? '2026-01-01'), '2026-01-15',
                $datepaid ?? '0000-00-00 00:00:00', $status,
                array_sum(array_column($lines, 4))]);
        foreach ($lines as [$lineId, $type, $relid, $description, $amount, $due]) {
            $this->whmcs->prepare('INSERT INTO tblinvoiceitems VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)')
                ->execute([$lineId, $id, $userid ?? $this->client->id, $type, $relid, $description, $amount, $due]);
        }
    }

    /** The full importer's domain, invoice and line steps, as it runs them. */
    private function import(): string
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        foreach (['importDomains', 'importInvoices', 'importInvoiceItems'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }

        return $output->fetch();
    }

    /** The cron's domain billing for one domain (CronJob: billDomainRenewal, then addStageFee). */
    private function bill(int $domainId): int
    {
        $billing = app(RenewalBilling::class);
        $made = DB::transaction(fn () => $billing->billDomainRenewal($domainId, now()->addDays(RenewalBilling::domainInvoiceDays())));
        DB::transaction(fn () => $billing->addStageFee($domainId));

        return $made;
    }

    /** @var list<int> the years of each renewal asked of Openprovider */
    private array $renewed = [];

    /** The imported Openprovider connection with credentials, on (as staff set it up after the cut-over), its record faked. */
    private function openproviderOn(int $domainId, string $sld, string $expiry): void
    {
        $connection = DomainRegistrarConnection::query()->where('registrar', 'openprovider')->firstOrFail();
        $connection->forceFill(['is_active' => true, 'mode' => 'production'])->save();
        $connection->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        Domain::query()->whereKey($domainId)->update(['registrar_domain_id' => '30391502']);
        $record = ['id' => 30391502, 'domain' => ['name' => $sld, 'extension' => 'com'], 'status' => 'ACT', 'expiration_date' => $expiry . ' 10:00:00',
            'name_servers' => [['name' => 'ns1.example.net'], ['name' => 'ns2.example.net']]];
        Http::fake(function (Request $request) use (&$record) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/renew')) {
                $years = (int) $request->data()['period'];
                $this->renewed[] = $years;
                $record['expiration_date'] = Carbon::parse($record['expiration_date'])->addYears($years)->format('Y-m-d H:i:s');

                return Http::response(['code' => 0, 'data' => ['success' => true]]);
            }

            return Http::response(['data' => $record]);
        });
    }

    // ── S10-DOMAINS-1 (known): the import names the live domains the date step ends before any registrar read ─────

    private const ENDED = ': the date step marks them Deleted (those a month past it within minutes of the import, the others once that month passes'
        . ' while their connection is off), before any registrar read, and a Deleted domain is never synced again.'
        . ' If one is alive at its registrar, correct its expiry in WHMCS before the final dump (cutover-runbook).';

    public function test_the_import_names_the_live_domains_the_dates_will_end_and_the_date_step_ends_them(): void
    {
        Carbon::setTestNow('2026-11-11 10:00:00');
        $this->domain(401, 'alive.example.com', 'Expired', 'openprovider', ['expirydate' => '2026-07-01']); // past 30 + 30 days, and a month
        $this->domain(402, 'recent.example.com', 'Expired', 'openprovider', ['expirydate' => '2026-10-20']); // in its grace
        $this->domain(403, 'ahead.example.com', 'Active', 'openprovider');
        $this->domain(404, 'byhand.example.com', 'Expired', 'enom', ['expirydate' => '2026-07-01']); // no driver: manual, by its dates as always
        $this->domain(405, 'gone.example.com', 'Cancelled', 'openprovider', ['expirydate' => '2026-07-01']);

        $output = $this->import();

        $this->assertStringContainsString('1 live domain(s) whose WHMCS expiry is past their grace and redemption (#401)' . self::ENDED, $output);
        $this->assertStringNotContainsString('alive.example.com:', $output, 'ids only');

        // What the line says is what happens (the rule of #308, unchanged): the scheduler, minutes after the import.
        $this->artisan('domains:sync', ['--scheduled' => true]);
        $this->assertSame([401 => DomainStatus::Deleted, 402 => DomainStatus::Grace, 403 => DomainStatus::Active],
            Domain::query()->whereKey([401, 402, 403])->orderBy('id')->get()->mapWithKeys(fn (Domain $domain) => [$domain->id => $domain->status])->all());
    }

    public function test_many_of_them_are_counted_not_listed(): void
    {
        Carbon::setTestNow('2026-11-11 10:00:00');
        foreach (range(1, 21) as $n) {
            $this->domain(500 + $n, "lapsed{$n}.example.com", 'Expired', 'openprovider', ['expirydate' => '2026-07-01']);
        }

        $output = $this->import();

        $this->assertStringContainsString('21 live domain(s) whose WHMCS expiry is past their grace and redemption' . self::ENDED, $output);
        $this->assertStringNotContainsString('#501', $output);
    }

    /** S10-DOMAINS-2 (known): the paid lines left to be renewed are named one by one, as before; the runbook points here. */
    public function test_a_paid_renewal_left_to_be_renewed_is_named_by_the_import(): void
    {
        Carbon::setTestNow('2026-11-11 10:00:00');
        $this->domain(402, 'offset.example.com', 'Active', 'openprovider', ['expirydate' => '2027-06-15', 'nextduedate' => '2027-08-01']);
        $this->invoice(1, 'Paid', '2026-07-20 09:00:00', [
            [12, 'Domain', 402, 'Domain Renewal - offset.example.com - 1 Year/s (01/08/2026 - 31/07/2027)', 12.5, '2026-08-01'],
        ]);

        $output = $this->import();

        $this->assertStringContainsString("Domain #402: invoice #1 paid its renewal to 2027-08-01 (1 year(s)), WHMCS's expiry is 2027-06-15: "
            . 'left to be renewed (the registrar read first: only the years it lacks).', $output);
    }

    // ── S10-IMPORT-1: an unpaid renewal of a period a paid one covers is named ────────────────────────────────────

    public function test_the_duplicate_unpaid_renewal_is_named_with_its_ids_and_stays_as_it_came(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:00');
        $this->domain(301, 'dup.example.com', 'Active', 'openprovider', ['expirydate' => '2026-11-20', 'nextduedate' => '2026-11-20']);
        $this->invoice(1, 'Paid', '2025-11-10 09:00:00', [
            [11, 'Domain', 301, 'Domain Renewal - dup.example.com - 1 Year/s (21/11/2025 - 20/11/2026)', 12.5, '2025-11-21'],
        ]);
        $this->invoice(2, 'Unpaid', null, [
            [21, 'Domain', 301, 'Domain Renewal - dup.example.com - 1 Year/s (21/11/2025 - 20/11/2026)', 12.5, '2025-11-21'],
        ]);

        $output = $this->import();

        $this->assertStringContainsString('1 unpaid domain renewal(s) of a period a paid renewal of the same domain already covers (a duplicate invoice: paid here, it renews nothing): '
            . 'domain #301 invoice #2 line #21. Cancel each in WHMCS before the final dump, or here after the import (cutover-runbook).', $output);
        $this->assertStringNotContainsString('dup.example.com', $output, 'ids only');
        $line = InvoiceItem::query()->findOrFail(21);
        $this->assertSame([Invoice::STATUS_UNPAID, null, '2025-11-21', '2026-11-21', null],
            [Invoice::query()->findOrFail(2)->status, $line->kind, $line->period_start->toDateString(), $line->period_end->toDateString(), $line->applied_at],
            'named, not handled: it comes as before');
    }

    /**
     * Not a duplicate: staff renewed the year by hand at the registrar (WHMCS's expiry shows it) and the invoice is owed,
     * and the next year's renewal beside last year's paid one. Both stay payable and unnamed.
     */
    public function test_an_unpaid_renewal_with_no_paid_twin_stays_payable_and_unnamed(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:00');
        $this->domain(302, 'owed.example.com', 'Active', 'openprovider', ['expirydate' => '2026-11-20', 'nextduedate' => '2026-11-20']);
        $this->invoice(3, 'Unpaid', null, [
            [31, 'Domain', 302, 'Domain Renewal - owed.example.com - 1 Year/s (21/11/2025 - 20/11/2026)', 12.5, '2025-11-21'],
        ]);
        $this->domain(303, 'next.example.com', 'Active', 'openprovider', ['expirydate' => '2026-11-20', 'nextduedate' => '2026-11-20']);
        $this->invoice(4, 'Paid', '2025-11-10 09:00:00', [
            [41, 'Domain', 303, 'Domain Renewal - next.example.com - 1 Year/s (21/11/2025 - 20/11/2026)', 12.5, '2025-11-21'],
        ]);
        $this->invoice(5, 'Unpaid', null, [
            [51, 'Domain', 303, 'Domain Renewal - next.example.com - 1 Year/s (21/11/2026 - 20/11/2027)', 12.5, '2026-11-21'],
        ]);
        // A cancelled twin is no payable duplicate either.
        $this->invoice(6, 'Cancelled', null, [
            [61, 'Domain', 303, 'Domain Renewal - next.example.com - 1 Year/s (21/11/2025 - 20/11/2026)', 12.5, '2025-11-21'],
        ]);

        $output = $this->import();

        $this->assertStringNotContainsString('already covers', $output);
        foreach ([31 => 3, 51 => 5] as $lineId => $invoiceId) {
            $line = InvoiceItem::query()->findOrFail($lineId);
            $this->assertSame([Invoice::STATUS_UNPAID, null, null], [Invoice::query()->findOrFail($invoiceId)->status, $line->kind, $line->applied_at]);
            $this->assertNotNull($line->period_start);
        }
    }
}
