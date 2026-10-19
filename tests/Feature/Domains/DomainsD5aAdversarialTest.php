<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainAdopter;
use App\Services\Domains\DomainLifecycle;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\Pricing\TldCostSync;
use App\Services\Domains\Pricing\TldImport;
use App\Services\Domains\Pricing\TldImportOptions;
use App\Support\Domains\TldPricing;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of D5a (grace and redemption from the registrar). Fixtures from DomainQuarantineTest.
 */
class DomainsD5aAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const PROD = 'https://api.openprovider.eu';

    private DomainRegistrarConnection $op;

    private DomainRegistrarConnection $manual;

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
        $this->manual = DomainRegistrarConnection::query()->create(['registrar' => 'manual', 'label' => 'CNIC', 'is_active' => true, 'mode' => 'production']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set('UTC');
        parent::tearDown();
    }

    /** @param  array<string, array{0: mixed, 1: mixed, 2: string, 3?: string}>  $tlds */
    private function fakeTlds(array $tlds): void
    {
        $q = fn (string $price) => ['reseller' => ['price' => $price, 'currency' => 'EUR']];
        $routes = [self::PROD . '/v1/auth/login' => Http::response(['data' => ['token' => 'tok']])];
        foreach ($tlds as $tld => $t) {
            $routes[self::PROD . '/v1/tlds/' . $tld . '*'] = Http::response(['data' => ['name' => $tld,
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
    private function record(array $record): array
    {
        return $record + ['id' => 30391502, 'domain' => ['name' => 'nixpal', 'extension' => 'com'], 'status' => 'ACT', 'expiration_date' => '2026-10-01 18:08:48',
            'name_servers' => [['name' => 'ns1.nixpal.com'], ['name' => 'ns2.nixpal.com']]];
    }

    /** @param  array<string, mixed>  $record */
    private function fakeDomain(array $record): void
    {
        $data = $this->record($record);
        Http::fake([
            self::PROD . '/v1/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::PROD . '/v1/domains?*' => Http::response(['data' => ['results' => [$data]]]),
            self::PROD . '/v1/domains/*' => Http::response(['data' => $data]),
            self::PROD . '/v1/customers/*' => Http::response([], 404),
        ]);
    }

    // ---------------------------------------------------------------- findings

    /**
     * F1: the registrar's stage dates are kept after the domain is renewed by anything but an OP «ACT without dates» sync
     * (staff edit, lifecycle «renewed», a registrar switch). A year later the stale dates, BEFORE the new expiry, are still
     * «the registrar's date» and skip the grace: on a manual registrar the domain is Deleted the day after it expires.
     */
    public function test_f1_stale_stage_dates_from_a_past_expiry_never_delete_a_renewed_domain(): void
    {
        $com = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 30, 'is_active' => true]);
        $domain = $this->domain(['domain_tld_id' => $com->id, 'registrar_connection_id' => $this->manual->id,
            'expires_at' => '2027-10-01', 'grace_ends_on' => '2026-11-10', 'redemption_ends_on' => '2026-12-08']);

        $this->assertSame(DomainStatus::Grace, app(DomainLifecycle::class)->next($domain, Carbon::parse('2027-10-02')),
            'expired yesterday with 40 grace days: grace, not deleted on last year\'s dates');
    }

    /**
     * F1c (the working tree's «registrar dates win over the expiry», review 1): a domain off OP (manual now: transferred
     * to CNIC, or restored and re-dated by staff) keeps its old OP dates, which nothing but an OP sync clears; it is
     * Deleted months BEFORE its expiry.
     */
    public function test_f1c_old_registrar_dates_never_delete_a_domain_before_its_expiry(): void
    {
        $com = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 30, 'is_active' => true]);
        $domain = $this->domain(['domain_tld_id' => $com->id, 'registrar_connection_id' => $this->manual->id,
            'expires_at' => '2027-10-01', 'grace_ends_on' => '2026-11-10', 'redemption_ends_on' => '2026-12-08']);

        $this->assertNull(app(DomainLifecycle::class)->next($domain, Carbon::parse('2027-03-01')), 'active, 7 months before its expiry');
    }

    /** F1b: the same through OP: renewed at OP while the record still carries last quarantine's dates. */
    public function test_f1b_a_renewal_whose_record_still_carries_quarantine_dates_does_not_skip_next_years_grace(): void
    {
        Carbon::setTestNow('2026-11-20 12:00:00');
        $com = DomainTld::query()->create(['tld' => 'com', 'registrar_connection_id' => $this->op->id, 'grace_days' => 40, 'redemption_days' => 30, 'is_active' => true]);
        $domain = $this->domain(['domain_tld_id' => $com->id, 'expires_at' => '2026-10-01', 'status' => DomainStatus::Redemption,
            'grace_ends_on' => '2026-11-10', 'redemption_ends_on' => '2026-12-08']);
        $this->fakeDomain(['status' => 'ACT', 'expiration_date' => '2027-10-01 18:08:48',
            'soft_quarantine_expiry_date' => '2026-11-10 12:00:00', 'hard_quarantine_expiry_date' => '2026-12-08 12:00:00']);

        app(DomainSyncService::class)->sync($domain);
        $domain->refresh();
        $this->assertSame(DomainStatus::Active, $domain->status);

        $this->assertSame(DomainStatus::Grace, app(DomainLifecycle::class)->next($domain, Carbon::parse('2027-10-02')),
            'next year\'s grace runs from next year\'s expiry');
    }

    /**
     * F2: a pending order (register/transfer) whose name has a DEL tombstone in quarantine in the account (the name's
     * past, e.g. it lapsed with us) is turned into Grace by the sync: the order never runs, a Grace domain without expiry.
     * Before D5a a tombstone «never ends a pending order» (DomainSyncService::plan); the guard checks only Deleted.
     */
    public function test_f2_a_quarantined_tombstone_never_turns_a_pending_order_into_grace(): void
    {
        Carbon::setTestNow('2026-10-17 12:00:00');
        $domain = $this->domain(['status' => DomainStatus::PendingRegister, 'expires_at' => null, 'registrar_domain_id' => null]);
        $this->fakeDomain(['status' => 'DEL', 'soft_quarantine_expiry_date' => '2026-11-10 12:00:00', 'hard_quarantine_expiry_date' => '2026-12-08 12:00:00']);

        app(DomainSyncService::class)->sync($domain);

        $this->assertSame(DomainStatus::PendingRegister, $domain->fresh()->status);
    }

    /**
     * F3: the sync decides the stage on Amsterdam's day, the lifecycle on the app's (Europe/Athens in production): for an
     * hour each night they disagree and the domain flips Grace <-> Redemption, audited both ways.
     */
    public function test_f3_sync_and_lifecycle_agree_on_the_last_day_of_grace(): void
    {
        date_default_timezone_set('Europe/Athens');
        Carbon::setTestNow(Carbon::parse('2026-11-11 00:30:00', 'Europe/Athens')); // 23:30 in Amsterdam, still 10 Nov
        $com = DomainTld::query()->create(['tld' => 'com', 'registrar_connection_id' => $this->op->id, 'grace_days' => 40, 'redemption_days' => 28, 'is_active' => true]);
        $domain = $this->domain(['domain_tld_id' => $com->id, 'expires_at' => '2026-10-01', 'status' => DomainStatus::Grace]);
        $this->fakeDomain(['status' => 'DEL', 'soft_quarantine_expiry_date' => '2026-11-10 12:00:00', 'hard_quarantine_expiry_date' => '2026-12-08 12:00:00']);

        app(DomainSyncService::class)->sync($domain);
        $domain->refresh();

        $this->assertNull(app(DomainLifecycle::class)->next($domain), "sync said {$domain->status->value}: the lifecycle must not move it the same minute");
    }

    /**
     * F4: a negative (garbled) quarantine period from OP becomes 0 = «no such stage» instead of «not reported»: the TLD's
     * 40 grace days are overwritten with 0 by Sync costs, and a manual-registrar domain of that TLD loses its grace.
     */
    public function test_f4_a_negative_quarantine_period_is_not_reported_never_no_stage(): void
    {
        $this->fakeTlds(['com' => [-1, -1, '42.16']]);
        $tld = DomainTld::query()->create(['tld' => 'com', 'registrar_connection_id' => $this->op->id, 'grace_days' => 40, 'redemption_days' => 30, 'is_active' => true]);
        DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR', 'price' => '12.00']);

        app(TldCostSync::class)->sync($tld);

        $this->assertSame([40, 30], [$tld->fresh()->grace_days, $tld->fresh()->redemption_days]);
    }

    /**
     * F5: a garbled (non-date) quarantine date on a DEL compares as a string «>= today» ('u' > '2'), so the tombstone is
     * read as in quarantine and the sync then fails writing it into a date column: a domain that is gone is never Deleted.
     */
    public function test_f5_a_garbled_quarantine_date_never_keeps_a_gone_domain_alive(): void
    {
        Carbon::setTestNow('2026-12-20 12:00:00');
        $domain = $this->domain(['expires_at' => '2026-10-01', 'status' => DomainStatus::Redemption]);
        $this->fakeDomain(['status' => 'DEL', 'soft_quarantine_expiry_date' => 'unknown', 'hard_quarantine_expiry_date' => '0000-00-00 00:00:00']);

        try {
            app(DomainSyncService::class)->sync($domain);
        } catch (Throwable $e) {
            $this->fail('the sync failed on a garbled date: ' . $e->getMessage());
        }

        $this->assertSame(DomainStatus::Deleted, $domain->fresh()->status);
    }

    /**
     * F6: the import from a connection writes that registrar's quarantine days onto a TLD routed to ANOTHER registrar
     * (auto-register off: only prices were wanted). OP's soft/hard quarantine are OP's; the lifecycle of the other
     * registrar's domains then runs on them (.eu 40/30 at CNIC becomes 0/38).
     */
    public function test_f6_the_import_never_writes_one_registrars_days_onto_a_tld_routed_to_another(): void
    {
        $eu = DomainTld::query()->create(['tld' => 'eu', 'registrar_connection_id' => $this->manual->id, 'grace_days' => 40, 'redemption_days' => 30, 'is_active' => true]);
        $pricing = new TldPricing(tld: 'eu', costs: ['register' => ['cost' => '5.00', 'currency' => 'EUR'], 'renew' => ['cost' => '5.00', 'currency' => 'EUR']],
            graceDays: 0, redemptionDays: 38);

        app(TldImport::class)->import($this->op, ['eu' => $pricing], new TldImportOptions(marginType: 'percent', margin: '10', autoRegister: false));

        $this->assertSame($this->manual->id, $eu->fresh()->registrar_connection_id);
        $this->assertSame([40, 30], [$eu->fresh()->grace_days, $eu->fresh()->redemption_days]);
    }

    // F7 (a domain the old sync deleted during its quarantine is not repaired): accepted, docs/domains.md 5a: none
    // exists, and Deleted is terminal (D-18); a restore of such a name goes through staff.

    // ---------------------------------------------------------------- holds

    public function test_holds_staff_ended_domains_are_not_revived_by_a_quarantined_del(): void
    {
        Carbon::setTestNow('2026-10-17 12:00:00');
        foreach ([DomainStatus::Cancelled, DomainStatus::TransferredAway] as $status) {
            Domain::query()->delete();
            $domain = $this->domain(['expires_at' => '2026-10-01', 'status' => $status]);
            $this->fakeDomain(['status' => 'DEL', 'soft_quarantine_expiry_date' => '2026-11-10 12:00:00', 'hard_quarantine_expiry_date' => '2026-12-08 12:00:00']);
            app(DomainSyncService::class)->sync($domain);
            $this->assertSame($status, $domain->fresh()->status);
            $this->assertNull(app(DomainLifecycle::class)->next($domain->fresh()));
        }
    }

    public function test_holds_the_adopter_never_takes_a_del_in_quarantine(): void
    {
        Carbon::setTestNow('2026-10-17 12:00:00');
        $this->fakeDomain(['status' => 'DEL', 'soft_quarantine_expiry_date' => '2026-11-10 12:00:00', 'hard_quarantine_expiry_date' => '2026-12-08 12:00:00']);

        $this->expectException(DisplayException::class);
        app(DomainAdopter::class)->adopt('nixpal.com', User::factory()->create()->client(), $this->op, 'EUR');
    }

    public function test_holds_a_del_past_its_quarantine_or_with_zero_dates_is_deleted(): void
    {
        Carbon::setTestNow('2026-10-17 12:00:00');
        $domain = $this->domain(['expires_at' => '2026-08-01', 'status' => DomainStatus::Redemption]);
        $this->fakeDomain(['status' => 'DEL', 'soft_quarantine_expiry_date' => '0000-00-00 00:00:00', 'hard_quarantine_expiry_date' => '2026-10-16 23:59:59']);

        app(DomainSyncService::class)->sync($domain);

        $this->assertSame(DomainStatus::Deleted, $domain->fresh()->status);
        $this->assertNull($domain->fresh()->live_fqdn);
    }

    public function test_holds_the_last_day_of_redemption_in_amsterdam_is_still_redemption(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-08 22:30:00', 'UTC')); // 23:30 Amsterdam
        $domain = $this->domain(['expires_at' => '2026-10-01', 'status' => DomainStatus::Grace]);
        $this->fakeDomain(['status' => 'DEL', 'soft_quarantine_expiry_date' => '2026-11-10 12:00:00', 'hard_quarantine_expiry_date' => '2026-12-08 12:00:00']);

        app(DomainSyncService::class)->sync($domain);

        $this->assertSame(DomainStatus::Redemption, $domain->fresh()->status);
    }

    public function test_holds_the_manual_lifecycle_never_deletes_past_what_is_known(): void
    {
        $lifecycle = app(DomainLifecycle::class);
        $cases = [
            'grace 0, redemption unknown' => [0, null, '2026-11-15', DomainStatus::Expired],
            'grace unknown, redemption 30' => [null, 30, '2026-11-15', DomainStatus::Expired],
            'grace 40, redemption unknown, past the grace' => [40, null, '2026-11-15', DomainStatus::Expired],
            'grace 0, redemption 38' => [0, 38, '2026-11-01', DomainStatus::Redemption],
        ];
        foreach ($cases as $label => [$grace, $redemption, $on, $expected]) {
            Domain::query()->delete();
            DomainTld::query()->delete();
            $tld = DomainTld::query()->create(['tld' => 'com', 'grace_days' => $grace, 'redemption_days' => $redemption, 'is_active' => true]);
            $domain = $this->domain(['domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->manual->id, 'expires_at' => '2026-10-01', 'status' => DomainStatus::Expired]);
            $this->assertSame($expected === DomainStatus::Expired ? null : $expected, $lifecycle->next($domain, Carbon::parse($on)), $label);
        }
    }

    public function test_holds_sync_costs_keeps_the_domain_override_and_skips_garbled_costs(): void
    {
        $this->fakeTlds(['com' => [40, 0, '-42.16']]);
        $tld = DomainTld::query()->create(['tld' => 'com', 'registrar_connection_id' => $this->op->id, 'grace_days' => 40, 'redemption_days' => 30, 'is_active' => true]);
        $grace = DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'grace', 'years' => 1, 'currency_code' => 'EUR', 'price' => '0.00', 'cost' => '1.00', 'cost_currency' => 'EUR']);
        DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR', 'price' => '12.00']);
        $domain = $this->domain(['domain_tld_id' => $tld->id, 'redemption_days_override' => 30]);

        app(TldCostSync::class)->sync($tld);

        $this->assertSame(30, $domain->fresh()->redemptionDays());
        $this->assertSame('1.00', number_format((float) $grace->fresh()->cost, 2, '.', ''));
    }

    public function test_holds_the_import_writes_no_stage_fee_unless_asked_and_keeps_a_free_grace(): void
    {
        $pricing = new TldPricing(tld: 'com', costs: ['register' => ['cost' => '11.22', 'currency' => 'EUR'], 'renew' => ['cost' => '9.80', 'currency' => 'EUR'],
            'grace' => ['cost' => '42.16', 'currency' => 'EUR']], graceDays: 40, redemptionDays: 0);

        app(TldImport::class)->import($this->op, ['com' => $pricing], new TldImportOptions(marginType: 'percent', margin: '10'));
        $tld = DomainTld::query()->where('tld', 'com')->firstOrFail();
        $this->assertFalse($tld->prices()->whereIn('operation', ['grace', 'redemption'])->exists());

        DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'grace', 'years' => 1, 'currency_code' => 'EUR', 'price' => '0.00']);
        app(TldImport::class)->import($this->op, ['com' => $pricing], new TldImportOptions(marginType: 'percent', margin: '10', redemptionFee: true));
        $row = $tld->prices()->where('operation', 'grace')->firstOrFail();
        $this->assertSame(['0.00', '42.16'], [number_format((float) $row->price, 2, '.', ''), number_format((float) $row->cost, 2, '.', '')]);
    }

    public function test_holds_the_page_shows_both_stage_ends_and_never_negative_days_on_the_last_day(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00');
        $tld = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 28, 'is_active' => true]);
        $domain = $this->domain(['domain_tld_id' => $tld->id, 'expires_at' => '2026-10-01', 'status' => DomainStatus::Grace]);
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['admin.domains.viewAny']])->id]);

        $this->livewireAs($staff)->test(ViewDomain::class, ['record' => $domain->getRouteKey()])
            ->assertSee('10 Nov 2026')->assertSee('8 Dec 2026')->assertDontSee('-1 day'); // its last day: 0 or 1 left, never negative
    }
}
