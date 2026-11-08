<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Sweep 4, lens «c-first-run» (adversarial verifier): what the first cron run does to state as the WHMCS importer leaves it.
 * The importer writes invoices and lines with DB::table (no observer), so the tests write them the same way where it
 * matters (a cancelled invoice: no forgone_at given by InvoiceObserver).
 */
class Sweep4CFirstRunTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainTld $tld;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 10:00:00');
        Queue::fake();
        Mail::fake();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [true, 'boolean'], 'default_currency' => ['EUR', 'string']]);
        config(['settings.cronjob_invoice' => 7, 'settings.domain_invoice_days' => 15, 'settings.domain_billing_enabled' => true,
            'settings.cronjob_order_cancel' => 0]);
        $this->user = User::factory()->create();
        $this->tld = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        foreach (['register', 'transfer', 'renew'] as $operation) {
            DomainTldPrice::query()->create(['domain_tld_id' => $this->tld->id, 'operation' => $operation, 'years' => 1, 'currency_code' => 'EUR', 'price' => '12.00']);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(array $values): void
    {
        foreach ($values as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + [
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'EUR', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2027-03-01',
        ]);
    }

    private function domain(array $attributes = []): Domain
    {
        static $n = 0;
        $n++;

        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => "fr{$n}", 'tld' => 'com', 'fqdn' => "fr{$n}.com",
            'domain_tld_id' => $this->tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Active, 'expires_at' => '2027-03-01',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    /** An invoice as the importer writes it: the row and its lines with DB::table, no observer. */
    private function imported(string $status, string $due, array $lines): Invoice
    {
        $id = DB::table('invoices')->insertGetId(['client_id' => $this->user->client()->id, 'status' => $status, 'currency_code' => 'EUR',
            'due_at' => $due, 'created_at' => $due, 'updated_at' => $due]);
        foreach ($lines as $line) {
            DB::table('invoice_items')->insert($line + ['invoice_id' => $id, 'quantity' => 1, 'taxed' => false, 'tax_rate' => 0, 'tax_amount' => 0,
                'line_total' => $line['price'], 'kind' => null, 'period_start' => null, 'period_end' => null, 'applied_at' => null, 'forgone_at' => null,
                'created_at' => $due, 'updated_at' => $due]);
        }

        return Invoice::query()->findOrFail($id);
    }

    private function cron(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    /**
     * C-FIRST-RUN-1 (owner's decision 2026-11-08). After the cut-over credit pays an order as before: a fresh order is
     * paid by the customer's credit on the cron (decision 5), the service set up and the name ordered.
     */
    public function test_c_first_run_1_credit_still_pays_a_fresh_order_after_cut_over(): void
    {
        $service = $this->service(['status' => Service::STATUS_PENDING, 'expires_at' => null]);
        $domain = $this->domain(['status' => DomainStatus::PendingRegister, 'expires_at' => null]);
        $order = $this->imported(Invoice::STATUS_UNPAID, now()->subDays(2)->toDateString(), [
            ['description' => 'Hosting order', 'price' => '10.00', 'reference_type' => Service::class, 'reference_id' => $service->id],
            ['description' => 'Domain registration', 'price' => '12.00', 'reference_type' => Domain::class, 'reference_id' => $domain->id, 'kind' => InvoiceItem::KIND_REGISTER],
        ]);
        (new CreditLedger)->add($this->user->client()->id, 'EUR', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Credit');

        $this->cron();

        $this->assertSame(Invoice::STATUS_PAID, $order->fresh()->status, 'a fresh order is paid by credit, as before');
    }

    /**
     * C-FIRST-RUN-1. An abandoned WHMCS order (unpaid, more than 7 days old: a Pending service's Hosting line, a domain
     * registration or transfer) would be paid by the customer's imported credit on the first run, the service set up and
     * the name bought. The import refuses to run while there is one, naming them, on a dry run too, before anything is
     * emptied: they are cancelled in WHMCS before the dump (cutover-runbook.md).
     */
    public function test_c_first_run_1_the_import_refuses_old_unpaid_orders(): void
    {
        $whmcs = $this->whmcs();
        $old = now()->subDays(8)->toDateString();
        $fresh = now()->subDays(5)->toDateString();
        $this->whmcsService($whmcs, 9701, $old, $old);
        $whmcs->exec("UPDATE tblhosting SET domainstatus = 'Pending' WHERE id = 9701");
        $this->whmcsService($whmcs, 9702, $old, $old); // Active: its unpaid renewal is no order
        $this->whmcsInvoice($whmcs, 9801, 'Unpaid', $old, [[98011, 'Hosting', 9701, 'Hosting order', 10, $old]]);
        $this->whmcsInvoice($whmcs, 9802, 'Unpaid', $old, [[98021, 'DomainRegister', 1, 'Domain registration', 12, $old]]);
        $this->whmcsInvoice($whmcs, 9803, 'Unpaid', $old, [[98031, 'DomainTransfer', 2, 'Domain transfer', 12, $old]]);
        $this->whmcsInvoice($whmcs, 9804, 'Unpaid', $old, [[98041, 'Hosting', 9702, 'Hosting renewal', 10, $old]]);
        $this->whmcsInvoice($whmcs, 9805, 'Cancelled', $old, [[98051, 'Hosting', 9701, 'Hosting order', 10, $old]]);
        $this->whmcsInvoice($whmcs, 9806, 'Unpaid', $fresh, [[98061, 'DomainRegister', 3, 'Domain registration', 12, $fresh]]);
        $whmcs->exec("UPDATE tblinvoices SET date = '{$old}' WHERE id <= 9805");
        $whmcs->exec("UPDATE tblinvoices SET date = '{$fresh}' WHERE id = 9806");
        ImportFromWhmcs::$connect = fn () => $whmcs;
        putenv('WHMCS_SOURCE_PASSWORD=secret');
        try {
            $refused = 'WHMCS unpaid order invoice(s) older than 7 days: #9801, #9802, #9803. Credit would pay them on the first cron'
                . ' and set them up: cancel them in WHMCS and dump again. Nothing done.';
            $source = ['dbname' => 'whmcs_copy', '--source-user' => 'reader', '--source-host' => 'db.example.test'];
            $this->artisan('app:import-from-whmcs', $source + ['--dry-run' => true])->assertFailed()->expectsOutputToContain($refused);
            $this->artisan('app:import-from-whmcs', $source)->assertFailed()->expectsOutputToContain($refused)
                ->doesntExpectOutputToContain('--confirm-database');
        } finally {
            ImportFromWhmcs::$connect = null;
            putenv('WHMCS_SOURCE_PASSWORD');
        }
        $this->assertSame(1, DB::table('users')->where('id', $this->user->id)->count(), 'nothing emptied');

        // Cancelled in WHMCS: no longer refused.
        $whmcs->exec("UPDATE tblinvoices SET status = 'Cancelled' WHERE id IN (9801, 9802, 9803)");
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $this->assertSame([], (new ReflectionMethod($command, 'abandonedOrders'))->invoke($command));
    }

    /** C-FIRST-RUN-1, what stays: credit still pays a live service's renewal and a domain's on the same run. */
    public function test_c_first_run_1_credit_still_pays_renewals(): void
    {
        $service = $this->service(['expires_at' => now()->addDays(3)->toDateString()]);
        $domain = $this->domain(['expires_at' => now()->addDays(3)->toDateString()]);
        $renewal = $this->imported(Invoice::STATUS_UNPAID, now()->subDays(2)->toDateString(), [
            ['description' => 'Hosting renewal', 'price' => '10.00', 'reference_type' => Service::class, 'reference_id' => $service->id],
            ['description' => 'Domain renewal', 'price' => '12.00', 'reference_type' => Domain::class, 'reference_id' => $domain->id],
        ]);
        (new CreditLedger)->add($this->user->client()->id, 'EUR', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Imported balance');

        $this->cron();

        $this->assertSame(Invoice::STATUS_PAID, $renewal->fresh()->status);
    }

    /** A WHMCS source with what the importer's service, domain, invoice and line steps read. */
    private function whmcs(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $client = $this->user->client()->id;
        foreach ([
            'CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT, prefix TEXT, suffix TEXT, format INT, rate REAL, "default" INT)',
            "INSERT INTO tblcurrencies VALUES (1, 'EUR', '', '€', 3, 1, 1)",
            'CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)',
            "INSERT INTO tblclients VALUES ({$client}, 1)",
            'CREATE TABLE tblconfiguration (setting TEXT, value TEXT)',
            "INSERT INTO tblconfiguration VALUES ('DateFormat', 'DD/MM/YYYY')",
            'CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, regdate TEXT, domain TEXT, qty INT, amount REAL,
                billingcycle TEXT, nextduedate TEXT, nextinvoicedate TEXT, domainstatus TEXT, username TEXT, password TEXT, notes TEXT, suspendreason TEXT,
                dedicatedip TEXT, assignedips TEXT, overideautosuspend INT, overidesuspenduntil TEXT)',
            'CREATE TABLE tbldomainpricing (id INTEGER PRIMARY KEY, extension TEXT, autoreg TEXT, eppcode INT, dnsmanagement INT, emailforwarding INT,
                idprotection INT, grace_period INT, redemption_grace_period INT, "order" INT)',
            "INSERT INTO tbldomainpricing VALUES (1, '.com', 'openprovider', 1, 1, 1, 1, 40, 0, 1)",
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
            $pdo->exec($sql);
        }

        return $pdo;
    }

    /** A WHMCS service on a monthly plan of a product here, its two dates as given. */
    private function whmcsService(PDO $whmcs, int $id, string $due, string $nextInvoice): void
    {
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $whmcs->prepare("INSERT INTO tblhosting VALUES (?, ?, NULL, ?, '2024-01-01', 'example.test', 1, 10, 'Monthly', ?, ?, 'Active', '', '', '', '', '', '', 0, '0000-00-00')")
            ->execute([$id, $this->user->client()->id, $product->product->id, $due, $nextInvoice]);
    }

    /** A WHMCS invoice with its lines: [id, type, relid, description, amount, duedate]. */
    private function whmcsInvoice(PDO $whmcs, int $id, string $status, string $due, array $lines): void
    {
        $whmcs->prepare("INSERT INTO tblinvoices VALUES (?, ?, '', ?, ?, '0000-00-00 00:00:00', ?, ?, 0, 0)")
            ->execute([$id, $this->user->client()->id, Carbon::parse($due)->subDays(7)->toDateString(), $due, $status, array_sum(array_column($lines, 4))]);
        foreach ($lines as [$lineId, $type, $relid, $description, $amount, $lineDue]) {
            $whmcs->prepare('INSERT INTO tblinvoiceitems VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)')
                ->execute([$lineId, $id, $this->user->client()->id, $type, $relid, $description, $amount, $lineDue]);
        }
    }

    /** The importer's steps, as it runs them. */
    private function import(PDO $whmcs, array $steps): string
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        foreach ($steps as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }

        return $output->fetch();
    }

    /**
     * C-FIRST-RUN-2. A service's renewal WHMCS invoiced and staff cancelled (WHMCS's nextinvoicedate moved on, so WHMCS
     * never bills it again) came as a cancelled Hosting line with no period and no forgone_at: the first run billed the
     * period again. The importer now reads nextinvoicedate and gives that line up (period nextduedate to
     * nextinvoicedate, forgone_at), as a cancel here does (Δ3a).
     */
    public function test_c_first_run_2_a_service_renewal_cancelled_in_whmcs_is_not_billed_again(): void
    {
        $whmcs = $this->whmcs();
        $due = now()->subDays(30)->toDateString();
        $this->whmcsService($whmcs, 9301, $due, now()->addDays(20)->toDateString());
        $this->whmcsInvoice($whmcs, 9401, 'Cancelled', $due, [[94011, 'Hosting', 9301, 'Hosting (example.test)', 10, $due]]);
        $output = $this->import($whmcs, ['importServices', 'importInvoices', 'importInvoiceItems']);
        $line = InvoiceItem::query()->findOrFail(94011);
        $this->assertNotNull($line->forgone_at, 'given up, as a cancel here');
        $this->assertSame($due, $line->period_start?->toDateString());
        $this->assertStringContainsString('9301', $output, 'listed for staff');

        $this->cron();

        $open = Invoice::query()->where('client_id', $this->user->client()->id)->where('status', Invoice::STATUS_UNPAID)->count();
        $this->assertSame(0, $open, 'the period WHMCS gave up on is billed again');
    }

    /** C-FIRST-RUN-2, case B: cancelled ahead of its due date (in 5 days): not billed again, so never charged on it. */
    public function test_c_first_run_2_a_service_renewal_cancelled_ahead_in_whmcs_is_not_billed_again(): void
    {
        $whmcs = $this->whmcs();
        $due = now()->addDays(5)->toDateString();
        $this->whmcsService($whmcs, 9302, $due, Carbon::parse($due)->addMonth()->toDateString());
        $this->whmcsInvoice($whmcs, 9402, 'Cancelled', $due, [[94021, 'Hosting', 9302, 'Hosting (example.test)', 10, $due]]);
        $this->import($whmcs, ['importServices', 'importInvoices', 'importInvoiceItems']);

        $this->cron();

        $this->assertSame(0, Invoice::query()->where('client_id', $this->user->client()->id)->where('status', Invoice::STATUS_UNPAID)->count());
    }

    /**
     * Not one beside an open renewal of the same due date (it stands for the period), and not one WHMCS would bill again
     * (nextinvoicedate = nextduedate: staff moved it back to bill it again): both stay as before, no period.
     */
    public function test_c_first_run_2_only_a_renewal_whmcs_gave_up_is_imported_given_up(): void
    {
        $whmcs = $this->whmcs();
        $due = now()->subDays(3)->toDateString();
        $this->whmcsService($whmcs, 9303, $due, now()->addDays(27)->toDateString());
        $this->whmcsInvoice($whmcs, 9403, 'Cancelled', $due, [[94031, 'Hosting', 9303, 'Hosting (example.test)', 10, $due]]);
        $this->whmcsInvoice($whmcs, 9404, 'Unpaid', $due, [[94041, 'Hosting', 9303, 'Hosting (example.test)', 10, $due]]);
        $this->whmcsService($whmcs, 9304, $due, $due);
        $this->whmcsInvoice($whmcs, 9405, 'Cancelled', $due, [[94051, 'Hosting', 9304, 'Hosting (example.test)', 10, $due]]);

        $this->import($whmcs, ['importServices', 'importInvoices', 'importInvoiceItems']);

        foreach ([94031, 94041, 94051] as $id) {
            $line = InvoiceItem::query()->findOrFail($id);
            $this->assertNull($line->forgone_at, "line {$id}");
            $this->assertNull($line->period_start, "line {$id}");
        }
    }

    /**
     * C-FIRST-RUN-2, the suspension. Given up as a cancel here, the service would fall to the suspension rules by its date
     * (Δ3a), where WHMCS, which suspends by an overdue invoice, would not. Its date is imported as WHMCS's nextinvoicedate,
     * the end of the period given up (as staff move a date over a gap): not suspended, and billed on from there
     * (review REV4-C-1).
     */
    public function test_c_first_run_2_a_service_renewal_cancelled_in_whmcs_is_not_suspended(): void
    {
        $whmcs = $this->whmcs();
        $due = now()->subDays(30)->toDateString();
        $this->whmcsService($whmcs, 9301, $due, now()->addDays(20)->toDateString());
        $this->whmcsInvoice($whmcs, 9401, 'Cancelled', $due, [[94011, 'Hosting', 9301, 'Hosting (example.test)', 10, $due]]);
        $this->import($whmcs, ['importServices', 'importInvoices', 'importInvoiceItems']);

        $this->cron();

        $this->assertSame(Service::STATUS_ACTIVE, Service::query()->findOrFail(9301)->status);
    }

    /**
     * C-FIRST-RUN-3. A domain renewal cancelled in WHMCS for a period starting in 10 days (inside the 15 days the cron
     * bills ahead): a cancellation made here would give it up (InvoiceObserver::givePeriodsUp, period_start < billedFrom),
     * the importer gave it up only when period_start <= today, so the first run billed it again.
     */
    public function test_c_first_run_3_a_domain_renewal_cancelled_in_whmcs_within_the_billing_window_is_not_billed_again(): void
    {
        $whmcs = $this->whmcs();
        $expiry = now()->addDays(10)->startOfDay();
        $whmcs->prepare("INSERT INTO tbldomains VALUES (9501, ?, 0, 'Register', '2020-03-01', 'given-up.com', 12, 12, 'openprovider', 1, ?, 'Active', ?, '',
            0, 0, 0, 0, '2020-03-01 10:00:00', '2026-03-01 10:00:00')")->execute([$this->user->client()->id, $expiry->toDateString(), $expiry->toDateString()]);
        $period = $expiry->format('d/m/Y') . ' - ' . $expiry->copy()->addYear()->subDay()->format('d/m/Y');
        $this->whmcsInvoice($whmcs, 9601, 'Cancelled', $expiry->toDateString(), [[96011, 'Domain', 9501, "Domain Renewal - given-up.com - 1 Year/s ({$period})", 12, $expiry->toDateString()]]);
        $this->import($whmcs, ['importDomains', 'importInvoices', 'importInvoiceItems']);
        $this->assertNotNull(InvoiceItem::query()->findOrFail(96011)->forgone_at, 'given up, as a cancel here (Δ3a)');

        $this->cron();

        $open = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', 9501)
            ->whereHas('invoice', fn ($invoice) => $invoice->where('status', Invoice::STATUS_UNPAID))->count();
        $this->assertSame(0, $open, 'the renewal WHMCS gave up on is billed again');
    }

    /** One cancelled for a period further off than the billing days stays billable, as a cancel here keeps it (ADV-4). */
    public function test_c_first_run_3_a_domain_renewal_cancelled_further_off_stays_billable(): void
    {
        $whmcs = $this->whmcs();
        $expiry = now()->addDays(40)->startOfDay();
        $whmcs->prepare("INSERT INTO tbldomains VALUES (9502, ?, 0, 'Register', '2020-03-01', 'later.com', 12, 12, 'openprovider', 1, ?, 'Active', ?, '',
            0, 0, 0, 0, '2020-03-01 10:00:00', '2026-03-01 10:00:00')")->execute([$this->user->client()->id, $expiry->toDateString(), $expiry->toDateString()]);
        $period = $expiry->format('d/m/Y') . ' - ' . $expiry->copy()->addYear()->subDay()->format('d/m/Y');
        $this->whmcsInvoice($whmcs, 9602, 'Cancelled', $expiry->toDateString(), [[96021, 'Domain', 9502, "Domain Renewal - later.com - 1 Year/s ({$period})", 12, $expiry->toDateString()]]);

        $this->import($whmcs, ['importDomains', 'importInvoices', 'importInvoiceItems']);

        $this->assertNull(InvoiceItem::query()->findOrFail(96021)->forgone_at);
    }
}
