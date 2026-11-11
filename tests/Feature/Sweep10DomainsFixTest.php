<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\DomainRenewalService;
use App\Services\Service\RetryModuleAction;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
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
 * Sweep 10, the fixes' neighbours (S10-DOMAINS-1, S10-DOMAINS-2, S10-IMPORT-1): what must stay as it was beside each rule,
 * on WHMCS's domains as the importer writes them (the harness of Sweep10DomainsTest). Example names; Http is faked.
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

    // ── S10-DOMAINS-1: never ended by the dates before its registrar was read once ────────────────────────────────

    public function test_the_import_says_which_live_domains_wait_for_their_first_registrar_read(): void
    {
        Carbon::setTestNow('2026-11-11 10:00:00');
        $this->domain(401, 'alive.example.com', 'Expired', 'openprovider', ['expirydate' => '2026-07-01']); // past 30 + 30 days
        $this->domain(402, 'recent.example.com', 'Expired', 'openprovider', ['expirydate' => '2026-10-20']); // in its grace
        $this->domain(403, 'ahead.example.com', 'Active', 'openprovider');
        $this->domain(404, 'byhand.example.com', 'Expired', 'enom', ['expirydate' => '2026-07-01']); // no driver: manual, by its dates
        $this->domain(405, 'gone.example.com', 'Cancelled', 'openprovider', ['expirydate' => '2026-07-01']);

        $output = $this->import();

        $this->assertStringContainsString('1 live domain(s) whose WHMCS expiry is past their grace and redemption (#401): not ended by the dates, '
            . 'they wait for their first registrar read (switch the connections on, sync, then look at the domains never synced: cutover-runbook).', $output);
    }

    public function test_many_of_them_are_counted_not_listed(): void
    {
        Carbon::setTestNow('2026-11-11 10:00:00');
        foreach (range(1, 21) as $n) {
            $this->domain(500 + $n, "lapsed{$n}.example.com", 'Expired', 'openprovider', ['expirydate' => '2026-07-01']);
        }

        $output = $this->import();

        $this->assertStringContainsString('21 live domain(s) whose WHMCS expiry is past their grace and redemption: not ended by the dates', $output);
        $this->assertStringNotContainsString('#501', $output);
    }

    public function test_a_never_read_domain_still_moves_through_its_stages_by_the_dates_but_is_never_ended(): void
    {
        Carbon::setTestNow('2026-11-11 10:00:00');
        $this->domain(402, 'recent.example.com', 'Active', 'openprovider', ['expirydate' => '2026-11-01']);
        $this->import();

        $this->artisan('domains:sync', ['--scheduled' => true]);
        $this->assertSame(DomainStatus::Grace, Domain::query()->findOrFail(402)->status, 'ten days past its expiry: in its grace, as before');

        Carbon::setTestNow('2026-12-11 10:00:00');
        Cache::flush();
        $this->artisan('domains:sync', ['--scheduled' => true]);
        $this->assertSame(DomainStatus::Redemption, Domain::query()->findOrFail(402)->status, 'forty days past it: in its redemption, as before');

        Carbon::setTestNow('2027-06-11 10:00:00');
        Cache::flush();
        $this->artisan('domains:sync', ['--scheduled' => true]);
        $domain = Domain::query()->findOrFail(402);
        $this->assertSame([DomainStatus::Redemption, null], [$domain->status, $domain->last_synced_at], 'never read: never ended by the dates, however long');
    }

    public function test_a_domain_read_once_whose_connection_is_off_for_good_is_ended_by_the_dates_as_before(): void
    {
        Carbon::setTestNow('2026-11-11 10:00:00');
        $this->domain(401, 'left.example.com', 'Expired', 'openprovider', ['expirydate' => '2026-07-01']);
        $this->import();
        // Read once (the connection was on for a while after the cut-over), then switched off again.
        DB::table('domains')->where('id', 401)->update(['last_synced_at' => '2026-11-11 09:00:00']);

        $this->artisan('domains:sync', ['--scheduled' => true]);

        $this->assertSame(DomainStatus::Deleted, Domain::query()->findOrFail(401)->status, 'a month past its last stage on a connection that is off: ended (#308)');
    }

    public function test_a_manual_registrars_domain_is_ended_by_the_dates_as_before_though_never_read(): void
    {
        Carbon::setTestNow('2026-11-11 10:00:00');
        $this->domain(404, 'byhand.example.com', 'Expired', 'enom', ['expirydate' => '2026-07-01']);
        $output = $this->import();
        $this->assertSame(DomainRegistrarConnection::MANUAL, Domain::query()->findOrFail(404)->effectiveRegistrarConnection()->registrar);
        $this->assertStringNotContainsString('wait for their first registrar read', $output);

        $this->artisan('domains:sync', ['--scheduled' => true]);

        $this->assertSame(DomainStatus::Deleted, Domain::query()->findOrFail(404)->status, 'no API to read it from: its dates end it');
    }

    // ── S10-DOMAINS-2: short by part of a year, no whole year bought ──────────────────────────────────────────────

    /** The paid line «(01/08/2026 - 31/07/2027)» of a domain WHMCS never renewed (its expiry still at the period's start). */
    private function paidRenewalLeftToRenew(string $whmcsExpiry): void
    {
        Carbon::setTestNow('2026-11-11 10:00:00');
        $this->domain(402, 'offset.example.com', 'Active', 'openprovider', ['expirydate' => $whmcsExpiry, 'nextduedate' => '2027-08-01']);
        $this->invoice(1, 'Paid', '2026-07-20 09:00:00', [
            [12, 'Domain', 402, 'Domain Renewal - offset.example.com - 1 Year/s (01/08/2026 - 31/07/2027)', 12.5, '2026-08-01'],
        ]);
        $this->import();
        $this->assertTrue(DomainRenewalService::waitingLines()->whereKey(12)->exists(), 'precondition: the import leaves the paid line to be renewed');
    }

    public function test_a_paid_renewal_the_registrar_is_short_of_by_a_year_is_renewed_once_as_before(): void
    {
        $this->paidRenewalLeftToRenew('2026-08-01');
        $this->openproviderOn(402, 'offset.example', '2026-08-01');

        $outcome = app(DomainRenewalService::class)->renewLine(12);

        $this->assertSame([DomainRenewalService::APPLIED, [1], '2027-08-01'], [$outcome, $this->renewed, Domain::query()->findOrFail(402)->expires_at->toDateString()]);
        $this->assertNotNull(InvoiceItem::query()->findOrFail(12)->applied_at);
        $this->assertSame(DomainRenewalService::NOTHING, app(DomainRenewalService::class)->renewLine(12), 'once');
        $this->assertSame([1], $this->renewed);
    }

    /** The registrar's expiry some days past the period's start (a sync moves an expiry so): still its year, as before. */
    public function test_a_registrar_expiry_days_past_the_periods_start_is_still_short_by_a_year(): void
    {
        $this->paidRenewalLeftToRenew('2026-08-21');
        $this->openproviderOn(402, 'offset.example', '2026-08-21');

        $outcome = app(DomainRenewalService::class)->renewLine(12);

        $this->assertSame([DomainRenewalService::APPLIED, [1]], [$outcome, $this->renewed]);
    }

    public function test_a_paid_renewal_the_registrar_already_covers_is_adopted_as_before(): void
    {
        $this->paidRenewalLeftToRenew('2026-08-01');
        $this->openproviderOn(402, 'offset.example', '2027-08-01'); // renewed there, WHMCS never read it

        $outcome = app(DomainRenewalService::class)->renewLine(12);

        $this->assertSame([DomainRenewalService::ADOPTED, []], [$outcome, $this->renewed]);
        $this->assertNotNull(InvoiceItem::query()->findOrFail(12)->applied_at);
    }

    public function test_short_by_part_of_a_year_is_a_task_for_staff_opened_once_and_adopted_once_renewed_by_hand(): void
    {
        $this->paidRenewalLeftToRenew('2027-06-15');
        $this->openproviderOn(402, 'offset.example', '2027-06-15');
        $renewals = app(DomainRenewalService::class);

        $this->assertSame(DomainRenewalService::FAILED, $renewals->renewLine(12));
        $this->assertSame(DomainRenewalService::FAILED, $renewals->renewLine(12), 'the next run: read again, nothing bought');
        // Staff's Retry does not buy it either: only by hand at the registrar.
        RetryModuleAction::run(ModuleAction::query()->where('invoice_item_id', 12)->sole());

        $task = ModuleAction::query()->where('domain_id', 402)->where('invoice_item_id', 12)->sole();
        $this->assertSame([ModuleAction::DOMAIN_RENEW, ModuleAction::STATUS_OPEN, 3], [$task->action, $task->status, $task->attempts]);
        $this->assertSame('Paid to 2027-08-01, the registrar has 2027-06-15: short by less than a year, so no whole year is bought for it. '
            . 'If its year is missing, renew it by hand at the registrar (it is then adopted here); if not, leave it. While this line waits, its period counts as billed.', $task->last_error);
        $this->assertSame([], $this->renewed);
        $this->assertNull(InvoiceItem::query()->findOrFail(12)->applied_at);
        $this->assertSame(0, DomainRegistrarLog::query()->where('domain_id', 402)->where('action', 'renew')->count());

        // Renewed by hand at the registrar: the next run finds the period covered and adopts it, the task closed.
        Http::swap(new HttpFactory); // the first fake would answer before a second one
        Http::preventStrayRequests();
        $this->openproviderOn(402, 'offset.example', '2028-06-15');
        $this->assertSame(DomainRenewalService::ADOPTED, $renewals->renewLine(12));
        $this->assertSame([[], ModuleAction::STATUS_COMPLETED], [$this->renewed, $task->fresh()->status]);
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
