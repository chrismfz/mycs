<?php

namespace Tests\Feature;

use App\Console\Commands\CronJob;
use App\Console\Commands\ImportFromWhmcs;
use App\Enums\DomainStatus;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\CronStat;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Domains\DomainReminders;
use App\Services\Domains\Ordering\DomainOrderFulfilment;
use App\Services\Import\WhmcsApi;
use App\Services\Import\WhmcsDomainImport;
use App\Services\Import\WhmcsPayMethodImport;
use App\Services\Payment\CardExpiryNotices;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Adversarial verification, flow «f-cutover-day» (main @ 55e34ff9). Anonymised fixtures only.
 */
class Sweep2FCutoverDayTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->client = User::factory()->create()->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::lock('app:cron-job')->forceRelease();
        parent::tearDown();
    }

    /**
     * F-CUTOVER-DAY-1: the import's migrate:fresh leaves no CronStat rows («today's run started» gone) and sets no guard
     * (no maintenance, no marker). The hourly catch-up at :35 then starts a full daily run over a database the import is
     * still filling (services in, WHMCS's invoices not yet) or has just filled, before staff's «before the first cron»
     * steps: here it bills the renewal of a service whose WHMCS invoice has not been imported yet.
     */
    public function test_f1_the_hourly_catch_up_does_not_start_a_full_run_over_a_database_just_imported(): void
    {
        config(['settings.cronjob_time' => '09:00', 'settings.credits_auto_use' => false, 'settings.autocharge_days_before' => 0,
            'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0]);
        Carbon::setTestNow('2026-11-09 10:35:00');
        // As migrate:fresh + db:seed leave it: no trace of any cron run.
        Setting::query()->where('settingable_type', CronStat::class)->delete();
        // The import has reached the services; WHMCS's open renewal invoice for it comes later (importInvoices).
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);
        Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-11-12']);
        $this->assertSame(0, Invoice::query()->count());

        Artisan::call('app:cron-job', ['--catch-up' => true]);

        $this->assertSame(0, Invoice::query()->count(), 'no renewal billed into ids the importer is about to write WHMCS\'s invoices to');
        $this->assertNull(Setting::query()->where('key', CronJob::STARTED)->where('settingable_type', CronStat::class)->value('value'),
            'no daily run started by the catch-up over a database the import just emptied and is filling');
    }

    /**
     * F-CUTOVER-DAY-2: mycs:install stores the instance's timezone and URL as settings; the import's prepareDatabase
     * (migrate:fresh + db:seed) puts them back to the code defaults (UTC, http://localhost), which then override .env
     * (app.timezone, app.url, date_default_timezone_set).
     */
    public function test_f2_the_imports_database_reset_keeps_the_instances_timezone_and_url(): void
    {
        foreach (['timezone' => 'Europe/Athens', 'app_url' => 'https://clients.example.test', 'company_name' => 'Example Hosting'] as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null, 'settingable_id' => null], ['value' => $value, 'type' => 'string']);
        }

        $command = app(ImportFromWhmcs::class);
        $command->setLaravel($this->app);
        $kernel = $this->app->make(Kernel::class);
        $command->setApplication((new ReflectionMethod($kernel, 'getArtisan'))->invoke($kernel));
        $command->setInput(new ArrayInput(['dbname' => 'whmcs_copy'], $command->getDefinition()));
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new ReflectionMethod($command, 'prepareDatabase'))->invoke($command);

        $settings = fn (string $key) => Setting::query()->where('key', $key)->whereNull('settingable_type')->value('value');
        $this->assertSame('Europe/Athens', $settings('timezone'), 'the instance\'s timezone survives the import (not UTC)');
        $this->assertSame('https://clients.example.test', $settings('app_url'), 'the instance\'s URL survives the import (not http://localhost)');
    }

    /**
     * F-CUTOVER-DAY-4: WHMCS (CCDaySendExpiryNotices = 25) mailed the card's expiry notice on the 25th; the import writes the
     * card's expiry but never expiry_notified_at, so a cut-over on the 27th sends it again on MyCS's first cron.
     */
    public function test_f4_a_card_expiry_notice_whmcs_sent_this_month_is_not_sent_again(): void
    {
        Carbon::setTestNow('2026-11-27 10:00:00');
        config(['settings.cc_expiry_notice_day' => 25]);
        $stripe = (int) DB::table('extensions')->insertGetId(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway',
            'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblpaymethods (id INTEGER PRIMARY KEY, userid INT, description TEXT, contact_id INT, contact_type TEXT, payment_id INT,
            payment_type TEXT, gateway_name TEXT, order_preference INT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $whmcs->exec('CREATE TABLE tblcreditcards (id INTEGER PRIMARY KEY, pay_method_id INT, card_type TEXT, last_four TEXT, expiry_date TEXT,
            card_data TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT, paymentmethod TEXT)');
        $whmcs->exec('CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, status TEXT, paymentmethod TEXT)');
        $c = $this->client->id;
        $whmcs->exec("INSERT INTO tblpaymethods (id, userid, payment_type, gateway_name, order_preference, deleted_at) VALUES (41, {$c}, 'RemoteCreditCard', 'stripe', 0, NULL)");
        Http::fake(['https://whmcs.example.test/includes/api.php' => Http::response(['result' => 'success', 'clientid' => $c, 'paymethods' => [
            ['id' => 41, 'remote_token' => json_encode(['customer' => 'cus_Delta1', 'method' => 'pm_Card4']), 'expiry_date' => '11/26', 'card_last_four' => '4242', 'card_type' => 'Visa'],
        ]])]);
        $map = fn (string $module) => in_array($module, ['stripe', 'stripe_sepa'], true) ? $stripe : null;
        (new WhmcsPayMethodImport($whmcs, new WhmcsApi('https://whmcs.example.test', 'id-1', 'secret-1'), $map, $map))->run();

        $card = BillingAgreement::query()->where('client_id', $c)->firstOrFail();
        $this->assertSame('2026-11-30', Carbon::parse($card->expiry)->toDateString(), 'precondition: expires at the end of this month');

        $this->assertSame(0, (new CardExpiryNotices)->run(), 'WHMCS sent this month\'s notice on the 25th: not sent again on the 27th');
    }

    private function domainFixture(): void
    {
        config(['settings.default_currency' => 'USD', 'settings.domain_reminder_days' => '30,7,1,-3']);
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
            "INSERT INTO tbldomainpricing VALUES (3, '.com', 'openprovider', 1, 1, 1, 0, 30, 30, 3)",
            'CREATE TABLE tblpricing (id INTEGER PRIMARY KEY, type TEXT, relid INT, currency INT, tsetupfee REAL, msetupfee REAL, qsetupfee REAL, ssetupfee REAL,
                asetupfee REAL, bsetupfee REAL, monthly REAL, quarterly REAL, semiannually REAL, annually REAL, biennially REAL, triennially REAL)',
            "INSERT INTO tblpricing VALUES (100, 'domainrenew', 3, 1, 0, 10, 19, -1, -1, -1, -1, -1, -1, -1, -1, -1)",
            "INSERT INTO tblpricing VALUES (101, 'domainregister', 3, 1, 0, 10, 19, -1, -1, -1, -1, -1, -1, -1, -1, -1)",
            "INSERT INTO tblpricing VALUES (102, 'domaintransfer', 3, 1, 0, 10, 19, -1, -1, -1, -1, -1, -1, -1, -1, -1)",
            'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, orderid INT, type TEXT, registrationdate TEXT, domain TEXT, firstpaymentamount REAL,
                recurringamount REAL, registrar TEXT, registrationperiod INT, expirydate TEXT, status TEXT, nextduedate TEXT, additionalnotes TEXT,
                dnsmanagement INT, emailforwarding INT, idprotection INT, donotrenew INT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE tbldomainsadditionalfields (id INTEGER PRIMARY KEY, domainid INT, name TEXT, value TEXT)',
            'CREATE TABLE tbldomains_extra (id INTEGER PRIMARY KEY, domain_id INT, name TEXT, value TEXT)',
            'CREATE TABLE wHandles (id INTEGER PRIMARY KEY, user_id INT, registrar TEXT, type TEXT, handle TEXT, data TEXT)',
            'CREATE TABLE wDomain_handle (id INTEGER PRIMARY KEY, type TEXT, domain_id INT, handle_id INT)',
            'CREATE TABLE tbldomainreminders (id INTEGER PRIMARY KEY, domain_id INT, date TEXT, recipients TEXT, type INT, days_before_expiry INT)',
        ] as $sql) {
            $this->whmcs->exec($sql);
        }
    }

    private function whmcsDomain(int $id, string $name, string $status, array $extra = []): void
    {
        $row = array_merge(['userid' => $this->client->id, 'orderid' => 0, 'type' => 'Register', 'registrationdate' => '2020-03-01', 'domain' => $name,
            'firstpaymentamount' => 10, 'recurringamount' => 10, 'registrar' => 'openprovider', 'registrationperiod' => 1, 'expirydate' => '2027-03-01',
            'status' => $status, 'nextduedate' => '2027-03-01', 'additionalnotes' => '', 'dnsmanagement' => 0, 'emailforwarding' => 0, 'idprotection' => 0,
            'donotrenew' => 0, 'created_at' => '2020-03-01 10:00:00', 'updated_at' => '2026-03-01 10:00:00'], $extra);
        $columns = implode(', ', array_keys($row));
        $this->whmcs->prepare("INSERT INTO tbldomains (id, {$columns}) VALUES (?, " . implode(', ', array_fill(0, count($row), '?')) . ')')
            ->execute([$id, ...array_values($row)]);
    }

    /**
     * F-CUTOVER-DAY-3: WHMCS «Pending Transfer» is a transfer WHMCS already sent to the registrar (TransferDomain done,
     * waiting at the registry). It is imported as PendingTransfer with its paid DomainTransfer line not applied and no
     * meta.sent_at, exactly like a WHMCS «Pending» order never sent: the cron's domains_ordered step sends it again
     * (DomainOrderFulfilment::due), with no EPP code (never imported).
     */
    public function test_f3_a_transfer_whmcs_already_sent_is_not_sent_again(): void
    {
        $this->domainFixture();
        Carbon::setTestNow('2026-11-09 10:00:00');
        DB::table('orders')->insert(['id' => 880, 'client_id' => $this->client->id, 'currency_code' => 'USD', 'created_at' => '2026-11-02 10:00:00', 'updated_at' => '2026-11-02 10:00:00']);
        $this->whmcsDomain(81, 'moving-in.com', 'Pending Transfer', ['type' => 'Transfer', 'orderid' => 880, 'registrationdate' => '2026-11-02',
            'expirydate' => '0000-00-00', 'nextduedate' => '2027-11-02', 'created_at' => '2026-11-02 10:00:00', 'updated_at' => '2026-11-02 10:05:00']);

        $import = new WhmcsDomainImport($this->whmcs);
        $import->run();
        $domain = Domain::query()->findOrFail(81);
        $this->assertSame(DomainStatus::PendingTransfer, $domain->status);
        $this->assertEmpty($domain->epp_code, 'EPP codes are not imported');

        // Its order's invoice, paid in WHMCS (the transfer was then sent by WHMCS: «Pending Transfer»).
        $line = $import->line(['id' => 9001, 'invoiceid' => 7001, 'type' => 'DomainTransfer', 'relid' => 81, 'description' => 'Domain Transfer - moving-in.com - 1 Year/s',
            'amount' => 10, 'duedate' => '2026-11-02', 'invoice_status' => 'Paid', 'invoice_datepaid' => '2026-11-02 10:01:00']);
        $this->assertNotNull($line);
        // As the importer writes it (directly, paid).
        $invoice = Invoice::factory()->create(['id' => 7001, 'client_id' => $this->client->id, 'currency_code' => 'USD']);
        InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'description' => 'Domain Transfer - moving-in.com', 'price' => '10.00', 'quantity' => 1,
            'reference_type' => $line['reference_type'], 'reference_id' => $line['reference_id'], 'kind' => $line['kind'], 'applied_at' => $line['applied_at']]);
        DB::table('invoices')->where('id', 7001)->update(['status' => Invoice::STATUS_PAID]);
        Domain::query()->whereKey(81)->update(['updated_at' => '2026-11-09 09:00:00']);

        $this->assertNotContains(81, DomainOrderFulfilment::due()->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'a transfer WHMCS already submitted is not sent to the registrar again by the cron (domains_ordered)');
    }

    /**
     * F-CUTOVER-DAY-5: WHMCS's after-expiry notice (tbldomainreminders, days_before_expiry < 0) is skipped by the import,
     * so the first cron (within CATCH_UP_DAYS) sends «domain_expired_notice» a second time.
     */
    public function test_f5_an_after_expiry_notice_whmcs_sent_is_not_sent_again(): void
    {
        $this->domainFixture();
        Carbon::setTestNow('2026-11-05 10:00:00');
        // Expired on 11-01, in grace; WHMCS sent its «-3» notice on 11-04 (for the 11-01 expiry).
        $this->whmcsDomain(91, 'lapsed.com', 'Grace', ['expirydate' => '2026-11-01', 'nextduedate' => '2026-11-01']);
        $this->whmcs->exec("INSERT INTO tbldomainreminders (domain_id, date, recipients, type, days_before_expiry) VALUES (91, '2026-11-04', 'x', 4, -3)");

        (new WhmcsDomainImport($this->whmcs))->run();
        $this->assertSame(DomainStatus::Grace, Domain::query()->findOrFail(91)->status);

        $sent = (new DomainReminders)->send();

        $this->assertSame(0, $sent, 'the expired notice WHMCS sent yesterday is not sent again');
        $this->assertFalse(DB::table('domain_reminders')->where('domain_id', 91)->where('days', -3)->where('sent_at', '>=', '2026-11-05 00:00:00')->exists());
    }

    // ---- the fixes' own tests -----------------------------------------------------------------------------------------

    /** F-CUTOVER-DAY-1: while an import runs (or after one that failed), no cron mode does anything; once it ends, as before. */
    public function test_fix_f1_no_cron_run_while_an_import_is_running_and_the_catch_up_as_before_once_a_run_is_recorded(): void
    {
        config(['settings.cronjob_time' => '09:00', 'settings.credits_auto_use' => false, 'settings.autocharge_days_before' => 0,
            'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0]);
        Carbon::setTestNow('2026-11-09 10:35:00');
        Setting::query()->where('settingable_type', CronStat::class)->delete();
        Setting::query()->create(['key' => ImportFromWhmcs::RUNNING, 'settingable_type' => CronStat::class, 'value' => '2026-11-09 10:00:00', 'type' => 'string']);
        Setting::query()->create(['key' => 'last_cron_run', 'settingable_type' => CronStat::class, 'value' => '2026-11-08 09:00:40', 'type' => 'string']);
        $this->assertSame('2026-11-09 10:00:00', ImportFromWhmcs::running());

        foreach ([[], ['--dunning-only' => true], ['--catch-up' => true]] as $options) {
            Artisan::call('app:cron-job', $options);
            $this->assertNull(Setting::query()->where('key', CronJob::STARTED)->where('settingable_type', CronStat::class)->value('value'),
                'nothing runs over a database an import is filling: ' . json_encode($options));
        }

        // The import ended: with a run of its own recorded, the catch-up starts the day's missed run as before.
        Setting::query()->where('key', ImportFromWhmcs::RUNNING)->delete();
        $this->assertNull(ImportFromWhmcs::running());
        Artisan::call('app:cron-job', ['--catch-up' => true]);
        $this->assertSame('2026-11-09 10:35:00', Setting::query()->where('key', CronJob::STARTED)->where('settingable_type', CronStat::class)->value('value'));
    }

    /** F-CUTOVER-DAY-1: the import's reset writes its marker; its own settings come back, others as seeded. */
    public function test_fix_f2_the_reset_marks_the_import_running_and_keeps_only_the_instances_settings(): void
    {
        Setting::query()->updateOrCreate(['key' => 'timezone', 'settingable_type' => null, 'settingable_id' => null], ['value' => 'Europe/Athens', 'type' => 'string']);
        Setting::query()->updateOrCreate(['key' => 'cronjob_time', 'settingable_type' => null, 'settingable_id' => null], ['value' => '03:00', 'type' => 'string']);

        $command = app(ImportFromWhmcs::class);
        $command->setLaravel($this->app);
        $kernel = $this->app->make(Kernel::class);
        $command->setApplication((new ReflectionMethod($kernel, 'getArtisan'))->invoke($kernel));
        $command->setInput(new ArrayInput(['dbname' => 'whmcs_copy'], $command->getDefinition()));
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new ReflectionMethod($command, 'prepareDatabase'))->invoke($command);

        $this->assertNotNull(ImportFromWhmcs::running(), 'the cron stays off until the import ends');
        $this->assertSame(1, Setting::query()->where('key', 'timezone')->whereNull('settingable_type')->count());
        $this->assertNotSame('03:00', Setting::query()->where('key', 'cronjob_time')->whereNull('settingable_type')->value('value'),
            'not an instance setting: the import brings it (or its default)');
    }

    /**
     * F-CUTOVER-DAY-3 / F-DOMAIN-LIFE-2: «Pending Registration» comes sent (the sync reads it); a plain «Pending», never
     * sent by WHMCS, is still sent from here once paid.
     */
    public function test_fix_f3_pending_registration_comes_sent_and_a_plain_pending_order_is_still_sent(): void
    {
        $this->domainFixture();
        Carbon::setTestNow('2026-11-09 10:00:00');
        DB::table('orders')->insert(['id' => 880, 'client_id' => $this->client->id, 'currency_code' => 'USD', 'created_at' => '2026-11-02 10:00:00', 'updated_at' => '2026-11-02 10:00:00']);
        $dates = ['orderid' => 880, 'registrationdate' => '2026-11-02', 'expirydate' => '0000-00-00', 'nextduedate' => '2027-11-02',
            'created_at' => '2026-11-02 10:00:00', 'updated_at' => '2026-11-02 10:05:00'];
        $this->whmcsDomain(82, 'awaiting.com', 'Pending Registration', $dates);
        $this->whmcsDomain(83, 'not-sent.com', 'Pending', $dates);

        $import = new WhmcsDomainImport($this->whmcs);
        $import->run();
        $this->assertSame(DomainStatus::PendingRegister, Domain::query()->findOrFail(82)->status);
        $this->assertNotEmpty(Domain::query()->findOrFail(82)->meta['sent_at'] ?? null);
        $this->assertEmpty(Domain::query()->findOrFail(83)->meta['sent_at'] ?? null);

        foreach ([82 => 7002, 83 => 7003] as $id => $invoiceId) {
            $line = $import->line(['id' => $invoiceId + 2000, 'invoiceid' => $invoiceId, 'type' => 'DomainRegister', 'relid' => $id, 'description' => 'Domain Registration',
                'amount' => 10, 'duedate' => '2026-11-02', 'invoice_status' => 'Paid', 'invoice_datepaid' => '2026-11-02 10:01:00']);
            $this->assertNotNull($line);
            $id === 82 ? $this->assertNotNull($line['applied_at'], 'sent by WHMCS: done') : $this->assertNull($line['applied_at'], 'never sent: left to be sent');
            $invoice = Invoice::factory()->create(['id' => $invoiceId, 'client_id' => $this->client->id, 'currency_code' => 'USD']);
            InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'description' => 'Domain Registration', 'price' => '10.00', 'quantity' => 1,
                'reference_type' => $line['reference_type'], 'reference_id' => $line['reference_id'], 'kind' => $line['kind'], 'applied_at' => $line['applied_at']]);
            DB::table('invoices')->where('id', $invoiceId)->update(['status' => Invoice::STATUS_PAID]);
        }

        $this->assertSame([83], DomainOrderFulfilment::due()->whereIn('id', [82, 83])->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /** F-CUTOVER-DAY-4: before WHMCS's notice day, or with its notices off, nothing is marked: the notice goes here. */
    public function test_fix_f4_a_card_is_marked_notified_only_once_whmcs_notice_day_came(): void
    {
        $stripe = (int) DB::table('extensions')->insertGetId(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway',
            'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $c = $this->client->id;
        foreach ([['2026-11-20 10:00:00', '25', false], ['2026-11-27 10:00:00', '0', false], ['2026-11-27 10:00:00', '25', true], ['2026-11-26 10:00:00', '26', true]] as [$now, $day, $marked]) {
            Carbon::setTestNow($now);
            DB::table('billing_agreements')->delete();
            $whmcs = new PDO('sqlite::memory:');
            $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $whmcs->exec('CREATE TABLE tblpaymethods (id INTEGER PRIMARY KEY, userid INT, description TEXT, contact_id INT, contact_type TEXT, payment_id INT,
                payment_type TEXT, gateway_name TEXT, order_preference INT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
            $whmcs->exec('CREATE TABLE tblcreditcards (id INTEGER PRIMARY KEY, pay_method_id INT, card_type TEXT, last_four TEXT, expiry_date TEXT,
                card_data TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
            $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT, paymentmethod TEXT)');
            $whmcs->exec('CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, status TEXT, paymentmethod TEXT)');
            $whmcs->exec('CREATE TABLE tblconfiguration (setting TEXT, value TEXT)');
            $whmcs->exec("INSERT INTO tblconfiguration VALUES ('CCDaySendExpiryNotices', '{$day}')");
            $whmcs->exec("INSERT INTO tblpaymethods (id, userid, payment_type, gateway_name, order_preference, deleted_at) VALUES (41, {$c}, 'RemoteCreditCard', 'stripe', 0, NULL)");
            Http::fake(['https://whmcs.example.test/includes/api.php' => Http::response(['result' => 'success', 'clientid' => $c, 'paymethods' => [
                ['id' => 41, 'remote_token' => json_encode(['customer' => 'cus_Delta1', 'method' => 'pm_Card4']), 'expiry_date' => '11/26', 'card_last_four' => '4242', 'card_type' => 'Visa'],
            ]])]);
            $map = fn (string $module) => in_array($module, ['stripe', 'stripe_sepa'], true) ? $stripe : null;
            (new WhmcsPayMethodImport($whmcs, new WhmcsApi('https://whmcs.example.test', 'id-1', 'secret-1'), $map, $map))->run();

            $notified = BillingAgreement::query()->where('client_id', $c)->firstOrFail()->expiry_notified_at;
            $this->assertSame($marked, $notified !== null, "{$now}, WHMCS day {$day}");
        }
    }
}
