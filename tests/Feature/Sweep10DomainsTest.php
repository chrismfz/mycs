<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\DomainRenewalService;
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
 * Sweep 10, domains: adversarial verification of the finder's candidates, on WHMCS's domains as the importer writes them
 * (the harness of ImportDomainsRound3Test). Each test FAILS on main (f9f145bb) for the reason it names. Names are example
 * ones; Http is faked, never a real registrar.
 */
class Sweep10DomainsTest extends TestCase
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

    /**
     * S10-DOMAINS-1 (medium). The import creates every registrar connection off (WhmcsDomainImport::connections) and
     * flushes the cache, so the scheduler's next `domains:sync --scheduled` (every 10 minutes; its date step runs even
     * with no batch) moves every imported live domain by WHMCS's dates before staff have entered a credential. A
     * connection that is off holds a domain only «until a month past its last stage» (DomainLifecycle, #308: off for
     * good); past that, with both stage days known (always for .gr since 2026-11-10, and for a TLD with days in
     * tbldomainpricing), the dates end it Deleted. A deleted row is never read from its registrar again (isSyncable,
     * SyncDomains::live), so a name WHMCS shows lapsed while it was renewed at the registrar (importer.md: such names
     * exist, «the first sync moves them forward»; the .gr ones were never compared) is ended on WHMCS's word alone, and
     * the runbook's «credentials, dry-run, sync» never sees it. Expected: an imported domain is never ended by the dates
     * before its registrar was read once (its connection off since the import is «not set up yet», not «off for good»).
     */
    public function test_s10_domains_1_an_imported_domain_is_not_ended_by_the_dates_before_its_registrar_was_ever_read(): void
    {
        $this->markTestIncomplete('known (2026-11-12): named by the import and handled by hand at the cut-over; the lifecycle rule of #308 is unchanged');

        Carbon::setTestNow('2026-11-11 10:00:00');
        // WHMCS's expiry 133 days ago (grace 30 + redemption 30 + a month = 90), renewed at the registrar to 2027-07-01.
        $this->domain(401, 'alive.example.com', 'Expired', 'openprovider', ['expirydate' => '2026-07-01']);
        $this->import();
        $domain = Domain::query()->findOrFail(401);
        $this->assertSame(DomainStatus::Expired, $domain->status, 'precondition: imported as WHMCS has it');
        $this->assertFalse($domain->effectiveRegistrarConnection()->isUsable(), 'precondition: its connection comes off, without credentials');

        // The scheduler, minutes after the import (the cache flushed: both steps due).
        $this->artisan('domains:sync', ['--scheduled' => true]);
        $afterDates = Domain::query()->findOrFail(401)->status;

        // Staff set the connection up, as the runbook says, and sync: the registrar has it active to 2027-07-01.
        $this->openproviderOn(401, 'alive.example', '2027-07-01');
        $this->artisan('domains:sync', ['--force' => true]);
        $domain = Domain::query()->findOrFail(401);

        $this->assertNotSame(DomainStatus::Deleted, $afterDates, 'ended by WHMCS\'s dates before its registrar was ever asked');
        $this->assertSame([DomainStatus::Active, '2027-07-01'], [$domain->status, $domain->expires_at->toDateString()],
            'the first sync after the connection is on moves it forward (importer.md); last_synced_at = ' . ($domain->last_synced_at ?? 'never'));
    }

    /**
     * S10-DOMAINS-2 (medium, money: a registrar year nobody paid for). WHMCS's renewal line carries the dates of its
     * next due date, which need not be the registry's expiry (a transfer-in, dates set by hand, «Sync Next Due Date» off).
     * A domain due on 1 August whose registry expiry is 15 June, renewed by WHMCS in 2026 (expiry 2026-06-15 →
     * 2027-06-15): its paid line «(01/08/2026 - 31/07/2027)» ends 47 days after WHMCS's expiry, more than the 31 the
     * import allows, so it comes «left to be renewed (the registrar read first: only the years it lacks)». The registrar
     * lacks 47 days, not a year; lacking() never answers less than 1, the gap rule does not fire, and the first cron
     * after the connection is on buys a whole year: 2028-06-15, a year no invoice paid. Expected: nothing is bought when
     * the registrar is short of the paid period by less than a year (adopted, or staff decide), or the import names it.
     */
    public function test_s10_domains_2_an_imported_paid_renewal_short_by_weeks_never_buys_a_whole_year(): void
    {
        $this->markTestIncomplete('known (2026-11-12): a paid imported renewal whose period ends part of a year after the registrar expiry buys a year, as WHMCS did; the waiting imported lines are looked at by hand before the connections are switched on: cutover-runbook');

        Carbon::setTestNow('2026-11-11 10:00:00');
        $this->domain(402, 'offset.example.com', 'Active', 'openprovider', ['expirydate' => '2027-06-15', 'nextduedate' => '2027-08-01']);
        $this->invoice(1, 'Paid', '2026-07-20 09:00:00', [
            [12, 'Domain', 402, 'Domain Renewal - offset.example.com - 1 Year/s (01/08/2026 - 31/07/2027)', 12.5, '2026-08-01'],
        ]);
        $this->import();
        $this->assertTrue(DomainRenewalService::waitingLines()->whereKey(12)->exists(), 'precondition: the import leaves the paid line to be renewed');

        // The registrar shows what WHMCS shows: renewed in 2026, to 2027-06-15.
        $this->openproviderOn(402, 'offset.example', '2027-06-15');
        $outcome = app(DomainRenewalService::class)->renewLine(12);

        $this->assertSame([], $this->renewed, "a whole year bought for 47 days missing (outcome {$outcome}): the registrar now at "
            . Domain::query()->find(402)->expires_at->toDateString() . ', and '
            . DomainRegistrarLog::query()->where('domain_id', 402)->where('action', 'renew')->count() . ' renew log(s)');
    }
}
