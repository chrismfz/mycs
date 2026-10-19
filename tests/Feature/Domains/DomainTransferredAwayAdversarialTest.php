<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource;
use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Staff;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\DomainNotFoundAtRegistrar;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Services\Domains\Registrars\OpenproviderRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * Adversarial review of PR #203 (TransferredAway from the sync). Each test is a way a domain we still hold is ended
 * (auto-renew off, sync and billing stop: it may expire and be lost), or one that left is not handled for staff.
 */
class DomainTransferredAwayAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private FakeTransport $transport;

    private DomainRegistrarConnection $gr;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Cache::flush();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $this->gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], ['password']);
        $this->transport = new FakeTransport;
        GrEppRegistrar::$transport = fn () => $this->transport;
        GrEppRegistrar::reset();
    }

    protected function tearDown(): void
    {
        GrEppRegistrar::$transport = null;
        GrEppRegistrar::reset();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function answer(int $code): void
    {
        $this->transport->queue(new HttpResponse(200, '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
            . "<result code=\"{$code}\"><msg>m</msg></result><trID><clTRID>ECHO</clTRID><svTRID>s</svTRID></trID></response></epp>"));
    }

    private function liveFixture(string $name): void
    {
        $body = (string) file_get_contents(base_path("packages/gr-epp/tests/fixtures/live/{$name}"));
        $this->transport->queue(new HttpResponse(200, (string) preg_replace('#<clTRID>[^<]*</clTRID>#', '<clTRID>ECHO</clTRID>', $body)));
    }

    private function sync(Domain $domain, ?int $code = null, ?\Closure $then = null): ?\Throwable
    {
        GrEppRegistrar::reset();
        $this->transport = new FakeTransport;
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml');
        $code !== null ? $this->answer($code) : $then?->__invoke();
        try {
            app(DomainSyncService::class)->sync($domain->fresh());
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    private function domain(array $attributes = []): Domain
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['is_active' => true, 'registrar_connection_id' => $this->gr->id, 'grace_days' => 15, 'redemption_days' => 0]);

        return Domain::factory()->create($attributes + ['client_id' => User::factory()->create()->client()->id, 'sld' => 'example1', 'tld' => 'gr',
            'fqdn' => 'example1.gr', 'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->gr->id, 'status' => DomainStatus::Active,
            'expires_at' => '2028-08-16', 'currency_code' => 'EUR', 'years' => 2, 'auto_renew' => true]);
    }

    /**
     * ADV-1 (high). The registry reads a .gr name only for its sponsor: a domain:info that answers IS ours. Its clID is
     * compared with a registrar id guessed from the EPP username (trailing digits stripped; `registrar_id` has no field
     * in the admin form). An EPP user not of the form «<clID><digits>» (here «myip-ops») makes every .gr domain «managed
     * by another registrar» (elsewhere: true): before #203 a sync error, now each one is ended an hour later.
     */
    public function test_adv1_a_registrar_id_guessed_wrong_ends_every_gr_domain_we_hold(): void
    {
        $this->gr->storeCredentials(['username' => 'myip-ops', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], ['password']);
        $domain = $this->domain();

        $this->assertInstanceOf(DomainNotFoundAtRegistrar::class, $this->sync($domain, then: fn () => $this->liveFixture('domain-info-response.xml')));
        Carbon::setTestNow('2026-10-20 14:00:00'); // the next 4-hourly cron
        $this->sync($domain, then: fn () => $this->liveFixture('domain-info-response.xml'));

        $fresh = $domain->fresh();
        $this->assertSame([DomainStatus::Active, true], [$fresh->status, $fresh->auto_renew],
            'the registry answered domain:info with its full record (clID myip): the name is ours, the setting is wrong');
    }

    /**
     * ADV-2 (high). The connection holds credentials of another .gr account (another instance's, a test account): every
     * domain answers 2201. Two cron runs end the whole portfolio, auto-renew off. A whole connection «not ours» at once is
     * a configuration fact, not a hundred transfers.
     */
    public function test_adv2_credentials_of_another_account_end_the_whole_portfolio(): void
    {
        // The whole connection (12 domains: the breaker, more than half and at least 10, owner 2026-10-18).
        $domains = [];
        foreach (range(1, 12) as $i) {
            $domains[] = $this->domain(['sld' => "example{$i}", 'fqdn' => "example{$i}.gr"]);
        }
        foreach ($domains as $domain) {
            $this->sync($domain, 2201);
        }
        Carbon::setTestNow('2026-10-20 14:00:00');
        foreach ($domains as $domain) {
            $this->sync($domain, 2201);
        }

        $ended = collect($domains)->filter(fn (Domain $d) => $d->fresh()->status === DomainStatus::TransferredAway)->count();
        $this->assertSame(0, $ended, "{$ended} of 12 domains (every domain of the connection) ended by one wrong setting");
    }

    /**
     * ADV-3 (high). A connection switched to sandbox (to try something), or a TLD routed to another registrar/account
     * for new orders: domains it never held are «not in the account». Nothing ties the «not ours» to the connection that
     * last read the domain as ours.
     */
    public function test_adv3_a_tld_routed_to_another_connection_ends_the_domains_the_old_one_holds(): void
    {
        config(['domains.registrars.advheld' => AdvHeldRegistrar::class, 'domains.registrars.advaway' => AdvAwayRegistrar::class]);
        $old = DomainRegistrarConnection::query()->create(['registrar' => 'advheld', 'label' => 'Old', 'is_active' => true, 'mode' => 'production']);
        $new = DomainRegistrarConnection::query()->create(['registrar' => 'advaway', 'label' => 'New', 'is_active' => true, 'mode' => 'production']);
        $tld = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'registrar_connection_id' => $old->id]);
        $domain = $this->domain(['sld' => 'example5', 'tld' => 'com', 'fqdn' => 'example5.com', 'domain_tld_id' => $tld->id, 'registrar_connection_id' => null]);

        $this->assertNull($this->sync($domain), 'read ours through the old connection');
        $tld->update(['registrar_connection_id' => $new->id]); // new .com orders go elsewhere now

        $this->sync($domain);
        Carbon::setTestNow('2026-10-20 14:00:00');
        $this->sync($domain);
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status, 'a connection that never held it cannot say it left');
    }

    public function test_adv3b_a_sandbox_connections_not_found_ends_production_domains(): void
    {
        config(['domains.registrars.advaway' => AdvAwayRegistrar::class]);
        $sandbox = DomainRegistrarConnection::query()->create(['registrar' => 'advaway', 'label' => 'OP', 'is_active' => true, 'mode' => 'sandbox']);
        $domain = $this->domain(['sld' => 'example6', 'tld' => 'com', 'fqdn' => 'example6.com', 'domain_tld_id' => null, 'registrar_connection_id' => $sandbox->id]);

        $this->sync($domain);
        Carbon::setTestNow('2026-10-20 14:00:00');
        $this->sync($domain);
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status, "a sandbox account's «not here» says nothing about the live registry");
    }

    /**
     * ADV-4 (medium). Openprovider answering 200 with a body that is not its search (a maintenance page, a proxy, a
     * changed API shape): `data.results` is not an array, the candidates are [], and «could not read» is thrown as
     * «not in the account». Twice an hour apart ends the domain.
     */
    public function test_adv4_an_unreadable_openprovider_answer_is_taken_as_not_in_the_account(): void
    {
        Http::preventStrayRequests();
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        Http::fake([
            'https://api.openprovider.eu/v1/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            'https://api.openprovider.eu/v1/domains?full_name=*' => Http::response('<html>Scheduled maintenance</html>', 200),
        ]);
        $domain = $this->domain(['sld' => 'example7', 'tld' => 'com', 'fqdn' => 'example7.com', 'domain_tld_id' => null, 'registrar_connection_id' => $op->id]);

        $first = $this->sync($domain);
        Carbon::setTestNow('2026-10-20 14:00:00');
        $this->sync($domain);

        $this->assertNotInstanceOf(DomainNotFoundAtRegistrar::class, $first, 'an answer that is not a search result is «could not read»');
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);
    }

    /**
     * ADV-5 (medium). Marked away with an open renewal invoice the customer can still pay (and a stored card may be
     * charged for): the only trace is a collapsed section on the domain's page. No to-do, no Pending Module Action, no
     * mail: staff never learn of it (D-20).
     */
    public function test_adv5_staff_are_told_when_a_domain_with_an_open_invoice_is_taken_as_away(): void
    {
        $domain = $this->domain();
        $invoice = Invoice::factory()->create(['client_id' => $domain->client_id, 'currency_code' => 'EUR']);
        InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => '19.00', 'description' => 'example1.gr',
            'reference_type' => Domain::class, 'reference_id' => $domain->id]);
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_UNPAID]);

        $this->sync($domain, 2201);

        // Option A (owner 2026-10-18): the sync only notes it; staff are told (the Domains badge, the filter, its page).
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status, 'never ended by the sync');
        $this->assertSame('1', DomainResource::getNavigationBadge());
        app(DomainManagementService::class)->markTransferredAway($domain->fresh(), Staff::factory()->create());
        $this->assertSame([$invoice->id], $domain->fresh()->meta['transferred_away']['open_invoices'] ?? null, 'its open invoice noted');
    }

    /**
     * ADV-6 (low). The note has no age limit: a single «not ours» weeks ago (a moment's failure) and a single one now
     * end the domain at once, with no confirmation an hour later. Syncs that failed otherwise in between (transport,
     * the connection off) never clear it.
     */
    public function test_adv6_a_note_weeks_old_and_one_answer_now_end_it(): void
    {
        $domain = $this->domain();
        $this->sync($domain, 2201);
        $this->assertNotNull($domain->fresh()->meta['not_ours']['since'] ?? null);

        Carbon::setTestNow('2026-11-20 10:00:00'); // a month of other failures later
        $this->sync($domain, 2201);
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status, 'one answer now, against a note a month old');
    }

    /** ADV-7 (low). A draft renewal invoice (Invoice::OPEN_STATUSES) is not noted for staff: only «unpaid» is looked at. */
    public function test_adv7_a_draft_invoice_of_the_domain_is_noted_too(): void
    {
        $domain = $this->domain();
        $invoice = Invoice::factory()->create(['client_id' => $domain->client_id, 'currency_code' => 'EUR']);
        InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => '19.00', 'description' => 'example1.gr',
            'reference_type' => Domain::class, 'reference_id' => $domain->id]);
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_DRAFT]);

        $this->sync($domain, 2201);
        app(DomainManagementService::class)->markTransferredAway($domain->fresh(), Staff::factory()->create());

        $this->assertSame([$invoice->id], $domain->fresh()->meta['transferred_away']['open_invoices'] ?? null);
    }

    /**
     * ADV-8 (low). A name past its grace and redemption that the account no longer lists was deleted (Openprovider
     * purges it), not transferred: it is recorded as TransferredAway, the wrong end (D-18: «deleted» by the sync).
     */
    public function test_adv8_a_lapsed_name_long_past_its_redemption_is_deleted_not_transferred(): void
    {
        config(['domains.registrars.advaway' => AdvAwayRegistrar::class]);
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'advaway', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $domain = $this->domain(['sld' => 'example8', 'tld' => 'com', 'fqdn' => 'example8.com', 'domain_tld_id' => null, 'registrar_connection_id' => $op->id,
            'status' => DomainStatus::Redemption, 'expires_at' => '2026-07-01', 'auto_renew' => false]);

        $this->sync($domain);
        Carbon::setTestNow('2026-10-20 14:00:00');
        $this->sync($domain);
        $this->assertNotSame(DomainStatus::TransferredAway, $domain->fresh()->status);
    }

    /** Regression (passes): once staff mark it away, its sync and its renewal billing stop (even within its renewal window). */
    public function test_regression_away_stops_sync_and_billing(): void
    {
        $domain = $this->domain();
        $this->sync($domain, 2201);
        app(DomainManagementService::class)->markTransferredAway($domain->fresh(), Staff::factory()->create());
        $fresh = $domain->fresh();

        $this->assertSame([DomainStatus::TransferredAway, false], [$fresh->status, $fresh->auto_renew]);
        $this->assertFalse(app(DomainSyncService::class)->isSyncable($fresh));
        $this->assertSame(0, app(RenewalBilling::class)->billDomainRenewal($fresh->id, Carbon::parse('2029-01-01')));
    }
}

/** A registrar that holds the domain. */
class AdvHeldRegistrar extends OpenproviderRegistrar
{
    public function syncDomain(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        return new DomainSyncResult(expiresAt: '2028-08-16', status: DomainStatus::Active, rawStatus: 'ACT');
    }
}

/** A registrar whose account does not list the domain. */
class AdvAwayRegistrar extends OpenproviderRegistrar
{
    public function syncDomain(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        throw new DomainNotFoundAtRegistrar("{$domain->fqdn} is not in the Openprovider account.");
    }
}
