<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\DomainAdopter;
use App\Services\Domains\DomainRenewalYears;
use App\Services\Domains\DomainSearch;
use App\Services\Domains\Lookup\DomainLookup;
use App\Services\Domains\Ordering\DomainOffer;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\DomainRegistrarCapabilities;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial sweep of the domains area (2026-11-07): each test fails on main for the reason in its name.
 */
class SweepDomainsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 10:00:00');
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        config(['domains.registrars.sweepfakegr' => SweepFakeGrRegistrar::class, 'settings.domain_billing_enabled' => true,
            'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false, 'settings.domain_client_years' => true]);
        SweepFakeGrRegistrar::$record = null;
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR']);
        session(['currency' => 'EUR']);
        $this->user = User::factory()->create();
        // RDAP answers «free» for any name: what a TLD without a usable connection should fall back to.
        $this->app->instance(DomainLookup::class, new class extends DomainLookup
        {
            public function __construct() {}

            public function availableByRdap(string $fqdn): ?bool
            {
                return true;
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, array<int, string>>  $prices */
    private function tld(string $name, ?DomainRegistrarConnection $connection, array $prices): DomainTld
    {
        $tld = DomainTld::query()->create(['tld' => $name, 'is_active' => true, 'sort' => 1, 'registrar_connection_id' => $connection?->id,
            'grace_days' => 15, 'redemption_days' => 0]);
        foreach ($prices as $operation => $byYears) {
            foreach ($byYears as $years => $price) {
                DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
            }
        }

        return $tld;
    }

    // DOMAINS-1 (a): a TLD on an active manual connection is sold, but its names are asked of the manual «registrar»,
    // which cannot check: always «could not check», never orderable (RDAP would have answered).
    public function test_domains_1_a_tld_on_a_manual_connection_is_checked_by_rdap_not_refused_as_unknown(): void
    {
        $manual = DomainRegistrarConnection::query()->create(['registrar' => 'manual', 'label' => 'By hand', 'is_active' => true, 'mode' => 'production']);
        $tld = $this->tld('xyz', $manual, ['register' => [1 => '12.00'], 'renew' => [1 => '12.00']]);
        $this->assertContains('xyz', app(DomainSearch::class)->sold('EUR')->pluck('tld')->all(), 'sold: staff register it by hand');

        $this->assertSame(DomainSearch::FREE, app(DomainSearch::class)->statuses(['foo-sweep.xyz' => $tld->fresh()])['foo-sweep.xyz'],
            'a manual connection has no API: RDAP must answer, not the Null registrar');

        Cache::flush();
        try {
            app(DomainOffer::class)->check('foo-sweep.xyz', 'register', 1, 'EUR');
        } catch (DisplayException $e) {
            $this->fail('refused: ' . $e->getMessage());
        }
    }

    // DOMAINS-1 (b): a connection staff set to mode «off» (still active) is asked anyway, with sandbox credentials.
    public function test_domains_1_a_connection_set_off_is_never_asked_for_availability(): void
    {
        $asked = [];
        Http::fake(function (Request $request) use (&$asked) {
            $asked[] = $request->url();

            return Http::response(['data' => ['token' => 'tok', 'results' => []]]);
        });
        $off = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'off']);
        $off->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->assertFalse($off->isUsable());
        $tld = $this->tld('xyz', $off, ['register' => [1 => '12.00'], 'renew' => [1 => '12.00']]);

        app(DomainSearch::class)->statuses(['foo-sweep.xyz' => $tld->fresh()]);

        $this->assertSame([], $asked, 'a connection that is off (sandbox credentials) must not answer the live shop');
    }

    // DOMAINS-2: .gr renews in steps of two, but a 1-year .gr price makes the shop sell it for one year.
    public function test_domains_2_the_shop_never_sells_gr_for_an_odd_period(): void
    {
        $gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $gr->storeCredentials(['username' => 'u1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], ['password']);
        $tld = $this->tld('gr', $gr, ['register' => [1 => '12.00', 2 => '24.00'], 'renew' => [1 => '12.00', 2 => '24.00']]);

        $this->assertNotContains(1, app(DomainOffer::class)->years($tld, 'register', 'EUR')->all(),
            'the .gr registry refuses odd periods (Rules::years): never offered');
        $this->assertSame(2, $tld->shortestYears('register', 'EUR'), 'the search shows .gr for two years');

        $refused = false;
        try {
            app(DomainOffer::class)->check('sweep-example.gr', 'register', 1, 'EUR', availability: false);
        } catch (DisplayException) {
            $refused = true;
        }
        $this->assertTrue($refused, 'a 1-year .gr registration is never placed and paid for');
    }

    // DOMAINS-3: the client switches a .gr domain to an odd period that is then never billed.
    public function test_domains_3_a_client_cannot_switch_a_gr_domain_to_an_odd_period(): void
    {
        $gr = DomainRegistrarConnection::query()->create(['registrar' => 'sweepfakegr', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $tld = $this->tld('gr', $gr, ['renew' => [1 => '15.00', 2 => '30.00']]);
        $domain = Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => 'sweep-example', 'tld' => 'gr', 'fqdn' => 'sweep-example.gr',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $gr->id, 'status' => DomainStatus::Active, 'expires_at' => '2026-11-20',
            'currency_code' => 'EUR', 'years' => 2, 'auto_renew' => true, 'recurring_amount' => null]);
        $this->assertSame(2, $domain->renewYearStep());

        $this->assertNotContains(1, DomainRenewalYears::options($domain), 'its registrar renews in steps of two years');

        try {
            app(DomainRenewalYears::class)->change($domain, 1, byStaff: false);
        } catch (DisplayException) {
            // refused: what it should do
        }
        $domain->refresh();
        $billing = app(RenewalBilling::class);
        $billed = DB::transaction(fn () => $billing->billDomainRenewal($domain->id, Carbon::parse('2027-01-01')));
        $this->assertSame(1, $billed, 'its renewal is still billed: ' . implode(' | ', array_map(fn ($e) => $e->getMessage(), $billing->notices)));
    }

    // DOMAINS-5: an adopted .gr name keeps the column's 1 year, a period its registrar never renews: never billed.
    public function test_domains_5_an_adopted_gr_domain_is_billed_for_its_renewal(): void
    {
        $gr = DomainRegistrarConnection::query()->create(['registrar' => 'sweepfakegr', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $this->tld('gr', $gr, ['renew' => [2 => '30.00', 4 => '60.00']]);
        SweepFakeGrRegistrar::$record = new DomainSyncResult(expiresAt: '2026-11-20', status: DomainStatus::Active, rawStatus: 'ok');

        $domain = app(DomainAdopter::class)->adopt('sweep-example.gr', $this->user->client(), $gr, 'EUR');
        $domain->forceFill(['auto_renew' => true])->save();

        $billing = app(RenewalBilling::class);
        $billed = DB::transaction(fn () => $billing->billDomainRenewal($domain->id, Carbon::parse('2027-01-01')));
        $this->assertSame(1, $billed, "adopted with years={$domain->years}: " . implode(' | ', array_map(fn ($e) => $e->getMessage(), $billing->notices)));
    }
}

/** The .gr registry in memory: renews in steps of two years. */
class SweepFakeGrRegistrar extends GrEppRegistrar
{
    public static ?DomainSyncResult $record = null;

    public function key(): string
    {
        return 'sweepfakegr';
    }

    public function capabilities(): DomainRegistrarCapabilities
    {
        return new DomainRegistrarCapabilities(renewsInGrace: true, renewYearStep: 2);
    }

    public function syncDomain(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        return self::$record ?? new DomainSyncResult(expiresAt: $domain->expires_at?->toDateString(), status: DomainStatus::Active, rawStatus: 'ok');
    }
}
