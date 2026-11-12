<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\DomainRenewalService;
use App\Services\Money\CreditAutoApply;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
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
 * Sweep 10, adversarial verification of the «import» candidates. Names are example ones.
 */
class Sweep10ImportTest extends TestCase
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
            'settings.domain_invoice_days' => 15, 'settings.credits_enabled' => true, 'settings.credits_auto_use' => true, 'audit.console' => true]);
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
    private function invoice(int $id, string $status, string $date, ?string $datepaid, array $lines): void
    {
        $this->whmcs->prepare('INSERT INTO tblinvoices VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0)')
            ->execute([$id, $this->client->id, '', $date, $lines[0][5], $datepaid ?? '0000-00-00 00:00:00', $status, array_sum(array_column($lines, 4))]);
        foreach ($lines as [$lineId, $type, $relid, $description, $amount, $due]) {
            $this->whmcs->prepare('INSERT INTO tblinvoiceitems VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)')
                ->execute([$lineId, $id, $this->client->id, $type, $relid, $description, $amount, $due]);
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
     * S10-IMPORT-1. A WHMCS domain whose year 21/11/2025 → 20/11/2026 was paid on one invoice, and a second, forgotten
     * Unpaid invoice with a «Domain» line of the same year (its expiry, 2026-11-20, already at that period's end). No
     * pre-dump check looks at domain lines; line() imports the unpaid one as a payable renewal with its old period and
     * names nothing (standsFor is only asked for a Cancelled twin). With credit auto-apply on, the first credit step pays
     * it, and the renewal then finds the registrar already at its period's end and adopts it: the customer's credit is
     * gone for a year already paid, nothing bought, «renewed» told, nobody told otherwise. Expected (KISS): the import
     * names such a line (or refuses it pre-dump), or it is never a payable renewal here.
     */
    public function test_s10_import_1_an_unpaid_renewal_of_a_year_already_paid_and_covered_is_named_or_never_paid_for_nothing(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:00');
        $this->domain(301, 'dup.example.com', 'Active', 'openprovider', ['expirydate' => '2026-11-20', 'nextduedate' => '2026-11-20']);
        $this->invoice(1, 'Paid', '2025-11-06', '2025-11-10 09:00:00', [
            [11, 'Domain', 301, 'Domain Renewal - dup.example.com - 1 Year/s (21/11/2025 - 20/11/2026)', 12.5, '2025-11-21'],
        ]);
        $this->invoice(2, 'Unpaid', '2025-11-08', null, [
            [21, 'Domain', 301, 'Domain Renewal - dup.example.com - 1 Year/s (21/11/2025 - 20/11/2026)', 12.5, '2025-11-21'],
        ]);

        $output = $this->import();
        $named = str_contains($output, 'invoice #2') || str_contains($output, '#301');
        $line = InvoiceItem::query()->findOrFail(21);
        $this->assertNotNull(InvoiceItem::query()->findOrFail(11)->applied_at, 'precondition: the paid year is done');
        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->findOrFail(2)->status, 'precondition: the twin is open');

        // The customer has credit (an overpayment brought by the import, a refund to credit): the cron's credit step.
        (new CreditLedger)->add((int) $this->client->id, 'EUR', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'credit');
        (new CreditAutoApply)->forCustomer((int) $this->client->id, 'EUR');
        $paid = Invoice::query()->findOrFail(2)->status === Invoice::STATUS_PAID;
        $outcome = null;
        if ($paid && DomainRenewalService::waitingLines()->whereKey(21)->exists()) {
            $this->openproviderOn(301, 'dup.example', '2026-11-20');
            $outcome = app(DomainRenewalService::class)->renewLine(21);
        }
        $adopted = DomainRegistrarLog::query()->where('domain_id', 301)->where('invoice_id', 2)->where('status', DomainRegistrarLog::STATUS_ADOPTED)->exists();
        $balance = (new CreditLedger)->balanceCents((int) $this->client->id, 'EUR');

        $this->assertFalse(!$named && $paid && $adopted && $this->renewed === [],
            "the import named nothing ({$output}), yet credit paid invoice #2 (balance {$balance} of 5000 cents) for the year 2025-11-21 → 2026-11-21 that invoice #1"
            . " already paid: outcome «{$outcome}», applied at {$line->fresh()->applied_at}, nothing bought at the registrar, expiry still "
            . Domain::query()->find(301)->expires_at->toDateString());
    }
}
