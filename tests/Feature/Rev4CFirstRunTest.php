<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
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
 * Independent review of the sweep 4 «c-first-run» fixes (fix/sweep4-c-first-run). Each test fails on the fix's head for
 * a problem it leaves or brings.
 */
class Rev4CFirstRunTest extends TestCase
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

    /**
     * REV4-C-1 (C-FIRST-RUN-2). The fix imports the renewal WHMCS gave up as given up (Δ3a) but leaves expires_at at
     * WHMCS's nextduedate. Δ3a bills nothing after a period given up on (ForgonePeriodTest), so the service is never
     * billed again: suspended by date on the first run with no invoice its customer could pay, then terminated by date
     * (Termination Days 60 past nextduedate) a month later. WHMCS, which suspends by an overdue invoice and bills the next
     * period at nextinvoicedate, keeps it running and bills it. Before the fix the period was billed again (payable);
     * now the customer has nothing to pay and loses the service (its data) without any invoice.
     */
    public function test_rev4_c_1_a_service_whose_renewal_whmcs_gave_up_is_not_terminated_without_ever_being_billed(): void
    {
        $whmcs = $this->whmcs();
        $due = now()->subDays(30)->toDateString();
        $this->whmcsService($whmcs, 9311, $due, now()->addDays(20)->toDateString());
        $this->whmcsInvoice($whmcs, 9411, 'Cancelled', $due, [[94111, 'Hosting', 9311, 'Hosting (example.test)', 10, $due]]);
        $this->import($whmcs, ['importServices', 'importInvoices', 'importInvoiceItems']);

        $this->cron(); // first run
        Carbon::setTestNow(now()->addDays(13)); // WHMCS bills the next period now (nextinvoicedate - 7)
        $this->cron();
        $billed = Invoice::query()->where('client_id', $this->user->client()->id)->whereIn('status', Invoice::OPEN_STATUSES)->count();
        Carbon::setTestNow(now()->addDays(18)); // nextduedate + 61
        $this->cron();

        $service = Service::query()->findOrFail(9311);
        $this->assertGreaterThan(0, $billed, 'never billed again, not even the period WHMCS bills next (nextinvoicedate)');
        $this->assertNotSame(Service::STATUS_TERMINATED, $service->status, 'terminated with no invoice ever billed to pay');
    }

    /**
     * REV4-C-2 (C-FIRST-RUN-1). An order is «abandoned» 7 days after it was made (ORDER_DAYS), whatever its due date.
     * Order Days Grace (order_days_grace, WHMCS's OrderDaysGrace, imported; myip 8) puts an order's due date after it, up
     * to years: an order still within its grace, not even due, is no longer paid by credit that comes in (decision 5),
     * while the fix's own docblock says a fresh order is. Measured from the due date it would follow the grace.
     */
    public function test_rev4_c_2_an_order_still_within_its_grace_is_paid_by_credit(): void
    {
        config(['settings.order_days_grace' => 14]);
        $service = $this->service(['status' => Service::STATUS_PENDING, 'expires_at' => null]);
        $made = now()->subDays(8)->toDateTimeString();
        $id = DB::table('invoices')->insertGetId(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR',
            'due_at' => now()->addDays(6)->toDateString(), 'created_at' => $made, 'updated_at' => $made]);
        DB::table('invoice_items')->insert(['invoice_id' => $id, 'description' => 'Hosting order', 'price' => '10.00', 'quantity' => 1, 'taxed' => false,
            'tax_rate' => 0, 'tax_amount' => 0, 'line_total' => '10.00', 'reference_type' => Service::class, 'reference_id' => $service->id,
            'created_at' => $made, 'updated_at' => $made]);
        (new CreditLedger)->add($this->user->client()->id, 'EUR', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Credit');

        $this->cron();

        $this->assertSame(Invoice::STATUS_PAID, Invoice::query()->findOrFail($id)->status, 'an order not yet due is not abandoned');
    }
}
