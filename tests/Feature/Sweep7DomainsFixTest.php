<?php

namespace Tests\Feature;

use App\Console\Commands\Domains\SyncDomains;
use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\Import\WhmcsDomainImport;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditAutoApply;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use PDO;
use Tests\TestCase;

/**
 * Sweep 7, the fix of S7-DOMAINS-1 (owner 2026-11-10): the open renewal invoice of a domain lapsed past its last
 * recoverable day (expiry + grace + redemption, 30 days where they are not known) is cancelled by the daily cron with one
 * notice to staff, credit does not pay it by itself meanwhile, and .gr has 15 days of grace and no redemption where
 * WHMCS did not say. Fake data only.
 */
class Sweep7DomainsFixTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainTld $tld;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        MailFacade::fake();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [true, 'boolean'], 'default_currency' => ['EUR', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        config(['settings.cronjob_invoice' => 7, 'settings.domain_invoice_days' => 15, 'settings.domain_reminder_days' => '30,7,1,-3',
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.domain_billing_enabled' => true]);
        $this->user = User::factory()->create();
        $this->tld = $this->tld('com', null, null);
        SyncDomains::$pauseMs = 0;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tld(string $name, ?int $grace, ?int $redemption): DomainTld
    {
        $tld = DomainTld::query()->create(['tld' => $name, 'grace_days' => $grace, 'redemption_days' => $redemption, 'is_active' => true]);
        DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => $name === 'gr' ? 2 : 1, 'currency_code' => 'EUR', 'price' => '23.33']);

        return $tld;
    }

    private function domain(string $sld, array $attributes = [], ?DomainTld $tld = null): Domain
    {
        $tld ??= $this->tld;

        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => $sld, 'tld' => $tld->tld, 'fqdn' => "{$sld}.{$tld->tld}",
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Active, 'expires_at' => '2026-11-01',
            'currency_code' => 'EUR', 'years' => $tld->tld === 'gr' ? 2 : 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    private function cron(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    private function renewalInvoice(Domain $domain): Invoice
    {
        return InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')->sole()->invoice;
    }

    private function credit(Invoice ...$invoices): void
    {
        (new CreditLedger)->add($this->user->client()->id, 'EUR', array_sum(array_map(fn (Invoice $invoice) => RecordPaymentService::toCents($invoice->fresh()->total), $invoices)),
            MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');
    }

    /** What the cron told staff about $name (its «needs staff attention» mail). */
    private function told(string $name): int
    {
        return EmailLog::query()->where('subject', 'Cron Job: needs staff attention')->get()
            ->filter(fn (EmailLog $mail) => str_contains((string) $mail->body, $name) && str_contains((string) $mail->body, 'past its last recoverable day'))->count();
    }

    /**
     * Credit meeting the invoice before the cron cancelled it (credit arriving in the day: CreditAutoApply::forCustomer)
     * skips it and pays what is still owed; a domain expired inside its window (15 days, of 30 not known) is paid as
     * before; and the customer paying the dead one on purpose is not stopped.
     */
    public function test_credit_does_not_pay_the_renewal_of_a_domain_lapsed_for_good_and_a_payment_on_purpose_is_not_blocked(): void
    {
        $dead = $this->domain('dead');
        $this->cron();
        $deadInvoice = $this->renewalInvoice($dead);
        Carbon::setTestNow('2027-01-10 10:00:00');
        $recent = $this->domain('recent', ['expires_at' => '2027-01-15']);
        $this->cron(); // the dead one still reads «active» here (no sync ran): its invoice stays
        $recentInvoice = $this->renewalInvoice($recent);
        Carbon::setTestNow('2027-01-30 10:00:00'); // dead: 90 days past its expiry; recent: 15
        DB::table('domains')->whereIn('id', [$dead->id, $recent->id])->update(['status' => DomainStatus::Expired->value]);
        $this->assertTrue($dead->fresh()->lapsedForGood());
        $this->assertFalse($recent->fresh()->lapsedForGood());
        $this->assertSame([Invoice::STATUS_UNPAID, Invoice::STATUS_UNPAID], [$deadInvoice->fresh()->status, $recentInvoice->fresh()->status]);

        $this->credit($deadInvoice, $recentInvoice);
        (new CreditAutoApply)->forCustomer($this->user->client()->id, 'EUR');

        $this->assertSame(Invoice::STATUS_UNPAID, $deadInvoice->fresh()->status, 'the oldest due, of a domain past its last recoverable day: not credit\'s');
        $this->assertSame(Invoice::STATUS_PAID, $recentInvoice->fresh()->status, 'expired inside its window: paid as before');

        (new RecordPaymentService)->handle($deadInvoice->fresh(), null, (float) $deadInvoice->fresh()->remaining, transactionId: 's7-domains-on-purpose');
        $this->assertSame(Invoice::STATUS_PAID, $deadInvoice->fresh()->status, 'the customer\'s own payment is taken');
    }

    /**
     * The cron's domain_invoices_cancelled step: stage days not known, so 30 days from the expiry. Day 30 nothing; day 31
     * the invoice is cancelled with its reason and staff are told, once; an invoice staff reopen is theirs.
     */
    public function test_the_cron_cancels_the_open_renewal_invoice_of_a_lapsed_domain_after_its_last_recoverable_day_and_tells_staff_once(): void
    {
        $dead = $this->domain('dead');
        $this->cron();
        $invoice = $this->renewalInvoice($dead);
        $this->assertSame('2026-12-01', $dead->fresh()->recoveryEndsOn()->toDateString());

        Carbon::setTestNow('2026-12-01 10:00:00');
        $this->artisan('domains:sync')->assertExitCode(0);
        $this->cron();
        $this->assertSame(DomainStatus::Expired, $dead->fresh()->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'its last recoverable day: still open');
        $this->assertSame(0, $this->told('dead.com'));

        Carbon::setTestNow('2026-12-02 10:00:00');
        $this->cron();
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame(__('invoices.cancellation_reasons.domain_lapsed'), $invoice->fresh()->cancellation_reason);
        $this->assertSame(DomainStatus::Expired, $dead->fresh()->status, 'the row is left for staff to end');
        $this->assertSame(1, $this->told('dead.com'));

        Carbon::setTestNow('2026-12-03 10:00:00');
        $this->cron();
        $this->assertSame(1, $this->told('dead.com'), 'one notice: nor «not billed» from the billing step every night after it');

        // Staff reopen it (they renewed it by hand): not cancelled again every night (ADV-6), and still not credit's.
        $invoice->fresh()->update(['status' => Invoice::STATUS_UNPAID]);
        $this->credit($invoice);
        Carbon::setTestNow('2026-12-04 10:00:00');
        $this->cron();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame(1, $this->told('dead.com'));
    }

    /** Both stage days known (.gr's: 15 and 0) and a row its registrar has not ended (still «grace»): day 15 open, day 16 cancelled. */
    public function test_with_known_stage_days_the_last_recoverable_day_is_the_end_of_the_stages(): void
    {
        $gr = $this->tld('gr', 15, 0);
        $domain = $this->domain('example', [], $gr);
        $this->cron();
        $invoice = $this->renewalInvoice($domain);
        DB::table('domains')->where('id', $domain->id)->update(['status' => DomainStatus::Grace->value]);

        Carbon::setTestNow('2026-11-16 10:00:00');
        $this->assertFalse($domain->fresh()->lapsedForGood());
        $this->cron();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        Carbon::setTestNow('2026-11-17 10:00:00');
        $this->assertTrue($domain->fresh()->lapsedForGood());
        $this->cron();
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame(1, $this->told('example.gr'));
    }

    /** Active, pending and ended rows are never «lapsed for good», whatever their dates. */
    public function test_only_a_row_after_its_expiry_and_not_ended_is_lapsed_for_good(): void
    {
        Carbon::setTestNow('2027-06-01 10:00:00');
        foreach ([DomainStatus::Active, DomainStatus::PendingRegister, DomainStatus::PendingTransfer, DomainStatus::Deleted, DomainStatus::Cancelled, DomainStatus::TransferredAway] as $status) {
            $this->assertFalse((new Domain(['expires_at' => '2026-11-01']))->forceFill(['status' => $status])->lapsedForGood(), $status->value);
        }
        foreach (Domain::LAPSED as $status) {
            $this->assertTrue((new Domain(['expires_at' => '2026-11-01']))->forceFill(['status' => $status])->lapsedForGood(), $status->value);
            $this->assertFalse((new Domain(['expires_at' => null]))->forceFill(['status' => $status])->lapsedForGood(), "{$status->value}, no expiry");
        }
    }

    /**
     * A .gr domain with no registrar API (a manual connection) 16 days past its expiry, with the registry's days: deleted
     * by its dates (both stages known), so its name is free and credit does not pay its renewal; its open invoice goes
     * with the other lost domains', never sooner than 30 days after the expiry (D7, CronJob::pastRecovery).
     */
    public function test_a_gr_domain_without_a_registrar_api_is_deleted_sixteen_days_after_its_expiry(): void
    {
        $gr = $this->tld('gr', DomainTld::GR_STAGE_DAYS['grace_days'], DomainTld::GR_STAGE_DAYS['redemption_days']);
        $domain = $this->domain('example', [], $gr);
        $this->cron();
        $invoice = $this->renewalInvoice($domain);

        Carbon::setTestNow('2026-11-16 10:00:00'); // day 15: the last of its grace
        $this->artisan('domains:sync')->assertExitCode(0);
        $this->assertSame(DomainStatus::Grace, $domain->fresh()->status);

        Carbon::setTestNow('2026-11-17 10:00:00'); // day 16
        $this->artisan('domains:sync')->assertExitCode(0);
        $this->assertSame(DomainStatus::Deleted, $domain->fresh()->status);
        $this->assertNull($domain->fresh()->live_fqdn, 'its name is free to order');
        $this->credit($invoice);
        $this->cron();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'not paid by credit, and open until 30 days after the expiry');

        Carbon::setTestNow('2026-12-02 10:00:00');
        $this->cron();
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
    }

    /** The importer's catalogue step: .gr and its second-level forms get 15 / 0 where WHMCS says «-1»; what it says is kept. */
    public function test_the_import_gives_gr_tlds_their_registrys_stage_days_where_whmcs_does_not_say(): void
    {
        DomainTld::query()->delete();
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $client = $this->user->client()->id;
        foreach ([
            'CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT, prefix TEXT, suffix TEXT, format INT, rate REAL, "default" INT)',
            "INSERT INTO tblcurrencies VALUES (1, 'EUR', '', '€', 3, 1, 1)",
            'CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)',
            "INSERT INTO tblclients VALUES ({$client}, 1)",
            'CREATE TABLE tblconfiguration (setting TEXT, value TEXT)',
            'CREATE TABLE tbldomainpricing (id INTEGER PRIMARY KEY, extension TEXT, autoreg TEXT, eppcode INT, dnsmanagement INT, emailforwarding INT,
                idprotection INT, grace_period INT, redemption_grace_period INT, "order" INT)',
            "INSERT INTO tbldomainpricing VALUES (1, '.gr', '', 0, 0, 0, 0, -1, -1, 1), (2, '.com.gr', '', 0, 0, 0, 0, -1, -1, 2),
                (3, '.net.gr', '', 0, 0, 0, 0, 20, -1, 3), (4, '.org.gr', '', 0, 0, 0, 0, -1, 5, 4), (5, '.edu.gr', '', 0, 0, 0, 0, 0, 30, 5),
                (6, '.com', '', 1, 0, 0, 0, -1, -1, 6), (7, '.agr', '', 1, 0, 0, 0, -1, -1, 7)",
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
        ] as $sql) {
            $whmcs->exec($sql);
        }

        (new WhmcsDomainImport($whmcs))->run();

        $days = DomainTld::query()->orderBy('whmcs_id')->get()->mapWithKeys(fn (DomainTld $tld) => [$tld->tld => [$tld->grace_days, $tld->redemption_days]])->all();
        $this->assertSame(['gr' => [15, 0], 'com.gr' => [15, 0], 'net.gr' => [20, 0], 'org.gr' => [15, 5], 'edu.gr' => [0, 30], 'com' => [null, null], 'agr' => [null, null]], $days);
    }

    /** The migration, for the rows already here: only what is still not known, only .gr's. */
    public function test_the_migration_sets_the_stage_days_of_gr_tlds_still_not_known(): void
    {
        $this->tld('gr', null, null);
        $this->tld('com.gr', null, 7);
        $this->tld('net.gr', 10, null);
        $this->tld('agr', null, null);

        (require database_path('migrations/2027_02_18_000000_set_gr_tld_stage_days.php'))->up();

        $days = DomainTld::query()->orderBy('id')->get()->mapWithKeys(fn (DomainTld $tld) => [$tld->tld => [$tld->grace_days, $tld->redemption_days]])->all();
        $this->assertSame(['com' => [null, null], 'gr' => [15, 0], 'com.gr' => [15, 7], 'net.gr' => [10, 0], 'agr' => [null, null]], $days);
    }
}
