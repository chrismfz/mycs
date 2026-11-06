<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\User;
use App\Services\Domains\Pricing\TldImport;
use App\Services\Domains\Pricing\TldImportOptions;
use App\Services\Import\WhmcsDomainImport;
use App\Support\Domains\TldPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

/**
 * Adversarial review of #670 (WG-32: ID protection free, offered where Openprovider allows it).
 *
 * ADV-670-1: ID protection is free here, yet its WHMCS price is only dropped when a domain's amount happens to be above its
 * TLD's price. Every amount the import keeps (a deal, an amount with DNS management in it, a domain in grace or
 * redemption) still carries WHMCS's ID protection price, and a deal pushed above the TLD price by that addon renews at the
 * full TLD price instead of its deal. WHMCS knows the addon's price (tblpricing type «domainaddons», ssetupfee: ID
 * protection, per year), so it can be taken out.
 *
 * ADV-670-2: the WHMCS import sets a TLD's id_protection from WHMCS's own per-TLD flag (CatalogImport), not from whether the
 * registry allows it; a re-run of the import (rehearsals, cut-over) undoes what the registrar said, until the weekly
 * cost sync flips it back: a loop, audited each way.
 */
class Adv670IdProtectionImportTest extends TestCase
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
            // .com in WHMCS: ID protection sold (idprotection = 1), registered at Openprovider.
            "INSERT INTO tbldomainpricing VALUES (3, '.com', 'openprovider', 1, 1, 1, 1, 30, 30, 3)",
            'CREATE TABLE tblpricing (id INTEGER PRIMARY KEY, type TEXT, relid INT, currency INT, tsetupfee REAL, msetupfee REAL, qsetupfee REAL, ssetupfee REAL,
                asetupfee REAL, bsetupfee REAL, monthly REAL, quarterly REAL, semiannually REAL, annually REAL, biennially REAL, triennially REAL)',
            // .com renew and register: 1 year 10.00, 2 years 19.00.
            "INSERT INTO tblpricing VALUES (100, 'domainrenew', 3, 1, 0, 10, 19, -1, -1, -1, -1, -1, -1, -1, -1, -1)",
            "INSERT INTO tblpricing VALUES (101, 'domainregister', 3, 1, 0, 10, 19, -1, -1, -1, -1, -1, -1, -1, -1, -1)",
            // WHMCS's domain addons, a year: DNS management (msetupfee) 2.50, email forwarding (qsetupfee) 1.00, ID protection (ssetupfee) 2.50.
            "INSERT INTO tblpricing VALUES (102, 'domainaddons', 0, 1, 0, 2.50, 1.00, 2.50, 0, 0, 0, 0, 0, 0, 0, 0)",
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

    private function domain(int $id, string $name, string $status, array $extra = []): void
    {
        $row = array_merge(['userid' => $this->client->id, 'orderid' => 0, 'type' => 'Register', 'registrationdate' => '2020-03-01', 'domain' => $name,
            'firstpaymentamount' => 10, 'recurringamount' => 12.5, 'registrar' => 'openprovider', 'registrationperiod' => 1, 'expirydate' => '2027-03-01',
            'status' => $status, 'nextduedate' => '2027-03-01', 'additionalnotes' => '', 'dnsmanagement' => 0, 'emailforwarding' => 0, 'idprotection' => 0,
            'donotrenew' => 0, 'created_at' => '2020-03-01 10:00:00', 'updated_at' => '2026-03-01 10:00:00'], $extra);
        $columns = implode(', ', array_keys($row));
        $this->whmcs->prepare("INSERT INTO tbldomains (id, {$columns}) VALUES (?, " . implode(', ', array_fill(0, count($row), '?')) . ')')
            ->execute([$id, ...array_values($row)]);
    }

    private function run_(): array
    {
        return (new WhmcsDomainImport($this->whmcs))->run();
    }

    private function renewsAt(int $id): ?string
    {
        return Domain::query()->with('domainTld.prices')->findOrFail($id)->renewalPrice();
    }

    /** ADV-670-1: a deal (7.00 against the TLD's 10.00) with ID protection on (2.50 in WHMCS): the free addon's price stays. */
    public function test_a_deal_with_id_protection_keeps_paying_for_the_free_addon(): void
    {
        $this->domain(71, 'deal-idp.com', 'Active', ['recurringamount' => 9.50, 'idprotection' => 1]);

        $this->run_();

        $this->assertSame('7.00', $this->renewsAt(71), 'its deal, without the ID protection WHMCS priced in it (free here, WG-32): not 9.50');
    }

    /**
     * ADV-670-1: a deal of 8.00 that ID protection (2.50) took above the TLD's 10.00 is read as «dearer» and renews at
     * 10.00: dearer than the deal the customer had, for an addon that is now free.
     */
    public function test_a_deal_lifted_above_the_tld_price_by_id_protection_loses_its_deal(): void
    {
        $this->domain(72, 'deal-lifted.com', 'Active', ['recurringamount' => 10.50, 'idprotection' => 1]);

        $this->run_();

        $this->assertSame('8.00', $this->renewsAt(72), 'its deal of 8.00, ID protection free: not the TLD\'s 10.00');
    }

    /** ADV-670-1: DNS management keeps the amount (it has no price here), but the ID protection in it is free now. */
    public function test_dns_management_keeps_the_amount_with_the_id_protection_price_in_it(): void
    {
        // 10.00 + DNS management 2.50 + ID protection 2.50.
        $this->domain(73, 'dns-idp.com', 'Active', ['recurringamount' => 15.00, 'idprotection' => 1, 'dnsmanagement' => 1]);

        $this->run_();

        $this->assertSame('12.50', $this->renewsAt(73), 'the TLD and DNS management, without ID protection: not 15.00');
    }

    /** ADV-670-1: a domain in grace keeps its own amount (its years), the ID protection WHMCS priced in it with it. */
    public function test_a_domain_in_grace_keeps_paying_for_id_protection(): void
    {
        $this->domain(74, 'grace-idp.com', 'Grace', ['recurringamount' => 12.50, 'idprotection' => 1, 'expirydate' => '2026-10-25', 'nextduedate' => '2026-10-25']);

        $this->run_();

        $this->assertSame('10.00', $this->renewsAt(74), 'its own amount less the free ID protection: not 12.50');
    }

    /**
     * ADV-670-2: Openprovider says .com's registry does not allow private WHOIS; a re-run of the WHMCS import turns the
     * TLD's ID protection back on from WHMCS's own flag, against the registrar (the weekly cost sync then flips it again).
     */
    public function test_a_rerun_of_the_import_undoes_what_the_registrar_said(): void
    {
        $this->run_();
        $com = DomainTld::query()->where('tld', 'com')->firstOrFail();
        $op = DomainRegistrarConnection::query()->where('registrar', 'openprovider')->firstOrFail();
        $this->assertSame($op->id, $com->registrar_connection_id);

        $quote = ['cost' => '8.00', 'currency' => 'EUR'];
        app(TldImport::class)->import($op, ['com' => new TldPricing(tld: 'com', costs: ['register' => $quote, 'renew' => $quote], privacyAllowed: false)],
            new TldImportOptions(marginType: 'percent', margin: '20'));
        $this->assertFalse($com->fresh()->id_protection, 'the registrar: not allowed');

        $this->run_(); // the importer is re-runnable (rehearsals, the cut-over)

        $this->assertFalse($com->fresh()->id_protection, 'a re-run keeps what the registrar said, not WHMCS\'s idprotection = 1');
    }
}
