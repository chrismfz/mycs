<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Jobs\Domains\RenewDomainJob;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\DomainRenewalYears;
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
 * Adversarial review of #656 (a client group's discount on domains). The tests named ADV-656-n fail on the PR head and
 * prove a finding; the test_held_* ones pass and pin what the review tried and found sound. Fake data only.
 */
class Adv656GroupDomainsTest extends TestCase
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

    /** .com's renew and register prices in EUR: [1 year, 2 years]. */
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

    /** One catalog-priced .com (19.00 for its 2 years, 10.00 for 1), imported: it follows its TLD's price. */
    private function catalogDomain(int $id = 91, string $name = 'adv656.com'): Domain
    {
        $this->comPrices(10.00, 19.00);
        $this->domain($id, $name, ['recurringamount' => 19.00, 'registrationperiod' => 2]);
        (new WhmcsDomainImport($this->whmcs))->run();
        $domain = Domain::query()->findOrFail($id);
        $this->assertNull($domain->recurring_amount, 'precondition: follows the catalog');

        return $domain;
    }

    private function bill(int $domainId): ?InvoiceItem
    {
        $billing = app(RenewalBilling::class);
        DB::transaction(fn () => $billing->billDomainRenewal($domainId, now()->addDays(RenewalBilling::domainInvoiceDays())));

        return InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domainId)->whereNull('kind')->latest('id')->first();
    }

    private function group(string $percent, bool $domains): ClientGroup
    {
        $group = ClientGroup::query()->create(['name' => 'Adv ' . $percent . ($domains ? ' d' : ''), 'discount_percent' => $percent, 'applies_to_domains' => $domains]);
        $this->client->forceFill(['client_group_id' => $group->id])->save();

        return $group;
    }

    /**
     * ADV-656-1 (medium, owner's decision): a 100% group with «Also on domains» bills its members' domain renewals at 0.00,
     * and the cron closes a 0.00 invoice as paid by itself (finishRenewalInvoices: total <= 0 → paid). A paid renewal
     * line is then renewed at the registrar, which charges the company the full cost: free domains for life, nobody asked.
     * A 100% group exists for services (staff or partner accounts on free hosting); one click on the new switch extends
     * it to every one of their domains. Fix: refuse «Also on domains» at 100% (or cap it), or leave a renewal the group's
     * discount brought to nothing for staff (never closed as paid on its own). Drop this test if free is wanted.
     */
    public function test_adv_656_1_a_whole_group_discount_never_renews_domains_for_free_by_itself(): void
    {
        $domain = $this->catalogDomain();
        $this->group('100.00', true);

        $line = $this->bill($domain->id);
        $this->assertNotNull($line, 'precondition: billed');
        app(RenewalBilling::class)->finishRenewalInvoices();
        $invoice = Invoice::query()->findOrFail($line->invoice_id);
        $renewals = Queue::pushed(RenewDomainJob::class)->count();

        $this->assertNotSame(Invoice::STATUS_PAID, $invoice->status,
            "A 0.00 domain renewal ({$line->price}) the group's 100% made is closed as paid by itself; renewals queued at the registrar: {$renewals}.");
        $this->assertSame(0, $renewals, 'never renewed at the registrar for nothing');
    }

    /**
     * ADV-656-2 (low, parity): the import's parities still say a member's catalog domain renews at its TLD's full price.
     * WhmcsDomainImport::expectedRenewal (used by WhmcsRecordParity «renews at», WhmcsParity «Domains, renewal amount»
     * and WhmcsForecastParity «renewal amount») ignores D-23, while renewalPrice() bills it less the group's percentage.
     * The group's switch survives a re-import (importClientGroups updates only its own columns), so once the owner turns it
     * on every member's domain is reported «wrong» in the rehearsal and cut-over parity, hiding real mismatches among them.
     * Fix: expectedRenewal applies the same rule (catalogRenewalPrice where it follows the catalog), as the forecast parity
     * already discounts WHMCS's service amounts by the group's percentage.
     */
    public function test_adv_656_2_the_import_parity_expects_what_the_billing_bills(): void
    {
        $domain = $this->catalogDomain();
        $this->group('25.00', true);
        $domain = Domain::query()->with('domainTld.prices')->findOrFail($domain->id);

        $this->assertSame('14.25', $domain->renewalPrice(), 'precondition: 19.00 less 25%');
        $this->assertSame($domain->renewalPrice(), WhmcsDomainImport::expectedRenewal($domain, '19.00'),
            'The parity expects the full 19.00 while the billing bills 14.25: every member domain reads as a mismatch.');
    }

    /** Held: the cron bills the discounted price; the portal, the reminders and «Renew now» read the same function. */
    public function test_held_billing_and_every_reader_agree(): void
    {
        $domain = $this->catalogDomain();
        $this->group('33.33', true);
        $domain->refresh();

        // 19.00 × 33.33% = 6.3327 → 6.33; 12.67. One rounding, on the period's price (no per-year drift).
        $this->assertSame('12.67', $domain->renewalPriceFor($domain->nextRenewalYears()));
        $line = $this->bill($domain->id);
        $this->assertSame('12.67', (string) $line->price);
    }

    /** Held: in grace the renewal part is the discounted stage period, the grace fee is never discounted. */
    public function test_held_grace_fee_is_never_discounted(): void
    {
        $domain = $this->catalogDomain();
        DomainTldPrice::query()->create(['domain_tld_id' => $domain->domain_tld_id, 'operation' => 'grace', 'years' => 1, 'currency_code' => 'EUR', 'price' => '8.00']);
        $this->group('25.00', true);
        $domain->forceFill(['status' => DomainStatus::Grace, 'expires_at' => now()->subDays(3)])->save();
        $domain->refresh();

        $this->assertSame(1, $domain->nextRenewalYears(), 'the stage\'s shortest period');
        $this->assertSame('7.50', $domain->renewalPriceFor(1), '10.00 less 25%');
        $this->assertSame('8.00', $domain->stageFee(DomainStatus::Grace), 'the fee as its TLD has it');
    }

    /**
     * Held: an open line billed before the switch keeps its price, and a period change then leaves it (NEXT): the catalog
     * net it is compared with is the discounted one, so a line at the full price reads as «not the catalog's».
     */
    public function test_held_an_open_line_keeps_its_price_through_a_period_change(): void
    {
        $domain = $this->catalogDomain();
        $line = $this->bill($domain->id);
        $this->assertSame('19.00', (string) $line->price);
        $this->group('25.00', true);

        $result = app(DomainRenewalYears::class)->change($domain->refresh(), 1, byStaff: true);

        $this->assertSame(DomainRenewalYears::NEXT, $result);
        $this->assertSame('19.00', (string) $line->refresh()->price);
    }

    /** Held: a domain's own price is never discounted, nor is a group discount without the switch. */
    public function test_held_own_price_and_switch_off(): void
    {
        $domain = $this->catalogDomain();
        $this->group('25.00', false);
        $this->assertSame('19.00', $domain->refresh()->renewalPrice());
        $this->client->group->update(['applies_to_domains' => true]);
        $domain->forceFill(['recurring_amount' => '17.00'])->save();
        $this->assertSame('17.00', $domain->refresh()->renewalPrice());
        $this->assertSame('17.00', (string) $this->bill($domain->id)->price);
    }
}
