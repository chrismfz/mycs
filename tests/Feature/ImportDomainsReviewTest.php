<?php

namespace Tests\Feature;

use App\Console\Commands\CronJob;
use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\Domains\DomainRenewalService;
use App\Services\Import\WhmcsDomainImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use ReflectionMethod;
use Tests\TestCase;

/**
 * WHMCS's domains (D6): their registrar connections and TLDs, each domain with its dates, amount, addons and contacts,
 * the Openprovider handles that are their contact's, the notices WHMCS sent for the current expiry, and their invoice
 * lines (a paid renewal applied: never renewed again at the registrar).
 */
class ImportDomainsReviewTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['settings.default_currency' => 'USD', 'settings.domain_reminder_days' => '30,7,1,-3']);
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
        ] as $sql) {
            $this->whmcs->exec($sql);
        }
    }

    private function domain(int $id, string $name, string $status, string $registrar, array $extra = []): void
    {
        $row = array_merge(['userid' => $this->client->id, 'orderid' => 0, 'type' => 'Register', 'registrationdate' => '2020-03-01', 'domain' => $name,
            'firstpaymentamount' => 10, 'recurringamount' => 12.5, 'registrar' => $registrar, 'registrationperiod' => 1, 'expirydate' => '2027-03-01',
            'status' => $status, 'nextduedate' => '2027-03-01', 'additionalnotes' => '', 'dnsmanagement' => 0, 'emailforwarding' => 0, 'idprotection' => 0,
            'donotrenew' => 0, 'created_at' => '2020-03-01 10:00:00', 'updated_at' => '2026-03-01 10:00:00'], $extra);
        $columns = implode(', ', array_keys($row));
        $this->whmcs->prepare("INSERT INTO tbldomains (id, {$columns}) VALUES (?, " . implode(', ', array_fill(0, count($row), '?')) . ')')
            ->execute([$id, ...array_values($row)]);
    }

    /** An Openprovider customer as the WHMCS module keeps it: an object of its own class (not loaded here). */
    private static function customer(string $email, string $handle): string
    {
        $data = serialize((object) ['email' => $email, 'handle' => $handle, 'additionalData' => (object) ['passportNumber' => 'X123']]);

        return str_replace('O:8:"stdClass"', 'O:12:"OP_Customer1"', $data);
    }

    private function run_(): array
    {
        return (new WhmcsDomainImport($this->whmcs))->run();
    }

    /** R1: a domain whose WHMCS registrar has no driver (enom) or none at all is put on its TLD's connection after all. */
    public function test_r1_a_domain_without_a_driver_never_falls_to_its_tlds_registrar(): void
    {
        $this->domain(15, 'odd.example.com', 'Active', 'enom');
        $this->domain(18, 'none.example.com', 'Grace', '');
        $this->run_();
        foreach ([15, 18] as $id) {
            $domain = Domain::query()->find($id);
            // The fix suggested: the manual connection (no API), never the TLD's registrar.
            $this->assertSame('manual', $domain->effectiveRegistrarConnection()?->registrar,
                "#{$id}: WHMCS says it is not at Openprovider, yet it is synced, renewed and marked transferred away there");
        }
    }

    /** R2: «N Χρόνια» (7 of 10k myip renewal lines use it, 600+) is not read: the period gets the domain's years. */
    public function test_r2_a_renewal_line_in_chronia_keeps_its_own_years(): void
    {
        $this->domain(41, 'lines.example.com', 'Active', 'openprovider', ['registrationperiod' => 2]);
        $this->run_();
        $import = new WhmcsDomainImport($this->whmcs);
        $line = $import->line(['type' => 'Domain', 'relid' => 41, 'duedate' => '2026-03-01', 'invoice_status' => 'Unpaid',
            'description' => 'Ανανέωση Domain - lines.example.com - 1 Χρόνια (01/03/2026 - 28/02/2027)']);
        $this->assertSame('2027-03-01', $line['period_end'], 'one year billed; paid after the cut-over, two would be charged at the registrar');
    }

    /** R3: paid in WHMCS but WHMCS's expiry never moved (its renew failed): applied, so never renewed, billed, nor raised. */
    public function test_r3_a_paid_renewal_whmcs_did_not_apply_is_not_lost(): void
    {
        $this->domain(41, 'lines.example.com', 'Active', 'openprovider', ['expirydate' => '2026-11-30']);
        $this->run_();
        $import = new WhmcsDomainImport($this->whmcs);
        $reference = $import->line(['type' => 'Domain', 'relid' => 41, 'duedate' => '2026-11-30', 'invoice_status' => 'Paid', 'invoice_datepaid' => '2026-10-01',
            'description' => 'Domain Renewal - lines.example.com - 1 Year/s (30/11/2026 - 29/11/2027)']);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'currency_code' => 'EUR']);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid']);
        DB::table('invoice_items')->insert(['invoice_id' => $invoice->id, 'description' => 'x', 'price' => 12.5, 'created_at' => now(), 'updated_at' => now()] + $reference);
        $domain = Domain::query()->find(41);
        $billed = DomainRenewalService::billedLine($domain, $domain->expires_at->copy()->startOfDay()) !== null;
        $waiting = DomainRenewalService::waitingLines()->count() > 0;
        $this->assertTrue(!$billed || $waiting, 'the period from the current expiry counts as billed and renewed, while WHMCS shows it never renewed');
    }

    /** R4: a live domain WHMCS still has «Pending» (renewed for years, from an order) is cancelled by the first cron as an unpaid order. */
    public function test_r4_a_long_paid_domain_pending_in_whmcs_is_not_cancelled_as_an_unpaid_order(): void
    {
        $order = Order::query()->create(['client_id' => $this->client->id, 'currency_code' => 'EUR']);
        $this->domain(554, 'stuck.example.com', 'Pending', 'openprovider', ['type' => 'Transfer', 'orderid' => $order->id, 'registrationdate' => '2010-05-10',
            'expirydate' => '2027-06-24', 'created_at' => '2010-05-10 10:00:00']);
        $this->run_();
        $this->assertSame(DomainStatus::Active, Domain::query()->find(554)->status, 'registered (an expiry): live, not an order');
        $query = (new ReflectionMethod(CronJob::class, 'unpaidDomainOrders'))->invoke(app(CronJob::class), 7);
        $this->assertNotContains(554, $query->pluck('id')->map(fn ($id) => (int) $id)->all(), 'cancelled by the cron, its order with it');
    }
}
