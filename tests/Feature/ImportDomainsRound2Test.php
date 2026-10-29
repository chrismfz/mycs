<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\InvoiceItem;
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
 * Round-2 adversarial review of #535 (WHMCS's domains, D6), on its fixes (da7e65ce, 6842ad53): the cancelled renewals
 * given up, the paid renewals left to be applied, «Pending» with an expiry, the stage fees. Shapes from the local WHMCS
 * copies (aggregates only); names are example ones.
 */
class ImportDomainsRound2Test extends TestCase
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
     * ADV-535-R2-1 (High, money: renewals never billed). The fix of ADV-535-7 marks every WHMCS renewal line on a
     * cancelled invoice whose period has started as given up (`forgone_at`). WHMCS's cancelled lines are mostly the
     * alternatives a customer did not take: a 2- or 5-year renewal invoice cancelled when they paid the 1-year one, its
     * period from the same start and reaching past today's expiry. billedLine counts a given-up line as billed (Δ3), so
     * the period from the expiry is «billed» by the cancelled offer: the cron never bills it, no notice goes, and the
     * domain lapses. myip: 8 live domains have such a cancelled line today (their next renewal, 2026-12 to 2027-10, never
     * billed). A cancel here gives up only the period it bills; an imported alternative should give up nothing past what
     * was paid instead (or only a line for the period now due, as ADV-535-7 meant).
     */
    public function test_adv_535_r2_1_a_cancelled_longer_alternative_never_stops_the_next_renewal_being_billed(): void
    {
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->domain(101, 'offer.example.com', 'Active', 'openprovider', ['expirydate' => '2026-12-09']);
        $this->invoice(1, 'Paid', '2025-11-30 09:00:00', [
            [11, 'Domain', 101, 'Domain Renewal - offer.example.com - 1 Year/s (09/12/2025 - 08/12/2026)', 12.5, '2025-12-09'],
        ]);
        $this->invoice(2, 'Cancelled', null, [
            [21, 'Domain', 101, 'Domain Renewal - offer.example.com - 2 Year/s (09/12/2025 - 08/12/2027)', 25, '2025-12-09'],
        ]);

        $this->import();

        $this->assertSame(1, $this->bill(101), 'the renewal due on 2026-12-09 is billed: the cancelled 2-year offer was never paid');
    }

    /**
     * ADV-535-R2-2 (Medium, money at the registrar). A paid 2-year renewal that WHMCS's CNIC → Openprovider hook replaced
     * with a transfer (mod_domain_migration «submitted»): the transfer added one year, from the registry's expiry, which is
     * not the customer's renewal date WHMCS billed from (nextduedate 2026-07-22, expiry 2026-06-24 → 2027-06-24). Left to
     * be applied (the fix of ADV-535-8), the registrar-first renewal counts the years Openprovider lacks to reach the
     * description's end (2028-07-22): 2027-06-24 + 1 year falls 27 days short, so it buys 2 years, to 2029-06-24. Three
     * years bought for the two paid, the company paying for one nobody paid; the cron does it on its own once staff turn
     * the connection on (it retries waiting lines each run). myip today: 2 such lines (2 and 3 years paid, 3 and 4 bought).
     * Expected: never more than the years the transfer did not add (one), or staff decide.
     */
    public function test_adv_535_r2_2_a_renewal_the_hooks_transfer_half_did_is_never_bought_whole_again(): void
    {
        Carbon::setTestNow('2026-10-29 10:00:00');
        $this->domain(102, 'hook.example.com', 'Active', 'openprovider', ['type' => 'Transfer', 'registrationperiod' => 2,
            'expirydate' => '2027-06-24', 'nextduedate' => '2028-07-22']);
        $this->invoice(3, 'Paid', '2026-06-24 11:38:00', [
            [31, 'Domain', 102, 'Ανανέωση Domain - hook.example.com - 2 Έτος/η (22/07/2026 - 21/07/2028)', 31.5, '2026-07-22'],
        ]);

        $this->import();
        $this->assertTrue(DomainRenewalService::waitingLines()->whereKey(31)->exists(), 'precondition: left to be applied (ADV-535-8)');
        $this->openproviderOn(102, 'hook.example', '2027-06-24');

        app(DomainRenewalService::class)->renewLine(31);

        $this->assertLessThanOrEqual(1, array_sum($this->renewed), 'years bought at Openprovider for a 2-year payment the transfer already added one of: '
            . json_encode($this->renewed) . '; logs: ' . DomainRegistrarLog::query()->pluck('request')->toJson());
    }

    /**
     * ADV-535-R2-3 (Low, status). «Pending with an expiry → Active» (R4) takes any expiry as «registered». A WHMCS
     * «Pending Transfer» carries the losing registrar's expiry (a module's sync or staff fill it before the transfer
     * completes), and its paid transfer line comes applied: imported Active, it is billed and reminded at that expiry as
     * ours, and the order flow that confirms a transfer at the registrar (PendingTransfer) never runs for it. None at the
     * cut-over copy today but the hook's own (which has a paid renewal); the copy of the day decides. An expiry proves a
     * registration, not a transfer: a pending transfer should stay pending unless WHMCS shows it renewed.
     */
    public function test_adv_535_r2_3_a_pending_transfer_with_the_losing_registrars_expiry_stays_pending(): void
    {
        Carbon::setTestNow('2026-10-29 10:00:00');
        $this->domain(103, 'incoming.example.com', 'Pending Transfer', 'openprovider', ['type' => 'Transfer', 'expirydate' => '2027-05-01',
            'registrationdate' => '2026-10-25']);
        $this->invoice(4, 'Paid', '2026-10-25 09:00:00', [
            [41, 'DomainTransfer', 103, 'Domain Transfer - incoming.example.com - 1 Year/s', 12.5, '2026-10-25'],
        ]);

        $this->import();

        $this->assertSame(DomainStatus::PendingTransfer, Domain::query()->find(103)->status, 'a transfer not completed is not a live domain of ours');
    }

    /**
     * ADV-535-R2-4 (Low, money). Every imported invoice line has created_at = the import's now(), and the stage fee rules
     * scope «this renewal's fee» by `created_at >= the renewal's created_at` (addStageFee, stageFeePaid). For an imported
     * renewal (the WHMCS invoice open at the cut-over), every older fee of the domain imported in the same second counts as
     * this one's: a redemption fee paid in an earlier lapse (myip: 4 live domains, nixpal 2) is taken as already on it, so
     * the cron never bills the current one and the company pays the restore; a cancelled one (myip 269) reads as «waived by
     * staff». Now with the kinds of ADV-535-3 these old lines match. Expected: a fee of another, older renewal never counts.
     */
    public function test_adv_535_r2_4_an_old_paid_redemption_fee_is_never_this_renewals(): void
    {
        Carbon::setTestNow('2026-10-29 10:00:00');
        $this->domain(104, 'lapsed.example.com', 'Active', 'openprovider', ['expirydate' => '2026-11-10']);
        $this->invoice(5, 'Paid', '2024-12-20 09:00:00', [
            [51, 'Domain', 104, 'Domain Renewal - lapsed.example.com - 1 Year/s (10/11/2024 - 09/11/2025)', 12.5, '2024-11-10'],
            [52, 'DomainRedemptionFee', 104, 'Domain Redemption Fee', 96.5, '2024-11-10'],
        ]);
        $this->invoice(6, 'Paid', '2025-11-01 09:00:00', [
            [61, 'Domain', 104, 'Domain Renewal - lapsed.example.com - 1 Year/s (10/11/2025 - 09/11/2026)', 12.5, '2025-11-10'],
        ]);
        $this->invoice(7, 'Unpaid', null, [
            [71, 'Domain', 104, 'Domain Renewal - lapsed.example.com - 1 Year/s (10/11/2026 - 09/11/2027)', 12.5, '2026-11-10'],
        ]);

        $this->import();
        Carbon::setTestNow('2026-12-26 10:00:00');
        Domain::query()->whereKey(104)->update(['status' => DomainStatus::Redemption->value, 'redemption_fee_override' => '96.50']);
        $this->bill(104);

        $this->assertSame(['redemption_fee'], InvoiceItem::query()->where('invoice_id', 7)->whereNotNull('kind')->pluck('kind')->all(),
            'this lapse\'s redemption fee on the open renewal invoice');
    }
}
