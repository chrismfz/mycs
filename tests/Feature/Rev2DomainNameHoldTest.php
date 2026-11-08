<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\User;
use App\Services\Domains\DomainLifecycle;
use App\Services\Domains\Ordering\DomainOffer;
use App\Services\Import\WhmcsDomainImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use PDO;
use Tests\TestCase;

/**
 * Second adversarial review of D-25 (owner 2026-11-08, after review): an expired row holds its name until the end of its
 * registry's recovery window (expiry + grace + redemption; a safe default where they are not known). Each test is a
 * finding that fails on feat/domain-name-hold at 0f9b8310.
 */
class Rev2DomainNameHoldTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 10:00:00');
        Queue::fake();
        MailFacade::fake();
        Cache::flush();
        Http::preventStrayRequests();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR', 'settings.domain_billing_enabled' => true]);
        $this->alice = User::factory()->create();
        $this->bob = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * REV2-DNH-1: a TLD whose grace is known (40 days) but whose redemption is not. The «30 days when not both known»
     * default is counted from the expiry, so it is swallowed by the known grace: the day the row leaves grace for expired
     * (DomainLifecycle, a manual registrar) its window has already ended, and it holds its name for not one day, while
     * its registry may still give it back (its redemption is unknown, not zero). The safe default never covers the part
     * that is not known.
     */
    public function test_an_expired_row_holds_after_a_known_grace_when_its_redemption_is_not_known(): void
    {
        $manual = DomainRegistrarConnection::query()->create(['registrar' => DomainRegistrarConnection::MANUAL, 'label' => 'Manual', 'is_active' => true, 'mode' => 'production']);
        $tld = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'sort' => 1, 'registrar_connection_id' => $manual->id,
            'grace_days' => 40, 'redemption_days' => null]);
        $domain = Domain::factory()->create(['client_id' => $this->alice->client()->id, 'sld' => 'mybusiness', 'tld' => 'com', 'fqdn' => 'mybusiness.com',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $manual->id, 'status' => DomainStatus::Grace, 'expires_at' => '2026-09-28']);

        // Its grace ended yesterday (2026-09-28 + 40 = 2026-11-07): the lifecycle moves it to expired today.
        $this->assertSame(DomainStatus::Expired, app(DomainLifecycle::class)->advance($domain->fresh(), Carbon::parse('2026-11-08')));

        $this->assertTrue($domain->fresh()->inRecoveryWindow(), 'its redemption is not known: still recoverable the day after its grace');
        $this->assertSame('mybusiness.com', $domain->fresh()->live_fqdn, 'it keeps holding its name');
    }

    /**
     * REV2-DNH-2: an expired row inside its window that does not hold (set back from cancelled by the model, or its TLD's
     * days raised after the cron released it) never gets its hold again: nothing re-claims it (the model sets it only
     * coming from a holding status, the cron only releases), so DomainOffer lets another customer order the name and
     * whoever pays first takes it from a customer whose registry still has it.
     */
    public function test_an_expired_row_set_back_inside_its_window_holds_its_name(): void
    {
        $domain = Domain::factory()->create(['client_id' => $this->alice->client()->id, 'sld' => 'mybusiness', 'tld' => 'com', 'fqdn' => 'mybusiness.com',
            'status' => DomainStatus::Cancelled, 'expires_at' => '2026-11-01']);
        $domain->forceFill(['status' => DomainStatus::Expired])->save(); // staff undo a cancel by mistake

        $this->assertTrue($domain->fresh()->inRecoveryWindow());
        $this->assertSame('mybusiness.com', $domain->fresh()->live_fqdn, 'expired inside its window: it holds its name');
        $this->expectException(DisplayException::class);
        app(DomainOffer::class)->check('mybusiness.com', 'register', 1, 'EUR', availability: false, clientId: $this->bob->client()->id);
    }

    /**
     * REV2-DNH-3: the importer. WHMCS let another customer order (and pay) a name whose expired row is still recoverable
     * at its registry. Here the expired row held first (it held since it was active), and the app never closes it for
     * another's order (closeOthers: left to staff) and refuses the later payment (claim). The importer ranks a pending
     * order above an expired row, so the recoverable row of its customer comes cancelled and the other's paid order,
     * never sent, holds the name and will be sent to a registry that still has it as the first customer's.
     */
    public function test_the_importer_never_cancels_an_expired_row_inside_its_window_for_another_customers_order(): void
    {
        Carbon::setTestNow('2026-11-08 00:00:00');
        $whmcs = $this->whmcs();
        $alice = $this->alice->client()->id;
        $bob = $this->bob->client()->id;
        $row = fn (int $id, int $user, string $status, string $expiry) => $whmcs->prepare('INSERT INTO tbldomains (id, userid, orderid, type, registrationdate, domain,
            firstpaymentamount, recurringamount, registrar, registrationperiod, expirydate, status, nextduedate, additionalnotes, dnsmanagement, emailforwarding,
            idprotection, donotrenew, created_at, updated_at) VALUES (?, ?, 0, ?, ?, ?, 10, 12.5, ?, 1, ?, ?, ?, ?, 0, 0, 0, 0, ?, ?)')
            ->execute([$id, $user, 'Register', '2020-03-01', 'recent.com', 'openprovider', $expiry, $status, $expiry, '', '2020-03-01 10:00:00', '2026-03-01 10:00:00']);
        $row(41, $alice, 'Expired', '2026-10-20'); // .com 30 + 30 here: recoverable till 2026-12-19
        $row(42, $bob, 'Pending', '0000-00-00');
        $whmcs->exec("INSERT INTO tblinvoices VALUES (1, 'Paid')");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (1, 1, 'DomainRegister', 42)");

        (new WhmcsDomainImport($whmcs))->run();

        $domains = Domain::query()->orderBy('id')->get()->keyBy('id');
        $this->assertSame(DomainStatus::Expired, $domains[41]->status, 'its customer\'s, still recoverable: never cancelled for another\'s order');
        $this->assertSame('recent.com', $domains[41]->live_fqdn);
        $this->assertNull($domains[42]->live_fqdn, 'the later order does not hold it (staff refund or credit it)');
    }

    /** A WHMCS database as ImportDomainsTest makes it: the two customers, .com at 30 + 30 days. */
    private function whmcs(): PDO
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT, prefix TEXT, suffix TEXT, format INT, rate REAL, "default" INT)',
            "INSERT INTO tblcurrencies VALUES (1, 'EUR', '', '€', 3, 1, 1)",
            'CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)',
            "INSERT INTO tblclients VALUES ({$this->alice->client()->id}, 1), ({$this->bob->client()->id}, 1)",
            'CREATE TABLE tblconfiguration (setting TEXT, value TEXT)',
            "INSERT INTO tblconfiguration VALUES ('DateFormat', 'DD/MM/YYYY')",
            'CREATE TABLE tbldomainpricing (id INTEGER PRIMARY KEY, extension TEXT, autoreg TEXT, eppcode INT, dnsmanagement INT, emailforwarding INT,
                idprotection INT, grace_period INT, redemption_grace_period INT, "order" INT)',
            "INSERT INTO tbldomainpricing VALUES (3, '.com', 'openprovider', 1, 1, 1, 1, 30, 30, 3)",
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
            'CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, status TEXT)',
            'CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, type TEXT, relid INT)',
        ] as $sql) {
            $whmcs->exec($sql);
        }

        return $whmcs;
    }
}
