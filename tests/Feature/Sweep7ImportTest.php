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
use App\Services\Domains\Ordering\DomainOrderFulfilment;
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
 * Sweep 7, adversarial verification of the importer («import»). Synthetic data only. Each test fails for the reason in
 * its name; the passing ones are the controls.
 */
class Sweep7ImportTest extends TestCase
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

    /** One of the importer's pre-dump refusals, on the source as it is now. */
    private function refusal(PDO $whmcs, string $check): array
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);

        return (new ReflectionMethod($command, $check))->invoke($command);
    }

    private function billedFor(int $service, string $start): int
    {
        return InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service)->whereDate('period_start', $start)
            ->whereHas('invoice', fn ($invoice) => $invoice->whereIn('status', [...Invoice::OPEN_STATUSES, Invoice::STATUS_PAID]))->count();
    }

    /**
     * S7-IMPORT-1. A live service due in 5 days (nextinvoicedate = nextduedate: WHMCS bills it tonight) that still has an
     * Unpaid invoice two years old with a Hosting line of it (ii.duedate two years back; staff moved the due date by hand
     * and nobody cancelled it). No pre-dump check names it. Its line comes as «the renewal billed already» (no kind, no
     * period), so the coming period is never billed here. Control: the same service without the stale invoice is billed.
     */
    public function test_s7_import_1_a_stale_unpaid_renewal_invoice_does_not_stop_the_next_renewal_from_being_billed(): void
    {
        $whmcs = $this->whmcs();
        $due = now()->addDays(5)->toDateString();
        $old = now()->subYears(2)->toDateString();
        $this->whmcsService($whmcs, 9701, $due, $due); // control
        $this->whmcsService($whmcs, 9702, $due, $due);
        $this->whmcsInvoice($whmcs, 9801, 'Unpaid', $old, [[98011, 'Hosting', 9702, "Hosting (example.test) ({$old})", 10, $old]]);
        // The fix (owner 2026-11-10): refused before the dump, named by invoice and service; nothing is written.
        $this->assertSame([9801 => ['service #9702']], $this->refusal($whmcs, 'staleRenewals'));
        $this->refused($whmcs, 'WHMCS unpaid invoice(s) with a renewal line of a period its live service or addon is already past: #9801 (service #9702).');
        // Staff cancel it in WHMCS, dump again: the import runs and the coming period is billed, as the control's.
        $whmcs->exec("UPDATE tblinvoices SET status = 'Cancelled' WHERE id = 9801");
        $this->assertSame([], $this->refusal($whmcs, 'staleRenewals'));
        $this->import($whmcs, ['importServices', 'importInvoices', 'importInvoiceItems']);
        $this->assertSame(Invoice::STATUS_CANCELLED, Invoice::query()->findOrFail(9801)->status);

        $this->cron();

        $this->assertSame(1, $this->billedFor(9701, $due), 'control: the service without a stale invoice is billed for the period due in 5 days');
        $this->assertSame(1, $this->billedFor(9702, $due), 'the period due in 5 days is billed (as WHMCS would tonight)');
    }

    /**
     * S7-IMPORT-1, what is NOT refused. WHMCS moves nextduedate on only when the period is paid, so a renewal still owed
     * has its line due on nextduedate, however long ago that was: a debt, imported as the open renewal it is. Nor a line
     * of a period paid, cancelled, on its way (Payment Pending), or of a service imported ended.
     */
    public function test_s7_import_1_an_overdue_renewal_of_the_period_the_service_is_in_is_not_refused(): void
    {
        $whmcs = $this->whmcs();
        $overdue = now()->subDays(40)->toDateString();
        $old = now()->subYears(2)->toDateString();
        $this->whmcsService($whmcs, 9711, $overdue, $overdue); // unpaid since 40 days: its line is due on its nextduedate
        $whmcs->exec("UPDATE tblhosting SET domainstatus = 'Suspended' WHERE id = 9711");
        $this->whmcsInvoice($whmcs, 9811, 'Unpaid', $overdue, [[98111, 'Hosting', 9711, 'Hosting', 10, $overdue]]);
        $this->whmcsInvoice($whmcs, 9812, 'Collections', $overdue, [[98121, 'Hosting', 9711, 'Hosting', 10, $overdue]]);
        $this->whmcsService($whmcs, 9712, now()->addDays(5)->toDateString(), now()->addDays(5)->toDateString());
        $this->whmcsInvoice($whmcs, 9813, 'Paid', $old, [[98131, 'Hosting', 9712, 'Hosting', 10, $old]]);
        $this->whmcsInvoice($whmcs, 9814, 'Cancelled', $old, [[98141, 'Hosting', 9712, 'Hosting', 10, $old]]);
        $this->whmcsInvoice($whmcs, 9815, 'Payment Pending', $old, [[98151, 'Hosting', 9712, 'Hosting', 10, $old]]);
        $this->whmcsInvoice($whmcs, 9816, 'Unpaid', $old, [[98161, 'Setup', 9712, 'Setup fee', 10, $old], [98162, 'Hosting', 9712, 'No date', 10, '0000-00-00']]);
        $this->whmcsService($whmcs, 9713, now()->addDays(5)->toDateString(), now()->addDays(5)->toDateString());
        $whmcs->exec("UPDATE tblhosting SET domainstatus = 'Terminated' WHERE id = 9713");
        $this->whmcsInvoice($whmcs, 9817, 'Unpaid', $old, [[98171, 'Hosting', 9713, 'Hosting', 10, $old]]);

        $this->assertSame([], $this->refusal($whmcs, 'staleRenewals'));

        // And the same leftover in Collections is refused, with every service it bills late.
        $this->whmcsInvoice($whmcs, 9818, 'Collections', $old, [[98181, 'Hosting', 9712, 'Hosting', 10, $old], [98182, 'Hosting', 9711, 'Hosting', 10, $old]]);
        $this->assertSame([9818 => ['service #9711', 'service #9712']], $this->refusal($whmcs, 'staleRenewals'));
    }

    /** S7-IMPORT-1 for a live addon's own «Addon» line (imported as its service's renewal, as a Hosting line). */
    public function test_s7_import_1_a_stale_unpaid_renewal_of_a_live_addon_is_refused(): void
    {
        $whmcs = $this->whmcs();
        $due = now()->addDays(20)->toDateString();
        $old = now()->subYear()->toDateString();
        $this->whmcsService($whmcs, 9721, $due, $due);
        $whmcs->exec('CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, hostingid INT, status TEXT, billingcycle TEXT, nextduedate TEXT)');
        $whmcs->exec("INSERT INTO tblhostingaddons VALUES (61, 9721, 'Active', 'Monthly', '{$due}'), (62, 9721, 'Active', 'Monthly', '{$due}'),
            (63, 9721, 'Terminated', 'Monthly', '{$due}'), (64, 9721, 'Active', 'One Time', '{$due}')");
        $this->whmcsInvoice($whmcs, 9821, 'Unpaid', $old, [[98211, 'Addon', 61, 'Addon', 3, $old], [98212, 'Addon', 63, 'Addon', 3, $old], [98213, 'Addon', 64, 'Addon', 3, $old]]);
        $this->whmcsInvoice($whmcs, 9822, 'Unpaid', $due, [[98221, 'Addon', 62, 'Addon', 3, $due]]); // the period it is in: owed

        $this->assertSame([9821 => ['addon #61']], $this->refusal($whmcs, 'staleRenewals'));
    }

    /**
     * S7-IMPORT-2. A domain order paid in 2019 and still plain «Pending» in WHMCS (never registered there, settled outside
     * WHMCS, the row never cancelled). WHMCS never retries it. Here its paid register line comes not applied, so the
     * cron's domains_ordered step (DomainOrderFulfilment::due, no age limit) sends it to the registrar on the first run.
     * The unpaid twin is refused before the dump after 7 days (abandonedOrders). Control: one paid yesterday is sent.
     */
    public function test_s7_import_2_a_domain_order_paid_years_ago_and_still_pending_is_not_sent_to_the_registrar_by_itself(): void
    {
        $whmcs = $this->whmcs();
        $client = $this->user->client()->id;
        foreach ([[9901, '2019-05-02', 'old-order.com'], [9902, now()->subDay()->toDateString(), 'new-order.com']] as [$id, $date, $name]) {
            DB::table('orders')->insert(['id' => $id, 'client_id' => $client, 'currency_code' => 'EUR', 'created_at' => "{$date} 10:00:00", 'updated_at' => "{$date} 10:00:00"]);
            $whmcs->prepare("INSERT INTO tbldomains VALUES (?, ?, ?, 'Register', ?, ?, 12, 12, 'openprovider', 1, '0000-00-00', 'Pending', ?, '',
                0, 0, 0, 0, ?, ?)")->execute([$id, $client, $id, $date, $name, $date, "{$date} 10:00:00", "{$date} 10:05:00"]);
            $whmcs->prepare("INSERT INTO tblinvoices VALUES (?, ?, '', ?, ?, ?, 'Paid', 12, 0, 0)")->execute([$id, $client, $date, $date, "{$date} 10:01:00"]);
            $whmcs->prepare("INSERT INTO tblinvoiceitems VALUES (?, ?, ?, 'DomainRegister', ?, ?, 12, 0, ?)")
                ->execute([$id * 10, $id, $client, $id, "Domain Registration - {$name} - 1 Year/s", $date]);
        }
        $this->assertSame([], $this->refusal($whmcs, 'abandonedOrders'), 'not an unpaid order');
        // The fix (owner 2026-11-10): paid more than 30 days ago and still plain «Pending» is refused before the dump.
        $this->assertSame([9901], $this->refusal($whmcs, 'stalePaidDomainOrders'));
        $this->refused($whmcs, 'WHMCS domain order(s) paid more than 30 days ago and still «Pending»: domain #9901.');
        // Staff set it Cancelled in WHMCS, dump again: the import runs and only the young order is sent from here.
        $whmcs->exec("UPDATE tbldomains SET status = 'Cancelled' WHERE id = 9901");
        $this->assertSame([], $this->refusal($whmcs, 'stalePaidDomainOrders'));

        $this->import($whmcs, ['importDomains', 'importInvoices', 'importInvoiceItems']);

        $this->assertSame(DomainStatus::Cancelled, Domain::query()->findOrFail(9901)->status);
        $this->assertSame(Invoice::STATUS_PAID, Invoice::query()->findOrFail(9901)->status);
        // As the cron's domains_ordered step reads them (CronJob: due() older than 10 minutes).
        $due = DomainOrderFulfilment::due()->where('updated_at', '<', now()->subMinutes(10))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains(9902, $due, 'control: an order paid yesterday and still pending is sent from here (R3-2 of #535)');
        $this->assertNotContains(9901, $due, 'an order paid in 2019 that WHMCS never registered is not bought at the registrar by the first cron');
    }

    /**
     * S7-IMPORT-2, the line: 30 days from the payment (the invoice's datepaid, else its date). Not refused: a younger one,
     * one WHMCS sent («Pending Registration»), one never paid (abandonedOrders'), and a «Pending» row with an expiry ahead
     * registered long ago (imported active, WhmcsDomainImport::registeredWhilePending: never sent).
     */
    public function test_s7_import_2_only_a_plain_pending_order_paid_more_than_thirty_days_ago_is_refused(): void
    {
        $whmcs = $this->whmcs();
        $client = $this->user->client()->id;
        $add = function (int $id, string $status, string $type, string $invoice, string $paid, ?string $invoiceDate = null, string $expiry = '0000-00-00', ?string $registered = null) use ($whmcs, $client) {
            $date = $invoiceDate ?? substr($paid, 0, 10);
            $whmcs->prepare("INSERT INTO tbldomains VALUES (?, ?, ?, ?, ?, ?, 12, 12, 'openprovider', 1, ?, ?, ?, '', 0, 0, 0, 0, ?, ?)")
                ->execute([$id, $client, $id, $type, $registered ?? $date, "order{$id}.example.com", $expiry, $status, $date, "{$date} 10:00:00", "{$date} 10:00:00"]);
            $whmcs->prepare("INSERT INTO tblinvoices VALUES (?, ?, '', ?, ?, ?, ?, 12, 0, 0)")->execute([$id, $client, $date, $date, $paid, $invoice]);
            $whmcs->prepare('INSERT INTO tblinvoiceitems VALUES (?, ?, ?, ?, ?, ?, 12, 0, ?)')
                ->execute([$id * 10, $id, $client, $type === 'Transfer' ? 'DomainTransfer' : 'DomainRegister', $id, 'Domain order', $date]);
        };
        $add(9911, 'Pending', 'Register', 'Paid', now()->subDays(31)->toDateString() . ' 09:00:00');
        $add(9912, 'Pending', 'Transfer', 'Paid', now()->subDays(31)->toDateString() . ' 09:00:00');
        $add(9913, 'Pending', 'Register', 'Paid', now()->subDays(29)->toDateString() . ' 09:00:00');
        $add(9914, 'Pending', 'Register', 'Paid', '0000-00-00 00:00:00', now()->subDays(45)->toDateString()); // no datepaid: by its date
        $add(9915, 'Pending Registration', 'Register', 'Paid', '2020-01-01 09:00:00');
        $add(9916, 'Pending', 'Register', 'Unpaid', '0000-00-00 00:00:00', '2020-01-01');
        $add(9917, 'Pending', 'Register', 'Paid', '2020-01-01 09:00:00', null, now()->addMonths(3)->toDateString(), '2020-01-01');
        $add(9918, 'Active', 'Register', 'Paid', '2020-01-01 09:00:00');

        $this->assertSame([9911, 9912, 9914], $this->refusal($whmcs, 'stalePaidDomainOrders'));
    }

    /** The import as staff run it, dry run and for real: refused with $message, and nothing asked about this database. */
    private function refused(PDO $whmcs, string $message): void
    {
        $whmcs->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        ImportFromWhmcs::$connect = fn () => $whmcs;
        putenv('WHMCS_SOURCE_PASSWORD=secret');
        try {
            $source = ['dbname' => 'whmcs_copy', '--source-user' => 'reader', '--source-host' => 'db.example.test'];
            $this->artisan('app:import-from-whmcs', $source + ['--dry-run' => true])->assertFailed()->expectsOutputToContain($message);
            $this->artisan('app:import-from-whmcs', $source)->assertFailed()->expectsOutputToContain($message)
                ->doesntExpectOutputToContain('--confirm-database');
        } finally {
            ImportFromWhmcs::$connect = null;
            putenv('WHMCS_SOURCE_PASSWORD');
        }
        $this->assertTrue(User::query()->whereKey($this->user->id)->exists(), 'nothing emptied');
    }
}
