<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTldPrice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Import\WhmcsDomainImport;
use App\Services\Reports\PriceMismatch;
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
 * A client group's discount on domains (owner 2026-11-05): with «Also on domains», its members' domains at their TLD's
 * price renew at that price less the group's percentage, so a deal follows the catalog (19.00 at 25%: 14.25). Never on a
 * domain's own price nor without the switch. Fake data only.
 */
class Review656Test extends TestCase
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

    private function group(string $percent, bool $domains): ClientGroup
    {
        $group = ClientGroup::query()->create(['name' => 'Partners ' . $percent . ($domains ? ' d' : ''), 'discount_percent' => $percent, 'applies_to_domains' => $domains]);
        $this->client->forceFill(['client_group_id' => $group->id])->save();

        return $group;
    }

    /**
     * R656-1: the parities (WhmcsParity «Domains, renewal amount», WhmcsRecordParity per domain, WhmcsForecastParity) take
     * WhmcsDomainImport::expectedRenewal as what a domain renews at, beside renewalPrice()/renewalPriceFor(). With the
     * group's «Also on domains» on, expectedRenewal still says the TLD's full price: every catalog domain of the group
     * reads as a mismatch.
     */
    public function test_r656_1_expected_renewal_follows_the_group_discount_as_the_renewal_does(): void
    {
        $this->comPrices(10.00, 19.00);
        $this->domain(91, 'catalog.com', ['recurringamount' => 19.00, 'registrationperiod' => 2]);
        (new WhmcsDomainImport($this->whmcs))->run();
        $this->group('25.00', true);
        $domain = Domain::query()->with('domainTld.prices')->findOrFail(91);
        $this->assertNull($domain->recurring_amount, 'precondition: follows the catalog');
        $this->assertSame('14.25', $domain->renewalPrice(), 'precondition: renews at 19.00 less 25%');

        $this->assertSame($domain->renewalPrice(), WhmcsDomainImport::expectedRenewal($domain, '19.00'),
            'the parities compare what it renews at here with what they expect it to');
    }

    /**
     * R656-2: re-run with the group's switch on (groups keep it: importClientGroups never writes it), a WHMCS deal that is
     * exactly the group's discount off the catalog (the owner's own example: .gr 19.00 at 25% = 14.25) keeps its own price
     * (followsCatalog compares with the TLD's full price), so it never follows the catalog: the point of the switch.
     */
    public function test_r656_2_a_whmcs_amount_equal_to_the_discounted_catalog_follows_the_catalog_on_a_rerun(): void
    {
        $this->comPrices(10.00, 19.00);
        $this->domain(92, 'deal.com', ['recurringamount' => 14.25, 'registrationperiod' => 2]);
        $this->group('25.00', true);
        (new WhmcsDomainImport($this->whmcs))->run();

        $domain = Domain::query()->findOrFail(92);
        $this->assertNull($domain->recurring_amount, 'its WHMCS amount is the group\'s price for its TLD: it follows the catalog');
        DomainTldPrice::query()->where('operation', 'renew')->where('years', 2)->update(['price' => '21.00']);
        $this->assertSame('15.75', Domain::query()->findOrFail(92)->renewalPrice(), 'and up with it');
    }

    /**
     * R656-3: the price-mismatch report compares a domain's own price with its TLD's full price, while its services side
     * compares with the catalog less the group discount (Service::calculatePrice). With «Also on domains» a domain at its
     * own 14.25 is exactly what «Recalculate» would give (19.00 less 25%): not a mismatch; one at its own 19.00 is (14.25).
     */
    public function test_r656_3_price_mismatch_compares_a_domain_with_its_catalog_price_less_the_group_discount(): void
    {
        $this->comPrices(10.00, 19.00);
        $this->domain(93, 'deal.com', ['recurringamount' => 14.25, 'registrationperiod' => 2]);
        $this->domain(94, 'full.com', ['recurringamount' => 19.00, 'registrationperiod' => 2]);
        (new WhmcsDomainImport($this->whmcs))->run();
        Domain::query()->whereKey(94)->update(['recurring_amount' => '19.00']); // kept by staff at the full price
        $this->group('25.00', true);
        $this->assertSame('14.25', (string) Domain::query()->findOrFail(93)->recurring_amount, 'precondition: its own price');

        $rows = collect(PriceMismatch::rows())->where('type', 'domain')->keyBy('id');
        $this->assertFalse($rows->has(93), 'its own price is its catalog price for this account: '
            . json_encode($rows->get(93)));
        $this->assertSame('14.25', $rows->get(94)['catalog'] ?? null, 'the catalog for this account is 19.00 less 25%');
    }
}
