<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\Domain;
use App\Models\User;
use App\Services\Import\WhmcsDomainImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

/**
 * Independent review of #655 (WG-12: imported domains at or above their TLD's renew price follow the catalog).
 * The WHMCS fixture is ImportDomainsTest's.
 */
class Review655Test extends TestCase
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

    /** .com renew (EUR): 1 year 10.00, 2 years 19.00. */
    private function comPrices(): void
    {
        foreach ([[3, 'domainrenew'], [3, 'domainregister']] as $i => [$tld, $type]) {
            $this->whmcs->exec('INSERT INTO tblpricing (id, type, relid, currency, tsetupfee, msetupfee, qsetupfee, ssetupfee, asetupfee, bsetupfee,
                monthly, quarterly, semiannually, annually, biennially, triennially) VALUES (' . (200 + $i) . ", '{$type}', {$tld}, 1, 0, 10, 19, -1, -1, -1, -1, -1, -1, -1, -1, -1)");
        }
    }

    /**
     * R655-1: a domain in grace (or redemption) that now follows the catalog loses its own period: stageRenewalYears()
     * goes by the TLD's shortest period once recurring_amount is null. Before #655 it renewed for its 2 years at WHMCS's
     * 19.00 (what WHMCS bills); now the cron bills 1 year at 10.00, while expectedRenewal() (and so WhmcsForecastParity's
     * «renewal amount», which compares it with renewalPriceFor(nextRenewalYears())) still says 19.00: a false mismatch
     * on every such domain (myip: one Grace domain of 9 years on a TLD whose shortest period is 1).
     */
    public function test_a_followed_domain_in_grace_is_expected_at_what_it_is_billed(): void
    {
        $this->comPrices();
        $this->domain(81, 'late.com', 'Grace', 'openprovider', ['recurringamount' => 19.00, 'registrationperiod' => 2, 'expirydate' => '2026-10-20']);

        (new WhmcsDomainImport($this->whmcs))->run();

        $domain = Domain::query()->with(['domainTld.prices', 'client'])->findOrFail(81);
        $this->assertSame(DomainStatus::Grace, $domain->status);
        // The fix chosen (the reviewer's first, «safer»): in grace or redemption it keeps its own amount, and so its 2 years.
        $this->assertSame('19.00', $domain->recurring_amount, 'in grace: its own amount kept, never the TLD\'s shortest period mid-stage');
        $this->assertSame(2, $domain->nextRenewalYears());

        // What WhmcsForecastParity compares: the expected renewal against what the cron bills (domainLine).
        $billed = $domain->renewalPriceFor($domain->nextRenewalYears());
        $this->assertSame(WhmcsDomainImport::expectedRenewal($domain, '19.00'), $billed,
            'the parity expects 19.00 (2 years) but the cron bills ' . $billed . ' for ' . $domain->nextRenewalYears() . ' year(s)');
    }
}
