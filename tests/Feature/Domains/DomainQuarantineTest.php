<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainLifecycle;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\Pricing\TldCostSync;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Domains D5a (docs/domains.md): grace and redemption from the registrar. Per TLD, Openprovider's soft quarantine (our
 * grace) and hard quarantine (our redemption) days, and the restore fee on the stage that has one; per domain, the end
 * dates while it is in them. An expired Openprovider domain is DEL during its quarantine: grace or redemption, never
 * deleted. Fixtures as the live answers looked (2026-10-17).
 */
class DomainQuarantineTest extends TestCase
{
    use RefreshDatabase;

    private const PROD = 'https://api.openprovider.eu';

    private DomainRegistrarConnection $op;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Cache::flush();
        Http::preventStrayRequests();
        config(['audit.console' => true]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
    }

    /** @param  array<string, array{0: int, 1: int, 2: string, 3?: string}>  $tlds  tld => [soft days, hard days, restore, soft restore] */
    private function fakeTlds(array $tlds): void
    {
        $q = fn (string $price) => ['reseller' => ['price' => $price, 'currency' => 'EUR']];
        $routes = [self::PROD . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']])];
        foreach ($tlds as $tld => $t) {
            $routes[self::PROD . '/v1beta/tlds/' . $tld . '*'] = Http::response(['data' => ['name' => $tld,
                'soft_quarantine_period' => $t[0], 'quarantine_period' => $t[1],
                'prices' => ['create_price' => $q('11.22'), 'renew_price' => $q('9.80'), 'transfer_price' => $q('9.80'),
                    'restore_price' => $q($t[2]), 'soft_restore_price' => $q($t[3] ?? '0')]]]);
        }
        Http::fake($routes);
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => User::factory()->create()->client()->id, 'sld' => 'nixpal', 'tld' => 'com',
            'fqdn' => 'nixpal.com', 'registrar_connection_id' => $this->op->id, 'registrar_domain_id' => '30391502', 'status' => DomainStatus::Active]);
    }

    /** @param  array<string, mixed>  $record */
    private function fakeDomain(array $record): void
    {
        $record += ['id' => 30391502, 'domain' => ['name' => 'nixpal', 'extension' => 'com'], 'status' => 'ACT', 'expiration_date' => '2026-10-01 18:08:48',
            'name_servers' => [['name' => 'ns1.nixpal.com'], ['name' => 'ns2.nixpal.com']]];
        Http::fake([
            self::PROD . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::PROD . '/v1beta/domains/*' => Http::response(['data' => $record]),
            self::PROD . '/v1beta/customers/*' => Http::response([], 404),
        ]);
    }

    public function test_the_restore_fee_goes_on_the_stage_that_has_one(): void
    {
        $this->fakeTlds(['com' => [40, 0, '42.16'], 'io' => [44, 28, '65.58', '0'], 'eu' => [0, 38, '15.00']]);
        $adapter = app(DomainRegistrarFactory::class)->for($this->op);
        $credentials = app(DomainRegistrarFactory::class)->credentialsFor($this->op);

        $com = $adapter->getTldPricing('com', $credentials);
        $io = $adapter->getTldPricing('io', $credentials);
        $eu = $adapter->getTldPricing('eu', $credentials);

        $this->assertSame([40, 0, '42.16', null], [$com->graceDays, $com->redemptionDays, $com->costs['grace']['cost'] ?? null, $com->costs['redemption'] ?? null]);
        $this->assertSame([44, 28, '0.00', '65.58'], [$io->graceDays, $io->redemptionDays, $io->costs['grace']['cost'] ?? null, $io->costs['redemption']['cost'] ?? null]);
        $this->assertSame([0, 38, null, '15.00'], [$eu->graceDays, $eu->redemptionDays, $eu->costs['grace'] ?? null, $eu->costs['redemption']['cost'] ?? null]);
    }

    public function test_sync_costs_brings_the_days_and_the_stage_costs(): void
    {
        $this->fakeTlds(['com' => [40, 0, '42.16']]);
        $tld = DomainTld::query()->create(['tld' => 'com', 'registrar_connection_id' => $this->op->id, 'grace_days' => 40, 'redemption_days' => 30, 'is_active' => true]);
        $grace = DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'grace', 'years' => 1, 'currency_code' => 'EUR', 'price' => '0.00']);

        app(TldCostSync::class)->sync($tld);

        $this->assertSame([40, 0], [$tld->fresh()->grace_days, $tld->fresh()->redemption_days], 'the registrar says .com has no redemption');
        $this->assertSame(['42.16', '0.00'], [number_format((float) $grace->fresh()->cost, 2, '.', ''), number_format((float) $grace->fresh()->price, 2, '.', '')], 'the cost moves, never the price');
        $this->assertTrue($tld->audits()->where('event', 'updated')->exists());
    }

    public function test_an_expired_domain_in_quarantine_is_grace_or_redemption_never_deleted(): void
    {
        Carbon::setTestNow('2026-10-17 12:00:00');
        $domain = $this->domain(['expires_at' => '2026-10-01']);
        $this->fakeDomain(['status' => 'DEL', 'soft_quarantine_expiry_date' => '2026-11-10 12:00:00', 'hard_quarantine_expiry_date' => '2026-12-08 12:00:00']);

        app(DomainSyncService::class)->sync($domain);

        $domain->refresh();
        $this->assertSame([DomainStatus::Grace, '2026-11-10', '2026-12-08'], [$domain->status, $domain->grace_ends_on->toDateString(), $domain->redemption_ends_on->toDateString()]);
        $this->assertNotNull($domain->live_fqdn, 'still the live row of its name');

        Carbon::setTestNow('2026-11-20 12:00:00');
        app(DomainSyncService::class)->sync($domain);
        $this->assertSame(DomainStatus::Redemption, $domain->fresh()->status);

        Carbon::setTestNow('2026-12-20 12:00:00');
        app(DomainSyncService::class)->sync($domain->fresh());
        $this->assertSame(DomainStatus::Deleted, $domain->fresh()->status, 'past both: the registrar has deleted it');
        Carbon::setTestNow();
    }

    public function test_a_renewal_clears_the_stage_dates(): void
    {
        $domain = $this->domain(['expires_at' => '2026-10-01', 'status' => DomainStatus::Grace, 'grace_ends_on' => '2026-11-10', 'redemption_ends_on' => '2026-12-08']);
        $this->fakeDomain(['status' => 'ACT', 'expiration_date' => '2027-10-01 18:08:48']);

        app(DomainSyncService::class)->sync($domain);

        $domain->refresh();
        $this->assertSame([DomainStatus::Active, null, null], [$domain->status, $domain->grace_ends_on, $domain->redemption_ends_on]);
    }

    public function test_the_lifecycle_follows_the_stage_dates_and_a_stage_of_zero_days_does_not_exist(): void
    {
        $com = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        $manual = DomainRegistrarConnection::query()->create(['registrar' => 'manual', 'label' => 'CNIC', 'is_active' => true, 'mode' => 'production']);
        $domain = $this->domain(['domain_tld_id' => $com->id, 'registrar_connection_id' => $manual->id, 'expires_at' => '2026-10-01']);
        $lifecycle = app(DomainLifecycle::class);

        $this->assertSame(DomainStatus::Grace, $lifecycle->next($domain, Carbon::parse('2026-11-10')), 'the 40th day is still grace');
        $this->assertSame(DomainStatus::Deleted, $lifecycle->next($domain, Carbon::parse('2026-11-11')), '.com: no redemption, gone after its grace');

        $io = DomainTld::query()->create(['tld' => 'io', 'grace_days' => 44, 'redemption_days' => 28, 'is_active' => true]);
        $other = $this->domain(['sld' => 'x', 'tld' => 'io', 'fqdn' => 'x.io', 'domain_tld_id' => $io->id, 'registrar_connection_id' => $manual->id, 'expires_at' => '2026-10-01']);
        $this->assertSame(DomainStatus::Redemption, $lifecycle->next($other, Carbon::parse('2026-11-20')));
        $other->forceFill(['grace_ends_on' => '2026-11-25'])->save(); // the registrar's own date wins
        $this->assertSame(DomainStatus::Grace, $lifecycle->next($other->fresh(), Carbon::parse('2026-11-20')));
    }

    public function test_the_domain_page_shows_the_days_left(): void
    {
        Carbon::setTestNow('2026-10-17 12:00:00');
        $tld = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        $domain = $this->domain(['domain_tld_id' => $tld->id, 'expires_at' => '2026-10-01', 'status' => DomainStatus::Grace]);
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['admin.domains.viewAny']])->id]);

        $this->livewireAs($staff)->test(ViewDomain::class, ['record' => $domain->getRouteKey()])
            ->assertSee('10 Nov 2026')->assertSee('24 days left');
        Carbon::setTestNow();
    }
}
