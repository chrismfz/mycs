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
use App\Models\ModuleAction;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\DomainReminders;
use App\Services\Domains\DomainRenewalService;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Ops\Health;
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
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Adversarial review of #806 (sweep-10 domains and import fixes). Each test fails on the PR head for the reason its
 * docblock gives. Domains through the importer's own steps, as the PR's tests (Sweep10DomainsFixTest's harness);
 * services through ImportedWhmcsInvoices. Invented rows, example names, Http faked: no registrar is called.
 */
class Rev806Test extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    /** WHMCS's domain tables (the trait's $whmcs holds the services'). */
    private PDO $source;

    private Client $client;

    /** @var list<int> the years of each renewal asked of Openprovider */
    private array $renewed = [];

    /** @var array<string, mixed> Openprovider's record of the one domain */
    private array $record = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── the domains' harness (as Sweep10DomainsFixTest) ───────────────────────────────────────────────────────────

    private function domainsSource(): void
    {
        config(['settings.default_currency' => 'USD', 'settings.domain_reminder_days' => '30,7,1,-3', 'settings.domain_billing_enabled' => true,
            'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false, 'audit.console' => true]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->client = User::factory()->create()->client();
        $this->client->primaryContact->forceFill(['email' => 'owner@example.test'])->save();
        $this->source = new PDO('sqlite::memory:');
        $this->source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
            $this->source->exec($sql);
        }
    }

    private function domain(int $id, string $name, string $status, array $extra = []): void
    {
        $row = array_merge(['userid' => $this->client->id, 'orderid' => 0, 'type' => 'Register', 'registrationdate' => '2020-03-01', 'domain' => $name,
            'firstpaymentamount' => 10, 'recurringamount' => 12.5, 'registrar' => 'openprovider', 'registrationperiod' => 1, 'expirydate' => '2027-03-01',
            'status' => $status, 'nextduedate' => $extra['expirydate'] ?? '2027-03-01', 'additionalnotes' => '', 'dnsmanagement' => 0, 'emailforwarding' => 0,
            'idprotection' => 0, 'donotrenew' => 0, 'created_at' => '2020-03-01 10:00:00', 'updated_at' => '2026-03-01 10:00:00'], $extra);
        $columns = implode(', ', array_keys($row));
        $this->source->prepare("INSERT INTO tbldomains (id, {$columns}) VALUES (?, " . implode(', ', array_fill(0, count($row), '?')) . ')')
            ->execute([$id, ...array_values($row)]);
    }

    /** A WHMCS invoice with its lines: [id, type, relid, description, amount, duedate]. */
    private function domainInvoice(int $id, string $status, ?string $datepaid, array $lines): void
    {
        $this->source->prepare('INSERT INTO tblinvoices VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0)')
            ->execute([$id, $this->client->id, '', $datepaid !== null ? substr($datepaid, 0, 10) : ($lines[0][5] ?? '2026-01-01'), $lines[0][5] ?? '2026-01-15',
                $datepaid ?? '0000-00-00 00:00:00', $status, array_sum(array_column($lines, 4))]);
        foreach ($lines as [$lineId, $type, $relid, $description, $amount, $due]) {
            $this->source->prepare('INSERT INTO tblinvoiceitems VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)')
                ->execute([$lineId, $id, $this->client->id, $type, $relid, $description, $amount, $due]);
        }
    }

    /** The importer's domain, invoice and line steps, as it runs them. */
    private function importDomains(): string
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->source);
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

    /** The imported Openprovider connection with credentials, on, its one record faked ($this->record: a test may move its expiry). */
    private function openproviderOn(int $domainId, string $sld, string $expiry): void
    {
        $connection = DomainRegistrarConnection::query()->where('registrar', 'openprovider')->firstOrFail();
        $connection->forceFill(['is_active' => true, 'mode' => 'production'])->save();
        $connection->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        Domain::query()->whereKey($domainId)->update(['registrar_domain_id' => '30391502']);
        $this->record = ['id' => 30391502, 'domain' => ['name' => $sld, 'extension' => 'com'], 'status' => 'ACT', 'expiration_date' => $expiry . ' 10:00:00',
            'name_servers' => [['name' => 'ns1.example.net'], ['name' => 'ns2.example.net']]];
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/renew')) {
                $years = (int) $request->data()['period'];
                $this->renewed[] = $years;
                $this->record['expiration_date'] = Carbon::parse($this->record['expiration_date'])->addYears($years)->format('Y-m-d H:i:s');

                return Http::response(['code' => 0, 'data' => ['success' => true]]);
            }

            return Http::response(['data' => $this->record]);
        });
    }

    // ── S10-DOMAINS-2: «short by part of a year» ──────────────────────────────────────────────────────────────────

    /**
     * REV-806-1 (high: a paid renewal is not bought, on every such invoice open at the cut-over). The rule reads every
     * waiting line, also one the customer pays HERE. WHMCS's next due date trails the registry's expiry by months on any
     * name transferred in with «Sync Next Due Date» off (the shape the sweep's own finding describes): due every 20
     * November, registry expiry every 1 May. WHMCS billed «(20/11/2026 - 19/11/2027)» on 2026-11-05; the customer pays
     * it on 2026-11-15, three days after the cut-over. In WHMCS that payment renews one year (2027-05-01 → 2028-05-01),
     * and so it did here before this PR. Now: 2027-05-01 + 1 year runs 163 days past 2027-11-20, so nothing is bought;
     * one Pending Module Action whose text says «if not, leave it». The line then waits for ever «counted as billed», so
     * no renewal is billed from 2027-05-01 and no expiry notice goes out (DomainReminders: «its next period paid
     * already»): the name lapses at its registry on 2027-05-01 with a paid invoice and nothing sent.
     *
     * Should: a renewal paid here buys its year (the rule only for a line that came PAID from WHMCS, whose year WHMCS may
     * have bought already), or the importer refuses / names open renewals whose period is not the domain's expiry so
     * they are settled before the dump.
     */
    public function test_rev806_1_an_imported_open_renewal_paid_after_the_cut_over_buys_its_year_though_whmcs_due_date_trails_the_registry(): void
    {
        $this->domainsSource();
        Carbon::setTestNow('2026-11-12 10:00:00');
        $this->domain(402, 'drift.example.com', 'Active', ['expirydate' => '2027-05-01', 'nextduedate' => '2026-11-20']);
        $this->domainInvoice(1, 'Paid', '2025-11-10 09:00:00', [
            [11, 'Domain', 402, 'Domain Renewal - drift.example.com - 1 Year/s (20/11/2025 - 19/11/2026)', 12.5, '2025-11-20'],
        ]);
        $this->domainInvoice(2, 'Unpaid', null, [
            [21, 'Domain', 402, 'Domain Renewal - drift.example.com - 1 Year/s (20/11/2026 - 19/11/2027)', 12.5, '2026-11-20'],
        ]);
        $output = $this->importDomains();
        $this->assertNotNull(InvoiceItem::query()->findOrFail(11)->applied_at, 'precondition: last year\'s paid renewal came done');
        $this->assertStringNotContainsString('#21', $output, 'precondition: the import says nothing about the open renewal');
        $this->openproviderOn(402, 'drift.example', '2027-05-01');
        $this->artisan('domains:sync', ['--force' => true]);

        Carbon::setTestNow('2026-11-15 10:00:00');
        (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(2), null, '12.50', 'tr_2');
        $this->assertSame(Invoice::STATUS_PAID, Invoice::query()->findOrFail(2)->status);
        $outcome = app(DomainRenewalService::class)->renewLine(21);

        // What happens to the name if the task is «left», as its text allows: ten days before the registry's expiry.
        Carbon::setTestNow('2027-04-21 10:00:00');
        Cache::flush();
        $this->artisan('domains:sync', ['--force' => true]);
        $billedNext = $this->bill(402);

        $this->assertSame([1], $this->renewed, "the customer paid a year on 2026-11-15 and none was bought (outcome {$outcome}; task: «"
            . ModuleAction::query()->where('invoice_item_id', 21)->value('last_error') . '»); on 2027-04-21 the registry still has '
            . substr((string) $this->record['expiration_date'], 0, 10) . " and the cron bills {$billedNext} renewal(s) from it");
    }

    /**
     * REV-806-2 (high if the tasks are left as their text says; the PR's «open for the owner»). The shape the rule was
     * made for: WHMCS renewed the year (expiry 2027-06-15) and its paid line «(01/08/2026 - 31/07/2027)» comes «left to
     * be renewed». Nothing is bought now (right), but the line is never applied: no button applies it, and the task says
     * «if not, leave it». Unapplied and paid, it is «the billed line» of every expiry inside its period
     * (DomainRenewalService::billedLine) and «its next period paid already» for the notices (DomainReminders::remind).
     * So on 2027-05-31 (15 days before 2027-06-15) the cron bills nothing, the 30/7/1-day notices are not sent, and on
     * 2027-06-15 the name lapses at its registry: the customer was never asked for 2027-06-15 → 2028-06-15. Before this
     * PR the same line bought a year nobody paid (the sweep's finding); now it loses the name instead.
     *
     * Should (cut the case): the importer does not leave such a line waiting. A paid WHMCS renewal whose period end is
     * past WHMCS's expiry by less than a year was renewed by WHMCS: it comes applied and is named, so the next year is
     * billed from the registry's expiry as for any domain. Then the runtime rule has nothing imported left to guard.
     */
    public function test_rev806_2_a_paid_whmcs_renewal_held_as_short_by_part_of_a_year_does_not_hide_the_next_renewal(): void
    {
        $this->domainsSource();
        Carbon::setTestNow('2026-11-12 10:00:00');
        $this->domain(402, 'offset.example.com', 'Active', ['expirydate' => '2027-06-15', 'nextduedate' => '2027-08-01']);
        $this->domainInvoice(1, 'Paid', '2026-07-20 09:00:00', [
            [12, 'Domain', 402, 'Domain Renewal - offset.example.com - 1 Year/s (01/08/2026 - 31/07/2027)', 12.5, '2026-08-01'],
        ]);
        $this->importDomains();
        $this->openproviderOn(402, 'offset.example', '2027-06-15');
        $this->artisan('domains:sync', ['--force' => true]);
        app(DomainRenewalService::class)->renewLine(12); // the first cron after the connection is on

        // Staff leave the task, as it says. Ten days before the registry's expiry: the nightly billing and the notices.
        Carbon::setTestNow('2027-06-05 10:00:00');
        Cache::flush();
        $this->artisan('domains:sync', ['--force' => true]);
        app(DomainRenewalService::class)->renewLine(12);
        $billed = $this->bill(402);
        $notices = app(DomainReminders::class)->send();
        $registry = substr((string) $this->record['expiration_date'], 0, 10);

        $this->assertTrue($registry > '2027-06-15' || $billed === 1,
            "on 2027-06-05 the registry has {$registry}, the cron bills {$billed} renewal(s) from it and sends {$notices} expiry notice(s): "
            . 'the name lapses on 2027-06-15 with nothing asked of its customer (line #12 applied: '
            . (InvoiceItem::query()->findOrFail(12)->applied_at === null ? 'no' : 'yes') . ')');
    }

    // ── S10-DOMAINS-1: never ended by the dates before its first read ─────────────────────────────────────────────

    /**
     * REV-806-3 (medium; the limit docs/domains.md states, built). A FAILED read stamps `last_synced_at`
     * (DomainSyncService::bookkeep), so «never read» ends with the first attempt, good or not. The cut-over day as the
     * runbook has it: staff switch Openprovider on with the credentials the import brought, the reseller's IP is not
     * allowed there yet (or the password is the old one), every read fails; they switch the connection off again to sort
     * it out. The scheduler's next date step (within 2 hours) ends every imported domain whose WHMCS expiry is a month
     * past its last stage: exactly S10-DOMAINS-1 again, one failed attempt later, and a Deleted row is never read again.
     *
     * Should: «read» means read. Either the rule asks for a good read (no `sync_error` on a domain whose expiry was never
     * confirmed; or a stamp of its own written only by a successful read), or a failed read does not stamp it.
     */
    public function test_rev806_3_a_failed_first_read_is_not_a_read_the_dates_still_do_not_end_it(): void
    {
        $this->markTestIncomplete('known, already on main (2026-11-12): the dates end a domain whether or not its registrar was read, and a read that failed (wrong credentials) is stamped as a read: cutover-runbook');

        $this->domainsSource();
        Carbon::setTestNow('2026-11-12 10:00:00');
        // WHMCS's expiry 134 days ago; renewed at the registrar by hand, WHMCS never read it (importer.md: such names exist).
        $this->domain(401, 'alive.example.com', 'Expired', ['expirydate' => '2026-07-01']);
        $this->importDomains();
        $connection = DomainRegistrarConnection::query()->where('registrar', 'openprovider')->firstOrFail();
        $connection->forceFill(['is_active' => true, 'mode' => 'production'])->save();
        $connection->storeCredentials(['username' => 'u', 'password' => 'Old-Secret-Pa55'], ['password']);
        Http::fake(fn () => Http::response(['code' => 196, 'desc' => 'Authentication/Authorization Failed'], 401));

        $this->artisan('domains:sync', ['--force' => true]);
        $domain = Domain::query()->findOrFail(401);
        $this->assertNotNull($domain->sync_error, 'precondition: the read failed');
        $this->assertSame(DomainStatus::Expired, $domain->status, 'precondition: a connection that is on holds it');

        // Off again while the credentials are sorted out; the scheduler's next pass.
        $connection->forceFill(['is_active' => false])->save();
        Cache::flush();
        $this->artisan('domains:sync', ['--scheduled' => true]);

        $domain = Domain::query()->findOrFail(401);
        $this->assertNotSame(DomainStatus::Deleted, $domain->status, 'its registrar was never read (one failed attempt: «' . $domain->sync_error
            . '», last_synced_at ' . $domain->last_synced_at . '), and WHMCS\'s dates ended it');
    }

    /**
     * REV-806-4 (medium, money; already on main, left out of the fix). `DomainLifecycle` now waits for the first read,
     * `Domain::lapsedForGood` does not: it still reads WHMCS's dates. An imported domain WHMCS shows expired on
     * 2026-07-01 (renewed at the registrar by hand; its renewal invoice of 2026-07-01 → 2027-07-01 still owed) keeps its
     * row, but the first nightly cron after the import (connections still off, as the import leaves them) cancels that
     * invoice as «domain lapsed» and marks the renewal given up, on WHMCS's word alone. The first read then finds it
     * active to 2027-07-01, and the year the customer owes is no longer asked for.
     *
     * Should: one rule for both. A domain on an API connection never read is not «lapsed for good» either: its open
     * invoice stays until the first read says what it is (or the importer names it with the others that «wait for their
     * first registrar read»).
     */
    public function test_rev806_4_the_open_renewal_of_a_domain_never_read_is_not_cancelled_by_whmcs_dates(): void
    {
        $this->markTestIncomplete('known, already on main (2026-11-12): the cron cancels the open renewal of a domain past its stages even when its registrar was never read: cutover-runbook');

        $this->domainsSource();
        Carbon::setTestNow('2026-11-12 10:00:00');
        $this->domain(401, 'alive.example.com', 'Expired', ['expirydate' => '2026-07-01']);
        $this->domainInvoice(3, 'Unpaid', null, [
            [31, 'Domain', 401, 'Domain Renewal - alive.example.com - 1 Year/s (01/07/2026 - 30/06/2027)', 12.5, '2026-07-01'],
        ]);
        $this->importDomains();
        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->findOrFail(3)->status);
        config(['settings.cronjob_invoice' => 7]);

        Carbon::setTestNow('2026-11-13 03:00:00');
        $this->artisan('domains:sync', ['--scheduled' => true]);
        $this->artisan('app:cron-job');

        $domain = Domain::query()->findOrFail(401);
        $this->assertNull($domain->last_synced_at, 'precondition: never read');
        $this->assertSame(DomainStatus::Expired, $domain->status, 'precondition: the dates did not end it (S10-DOMAINS-1)');
        $invoice = Invoice::query()->findOrFail(3);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status, 'its registrar never read, its open renewal was given up by WHMCS\'s dates («'
            . $invoice->cancellation_reason . '», renewal_given_up ' . ($domain->meta['renewal_given_up'] ?? '—') . ')');
    }

    /**
     * REV-806-5 (medium; ADV-1 of #308 opened again for a domain never read). A connection that is never switched on (a
     * registrar the company left before the cut-over, an account nobody has credentials for; or a domain staff made by
     * hand on a connection that is off) leaves its domains «never read» for good. One imported active that lapses after
     * the import moves to grace, to redemption, and stays there: in redemption it holds its name (`live_fqdn`: nobody,
     * its old customer included, can order it again here) and nothing in the app says so. Health counts only domains
     * that were synced once («not synced for 3+ days» skips `last_synced_at` null), the import's line is long gone, and
     * the only trace is the runbook's one-off look at «Never». #308's rule (a month past its last stage, off: ended)
     * existed because of exactly this row.
     *
     * Should (tell staff, no automation): Health's domains check warns about live domains never read that are past their
     * last stage (a count), or a task is opened once; then staff end them or fix the connection.
     */
    public function test_rev806_5_a_domain_never_read_and_long_past_its_last_stage_is_ended_or_at_least_said(): void
    {
        $this->domainsSource();
        Carbon::setTestNow('2026-11-11 10:00:00');
        $this->domain(402, 'recent.example.com', 'Active', ['expirydate' => '2026-11-01']);
        $this->importDomains();
        foreach (['2026-11-11', '2026-12-11', '2027-06-11'] as $day) {
            Carbon::setTestNow($day . ' 10:00:00');
            Cache::flush();
            $this->artisan('domains:sync', ['--scheduled' => true]);
        }

        $domain = Domain::query()->findOrFail(402);
        $health = Health::report()['checks']['domains'];
        $this->assertTrue($domain->status === DomainStatus::Deleted || $health['status'] !== Health::OK,
            "162 days past the end of its redemption on a connection never switched on: still {$domain->status->value}, "
            . 'holds its name: ' . (Domain::query()->where('live_fqdn', 'recent.example.com')->exists() ? 'yes' : 'no')
            . ", and Health says «{$health['status']}: {$health['detail']}»");
    }

    // ── S10-CRON-BILLING-1: a «Pending» WHMCS service comes without a due date ────────────────────────────────────

    private function servicesSource(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct(); // set up on payment, no server, not pro rata, not an addon
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($user->client()->id, $product->product->id);
        config(['settings.cronjob_invoice' => 7, 'settings.credits_enabled' => false, 'settings.cronjob_order_suspend' => 8,
            'settings.cronjob_order_terminate' => 60, 'settings.cronjob_order_cancel' => 0]);
    }

    /**
     * REV-806-6 (high: a paying customer billed twice and suspended; introduced by this PR). The fix reads «Pending» as
     * «an order not paid», but WHMCS also keeps PAID orders pending: every product set up by staff («Automatically setup
     * … when you manually accept a pending order», or no automatic setup: a dedicated server built by hand) and every
     * module create that failed. WHMCS moves `nextduedate` on when the first invoice is paid, whatever the status, so on
     * such a row it is the paid-until date: ordered and paid 2026-09-01/03, due 2026-10-01. The import now drops that
     * date. Staff start the service on 2026-09-12 (Accept, or its status set to active): with no date,
     * `Service::datesOnStart` takes it for an order set up before payment and makes it due from its order date,
     * 2026-09-01. The next cron bills 2026-09-01 → 2026-10-01 again (invoice #602, the month the customer paid on #601)
     * and suspends the service the night after it was started (11 days «overdue»). Before this PR it started paid until
     * 2026-10-12 (its month moved by the days it waited) with nothing billed.
     *
     * Should (cut by rule): only a Pending service with NO paid «Hosting» line loses its date; one whose order is paid
     * keeps WHMCS's paid-until date, as before.
     */
    public function test_rev806_6_a_pending_whmcs_service_whose_order_is_paid_keeps_its_paid_until_date(): void
    {
        $this->servicesSource();
        Carbon::setTestNow('2026-09-05 10:00:00');
        // Ordered 09-01, paid 09-03, waiting for staff: WHMCS's next due date is already a month on.
        $this->whmcsService(41, '2026-10-01', regdate: '2026-09-01', status: 'Pending');
        $this->whmcsInvoice(601, 'Paid', [41 => 10.0], due: '2026-09-01', date: '2026-09-01');
        $this->importWhmcs();
        $this->assertSame([Service::STATUS_PENDING, Invoice::STATUS_PAID], [Service::query()->findOrFail(41)->status, Invoice::query()->findOrFail(601)->status]);

        // Staff start it a week after the cut-over.
        Carbon::setTestNow('2026-09-12 10:00:00');
        $service = Service::query()->findOrFail(41);
        $service->status = Service::STATUS_ACTIVE;
        $service->save();
        Carbon::setTestNow('2026-09-13 03:00:00');
        $this->artisan('app:cron-job');

        $service = Service::query()->findOrFail(41);
        $second = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', 41)->where('invoice_id', '!=', 601)->first();
        $this->assertSame([Service::STATUS_ACTIVE, null], [$service->status, $second?->invoice_id],
            'paid in WHMCS for 2026-09-01 → 2026-10-01 and started on 2026-09-12: here it is due ' . $service->expires_at?->toDateString()
            . ', billed again on invoice #' . ($second?->invoice_id ?? '—') . ' for ' . ($second?->period_start?->toDateString() ?? '—') . ' → '
            . ($second?->period_end?->toDateString() ?? '—') . " and {$service->status} the night after it was started");
    }

    /**
     * REV-806-7 (medium; what the PR says it leaves: «an imported Pending addon keeps its own nextduedate»). An order of
     * 2026-09-01 for a service and an addon on it, both «Pending», one unpaid invoice (the import takes it: under 7
     * days old). The service now comes with no date, its addon with the order date, although the import itself refuses
     * an addon due on another date than its service («A service with no next due date cannot carry a recurring addon»).
     * The customer pays on 2026-11-20: the service gets 2026-11-20 → 2026-12-20, the addon 2026-09-01 → 2026-10-01. It
     * starts fifty days expired, apart from its parent (owner 2026-11-08: an addon always has its parent's date), and
     * the cron bills its «missed» months.
     *
     * Should: the same line of the fix for a Pending addon of a Pending service (no date, its parent's on payment), or
     * the pre-dump refusal names the pair.
     */
    public function test_rev806_7_a_pending_addon_of_a_pending_service_starts_on_its_parents_date_when_the_order_is_paid(): void
    {
        $this->servicesSource();
        Carbon::setTestNow('2026-09-05 10:00:00');
        $this->whmcs->exec('CREATE TABLE tbladdons (id INTEGER PRIMARY KEY, packages TEXT, name TEXT, description TEXT, billingcycle TEXT, allowqty INT DEFAULT 0,
            tax INT DEFAULT 1, hidden INT DEFAULT 0, retired INT DEFAULT 0, autoactivate TEXT DEFAULT \'\')');
        $this->whmcs->exec('CREATE TABLE tblpricing (id INTEGER PRIMARY KEY, type TEXT, currency INT, relid INT, msetupfee REAL DEFAULT 0, qsetupfee REAL DEFAULT 0,
            ssetupfee REAL DEFAULT 0, asetupfee REAL DEFAULT 0, bsetupfee REAL DEFAULT 0, tsetupfee REAL DEFAULT 0, monthly REAL, quarterly REAL,
            semiannually REAL, annually REAL, biennially REAL, triennially REAL)');
        $this->whmcs->exec('CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, orderid INT DEFAULT 0, hostingid INT, addonid INT, userid INT, name TEXT,
            qty INT DEFAULT 1, recurring REAL, billingcycle TEXT, tax TEXT, status TEXT, regdate TEXT, nextduedate TEXT, nextinvoicedate TEXT,
            paymentmethod TEXT DEFAULT \'banktransfer\', notes TEXT DEFAULT \'\', subscriptionid TEXT DEFAULT \'\')');
        $this->whmcs->exec("INSERT INTO tbladdons (id, packages, name, description, billingcycle, tax) VALUES (26, ',{$this->whmcsProductId},', 'Dedicated IP', '', 'recurring', 1)");
        $this->whmcs->exec("INSERT INTO tblpricing (type, currency, relid, monthly, quarterly, semiannually, annually, biennially, triennially) VALUES ('addon', 1, 26, 5, -1, -1, -1, -1, -1)");
        $this->whmcsService(41, '2026-09-01', regdate: '2026-09-01', status: 'Pending');
        $this->whmcs->exec("INSERT INTO tblhostingaddons (id, hostingid, addonid, userid, name, recurring, billingcycle, tax, status, regdate, nextduedate, nextinvoicedate)
            VALUES (7, 41, 26, {$this->whmcsClientId}, '', 5, 'Monthly', '1', 'Pending', '2026-09-01', '2026-09-01', '2026-09-01')");
        $this->whmcsInvoice(601, 'Unpaid', [41 => 10.0], due: '2026-09-01', date: '2026-09-01');
        $this->whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, userid, type, relid, description, amount, taxed, duedate) VALUES (601, {$this->whmcsClientId}, 'Addon',
            7, 'Dedicated IP', 5, 0, '2026-09-01')");
        $this->whmcs->exec('UPDATE tblinvoices SET total = 15 WHERE id = 601');
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        foreach (['importServices', 'importAddons', 'importInvoices', 'importInvoiceItems', 'importPayments', 'journalMoney'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
        $addon = Service::query()->where('whmcs_addon_id', 7)->sole();
        $this->assertSame([Service::STATUS_PENDING, 41], [$addon->status, (int) $addon->parent_service_id], 'precondition: a pending addon of the pending service');
        $this->assertSame(2, InvoiceItem::query()->where('invoice_id', 601)->where('reference_type', Service::class)->count(), 'precondition: both lines on the order invoice');

        Carbon::setTestNow('2026-11-20 10:00:00');
        $invoice = Invoice::query()->findOrFail(601);
        (new RecordPaymentService)->recordByStaff($invoice, null, (string) $invoice->total, 'tr_601');

        $parent = Service::query()->findOrFail(41);
        $addon = $addon->fresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'precondition: the order is paid');
        $this->assertSame($parent->expires_at?->toDateString(), $addon->expires_at?->toDateString(),
            "paid together on 2026-11-20: the service runs to {$parent->expires_at?->toDateString()}, its addon ({$addon->status}) to {$addon->expires_at?->toDateString()}");
    }
}
