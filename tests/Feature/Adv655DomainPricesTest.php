<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Import\WhmcsDomainImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PDO;
use Tests\TestCase;

/**
 * Adversarial review of #655 (WG-12: an imported domain at or above its TLD's renew price follows the catalog). Each test
 * imports invented WHMCS rows and asserts what the domain should renew at (amount and years) by what WHMCS billed it.
 */
class Adv655DomainPricesTest extends TestCase
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
            'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false]);
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
            "INSERT INTO tbldomainpricing VALUES (3, '.com', 'openprovider', 1, 1, 1, 1, 30, 30, 3)",
            'CREATE TABLE tblpricing (id INTEGER PRIMARY KEY, type TEXT, relid INT, currency INT, tsetupfee REAL, msetupfee REAL, qsetupfee REAL, ssetupfee REAL,
                asetupfee REAL, bsetupfee REAL, monthly REAL, quarterly REAL, semiannually REAL, annually REAL, biennially REAL, triennially REAL)',
            'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, orderid INT, type TEXT, registrationdate TEXT, domain TEXT, firstpaymentamount REAL,
                recurringamount REAL, registrar TEXT, registrationperiod INT, expirydate TEXT, status TEXT, nextduedate TEXT, additionalnotes TEXT,
                dnsmanagement INT, emailforwarding INT, idprotection INT, donotrenew INT, is_premium INT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE tbldomainsadditionalfields (id INTEGER PRIMARY KEY, domainid INT, name TEXT, value TEXT)',
            'CREATE TABLE tbldomains_extra (id INTEGER PRIMARY KEY, domain_id INT, name TEXT, value TEXT)',
            'CREATE TABLE wHandles (id INTEGER PRIMARY KEY, user_id INT, registrar TEXT, type TEXT, handle TEXT, data TEXT)',
            'CREATE TABLE wDomain_handle (id INTEGER PRIMARY KEY, type TEXT, domain_id INT, handle_id INT)',
            'CREATE TABLE tbldomainreminders (id INTEGER PRIMARY KEY, domain_id INT, date TEXT, recipients TEXT, type INT, days_before_expiry INT)',
        ] as $sql) {
            $this->whmcs->exec($sql);
        }
    }

    /** .com's renew and register prices in EUR: [1 year, 2 years] (WHMCS msetupfee, qsetupfee; -1 the rest). */
    private function comPrices(float $one, float $two): void
    {
        foreach (['domainrenew', 'domainregister'] as $i => $type) {
            $this->whmcs->exec('INSERT INTO tblpricing (id, type, relid, currency, tsetupfee, msetupfee, qsetupfee, ssetupfee, asetupfee, bsetupfee,
                monthly, quarterly, semiannually, annually, biennially, triennially) VALUES (' . (100 + $i) . ", '{$type}', 3, 1, 0, {$one}, {$two}, -1, -1, -1, -1, -1, -1, -1, -1, -1)");
        }
    }

    private function domain(int $id, string $name, array $extra = []): void
    {
        $expiry = $extra['expirydate'] ?? now()->addDays(10)->toDateString();
        $row = array_merge(['userid' => $this->client->id, 'orderid' => 0, 'type' => 'Register', 'registrationdate' => '2020-03-01', 'domain' => $name,
            'firstpaymentamount' => 10, 'recurringamount' => 12.5, 'registrar' => 'openprovider', 'registrationperiod' => 1, 'expirydate' => $expiry,
            'status' => 'Active', 'nextduedate' => $expiry, 'additionalnotes' => '', 'dnsmanagement' => 0, 'emailforwarding' => 0, 'idprotection' => 0,
            'donotrenew' => 0, 'is_premium' => 0, 'created_at' => '2020-03-01 10:00:00', 'updated_at' => '2026-03-01 10:00:00'], $extra);
        $columns = implode(', ', array_keys($row));
        $this->whmcs->prepare("INSERT INTO tbldomains (id, {$columns}) VALUES (?, " . implode(', ', array_fill(0, count($row), '?')) . ')')
            ->execute([$id, ...array_values($row)]);
    }

    /** The cron's domain billing for one domain (CronJob: billDomainRenewal, then addStageFee). */
    private function bill(int $domainId): ?InvoiceItem
    {
        $billing = app(RenewalBilling::class);
        DB::transaction(fn () => $billing->billDomainRenewal($domainId, now()->addDays(RenewalBilling::domainInvoiceDays())));

        return InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domainId)->whereNull('kind')->latest('id')->first();
    }

    /**
     * ADV-655-1 (end to end). WHMCS keeps 0.00 in a TLD's year column it never sells (CatalogImport imports 0.00 as «free and
     * offered»; only -1 is «not offered»). A 2-year .com billed 19.00 by WHMCS is «dearer» than that 0.00, so it follows the
     * catalog: the cron then bills its renewal at 0.00 and the registrar is charged for a renewal nobody pays. WHMCS charged
     * 19.00: never follow a catalog price of zero (or below what WHMCS billed when that is zero).
     */
    public function test_adv_655_1_a_dearer_domain_never_follows_a_zero_catalog_price_to_a_free_renewal(): void
    {
        $this->comPrices(10.00, 0.00);
        $this->domain(71, 'twoyears.com', ['recurringamount' => 19.00, 'registrationperiod' => 2]);

        (new WhmcsDomainImport($this->whmcs))->run();
        $line = $this->bill(71);

        $this->assertNotNull($line, 'its renewal is billed');
        $this->assertSame('19.00', (string) $line->price, 'WHMCS billed 19.00 for its two years: never a free renewal');
    }

    /**
     * ADV-655-2. A premium name (tbldomains.is_premium): WHMCS bills the registry's premium renewal, far above the TLD's
     * price, and the registrar charges it so. «Dearer» makes it follow the standard catalog price: billed 10.00 for a
     * renewal the registrar charges 120.00 for. Keep its own amount when it is premium (and import the flag). None on
     * myip/nixpal today.
     */
    public function test_adv_655_2_a_premium_domain_keeps_its_premium_renewal(): void
    {
        $this->comPrices(10.00, 19.00);
        $this->domain(72, 'premium.com', ['recurringamount' => 120.00, 'is_premium' => 1]);

        (new WhmcsDomainImport($this->whmcs))->run();

        $this->assertSame('120.00', Domain::query()->findOrFail(72)->renewalPrice(), 'a premium renewal, as WHMCS bills it');
    }

    /**
     * ADV-655-3. Only ID protection is guarded, but WHMCS prices DNS management and email forwarding the same way
     * (tblpricing type «domainaddons»: msetupfee DNS, qsetupfee email, ssetupfee ID protection), and none has a price here
     * either. A domain with DNS management at 3.00 a year billed 13.00 is «dearer» than its TLD's 10.00 and drops to 10.00.
     * Both companies have them at 0.00 today; a company that prices them loses them. Guard any priced addon that is on.
     */
    public function test_adv_655_3_a_priced_dns_management_addon_is_not_dropped(): void
    {
        $this->comPrices(10.00, 19.00);
        $this->whmcs->exec("INSERT INTO tblpricing (id, type, relid, currency, tsetupfee, msetupfee, qsetupfee, ssetupfee, asetupfee, bsetupfee,
            monthly, quarterly, semiannually, annually, biennially, triennially) VALUES (200, 'domainaddons', 0, 1, 0, 3.00, 2.00, 5.50, 0, 0, 0, 0, 0, 0, 0, 0)");
        $this->domain(73, 'dns.com', ['recurringamount' => 13.00, 'dnsmanagement' => 1]);

        (new WhmcsDomainImport($this->whmcs))->run();

        $this->assertSame('13.00', Domain::query()->findOrFail(73)->renewalPrice(), 'its DNS management is in its amount, as ID protection would be');
    }

    /**
     * ADV-655-4 (end to end). Before #655 an imported domain kept its amount, so in grace or redemption it kept its own period
     * (Domain::stageRenewalYears: «a price set by hand keeps its own period»). Following the catalog, it now renews for the
     * TLD's shortest period: a 2-year .com in grace, billed by WHMCS for 2 years at 19.00, is billed here 1 year at 10.00
     * (myip: pittaking.com, 9 years at 151.23 in WHMCS, would be billed 1 year at 16.50 once its open invoice is cancelled;
     * the forecast parity then reports 142.50 against 16.50). Per D-13 this may be wanted, but it is a change against WHMCS
     * the PR does not mention: keep the period of a grace/redemption domain at import, or say it in the notes and parity.
     */
    public function test_adv_655_4_a_domain_in_grace_renews_for_the_years_whmcs_bills(): void
    {
        $this->comPrices(10.00, 19.00);
        $this->domain(74, 'ingrace.com', ['recurringamount' => 19.00, 'registrationperiod' => 2, 'status' => 'Grace',
            'expirydate' => now()->subDays(5)->toDateString()]);

        (new WhmcsDomainImport($this->whmcs))->run();
        $line = $this->bill(74);

        $this->assertNotNull($line, 'its renewal is billed');
        $this->assertSame(2, (int) round($line->period_start->diffInMonths($line->period_end) / 12), 'its two years, as WHMCS bills them');
        $this->assertSame('19.00', (string) $line->price);
    }
}
