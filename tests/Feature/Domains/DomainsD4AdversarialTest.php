<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainLifecycle;
use App\Services\Domains\DomainSyncService;
use App\Services\Ops\Health;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * Adversarial review of domains D4 (#161): the registrar's truth, the lifecycle by dates, adopt, Sync now, health.
 * Each test named a_/an_ ... states what SHOULD hold; a failing one is a finding.
 */
class DomainsD4AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const PROD = 'https://api.openprovider.eu';

    private DomainRegistrarConnection $op;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-16 10:00:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Cache::flush();
        Http::preventStrayRequests();
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'nixpal', 'tld' => 'com', 'fqdn' => 'nixpal.com',
            'registrar_connection_id' => $this->op->id, 'expires_at' => '2026-11-17']);
    }

    private function tld(?int $grace, ?int $redemption): DomainTld
    {
        return DomainTld::query()->create(['tld' => 'com', 'grace_days' => $grace, 'redemption_days' => $redemption]);
    }

    /** @param  array<string, mixed>  $record */
    private function fakeRecord(array $record, ?array $byName = null): void
    {
        $record += ['id' => 30391502, 'status' => 'ACT', 'expiration_date' => '2027-11-17 00:00:00',
            'domain' => ['name' => 'nixpal', 'extension' => 'com'],
            'name_servers' => [['name' => 'ns1.nixpal.com'], ['name' => 'ns2.nixpal.com']], 'is_locked' => true];
        Http::fake([
            self::PROD . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::PROD . '/v1beta/domains?full_name=*' => Http::response(['data' => ['results' => $byName ?? [$record]]]),
            self::PROD . '/v1beta/domains/*' => Http::response(['data' => $record]),
        ]);
    }

    /** @return list<string> the status values the audit saw the domain move to */
    private function statusMoves(Domain $domain): array
    {
        return Audit::query()->where('auditable_type', $domain->getMorphClass())->where('auditable_id', $domain->id)->orderBy('id')->get()
            ->map(fn (Audit $a) => $a->new_values['status'] ?? null)->filter()->values()->all();
    }

    // ── the registrar overwriting ───────────────────────────────────────────────────────────────────────────────────

    /** F1: the record by name is another domain's: its id/expiry/NS must not land on ours. */
    public function test_another_domains_record_returned_by_name_is_not_written(): void
    {
        $domain = $this->domain();
        $domain->nameservers()->create(['host' => 'ns1.ours.gr', 'sort' => 0]);
        $other = ['id' => 999, 'status' => 'ACT', 'expiration_date' => '2030-01-01 00:00:00', 'domain' => ['name' => 'nixpal-other', 'extension' => 'com'],
            'name_servers' => [['name' => 'ns1.someone-else.net']]];
        $this->fakeRecord($other, [$other]);

        try {
            app(DomainSyncService::class)->sync($domain->load('nameservers'));
        } catch (\Throwable) {
            // refusing is fine
        }

        $domain->refresh();
        $this->assertNull($domain->registrar_domain_id, 'another domain\'s registrar id adopted (a later renew/restore would charge it)');
        $this->assertSame('2026-11-17', $domain->expires_at->toDateString());
        $this->assertSame(['ns1.ours.gr'], $domain->nameservers()->pluck('host')->all());
    }

    /** F2: one wrong/old expiry from the registrar takes an active domain to a terminal «deleted» in the same run. */
    public function test_a_backwards_expiry_does_not_end_an_active_domain_in_one_run(): void
    {
        $tld = $this->tld(40, 30);
        $domain = $this->domain(['domain_tld_id' => $tld->id, 'expires_at' => '2027-10-16']); // renewed here
        $this->fakeRecord(['expiration_date' => '1970-01-01 00:00:00']);

        $this->artisan('domains:sync');

        $this->assertFalse($domain->fresh()->status->isTerminal(), 'an active, renewed domain became ' . $domain->fresh()->status->value . ' from one registrar answer');
    }

    /** F3: D-9 «the registrar only moves the status forward»: ACT with the same lapsed expiry flips grace↔active every run. */
    public function test_registrar_act_after_expiry_does_not_flap_the_status(): void
    {
        $tld = $this->tld(40, 30);
        $domain = $this->domain(['domain_tld_id' => $tld->id, 'expires_at' => '2026-10-01', 'status' => DomainStatus::Grace]);
        $this->fakeRecord(['expiration_date' => '2026-10-01 00:00:00']);

        $this->assertArrayNotHasKey('status', app(DomainSyncService::class)->sync($domain->load('nameservers'), false), 'grace → active without the expiry moving');
        $this->artisan('domains:sync');
        $this->artisan('domains:sync');

        $this->assertSame(DomainStatus::Grace, $domain->fresh()->status);
        $this->assertSame([], $this->statusMoves($domain), 'status flapped: ' . implode(' → ', $this->statusMoves($domain)));
    }

    /** F4: a deleted row (registrar purged its id) is resurrected by the name's NEW registration (another id). */
    public function test_a_deleted_domain_is_not_resurrected_by_a_new_registration_of_its_name(): void
    {
        $old = $this->domain(['status' => DomainStatus::Deleted, 'registrar_domain_id' => '111', 'expires_at' => '2025-01-01']);
        Http::fake([
            self::PROD . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::PROD . '/v1beta/domains/111' => Http::response(['desc' => 'Domain not found'], 404),
            self::PROD . '/v1beta/domains?full_name=*' => Http::response(['data' => ['results' => [
                ['id' => 222, 'status' => 'ACT', 'expiration_date' => '2027-06-01 00:00:00', 'domain' => ['name' => 'nixpal', 'extension' => 'com']],
            ]]]),
        ]);

        $this->artisan('domains:sync');

        $old->refresh();
        $this->assertSame(DomainStatus::Deleted, $old->status, 'the old account\'s row is live (and billable) again');
        $this->assertSame('111', $old->registrar_domain_id);
    }

    /** F5: deleted (terminal) domains stay in the 4-hourly sync: once purged at the registrar the run fails forever. */
    public function test_a_long_deleted_domain_purged_at_the_registrar_does_not_fail_every_run(): void
    {
        $this->domain(['status' => DomainStatus::Deleted, 'expires_at' => '2024-01-01']);
        Http::fake([
            self::PROD . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::PROD . '/v1beta/domains?full_name=*' => Http::response(['data' => ['results' => []]]),
        ]);

        $this->artisan('domains:sync')->assertSuccessful();
    }

    // ── the lifecycle by dates ──────────────────────────────────────────────────────────────────────────────────────

    /** F6: grace unknown (null) + redemption known: day 1 after expiry is «redemption» (restore fee) instead of expired. */
    public function test_unknown_grace_does_not_put_a_domain_in_redemption_on_day_one(): void
    {
        $tld = $this->tld(null, 30);
        $domain = $this->domain(['domain_tld_id' => $tld->id, 'registrar_connection_id' => null, 'expires_at' => '2026-10-15']);

        $this->assertNotSame(DomainStatus::Redemption, app(DomainLifecycle::class)->next($domain, Carbon::parse('2026-10-16')));
    }

    /** F7: grace known + redemption unknown (null): the day after grace it is «deleted» (terminal) by our guess. */
    public function test_unknown_redemption_does_not_delete_a_domain_after_its_grace(): void
    {
        $tld = $this->tld(40, null);
        $domain = $this->domain(['domain_tld_id' => $tld->id, 'registrar_connection_id' => null, 'expires_at' => '2026-09-01']);

        $this->assertNotSame(DomainStatus::Deleted, app(DomainLifecycle::class)->next($domain, Carbon::parse('2026-10-16')));
    }

    /** F8: D-18: an API registrar's domain becomes deleted by the sync (its word), the dates only for registrars without one. */
    public function test_a_domain_at_an_api_registrar_is_not_deleted_by_dates(): void
    {
        $tld = $this->tld(40, 30);
        $domain = $this->domain(['domain_tld_id' => $tld->id, 'expires_at' => '2026-07-01', 'status' => DomainStatus::Redemption]);
        $this->fakeRecord(['status' => 'RRQ', 'expiration_date' => '2026-07-01 00:00:00']); // unmapped: «keep the local status»

        $this->artisan('domains:sync');

        $this->assertSame(DomainStatus::Redemption, $domain->fresh()->status, 'the registrar did not say deleted');
    }

    /** F9: a registrar read failure skips the dates too: the domain stays «active» long past its expiry. */
    public function test_a_failing_registrar_does_not_freeze_the_dates(): void
    {
        $tld = $this->tld(40, 30);
        $domain = $this->domain(['domain_tld_id' => $tld->id, 'expires_at' => '2026-10-01']);
        Http::fake([self::PROD . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::PROD . '/v1beta/domains*' => Http::response(['desc' => 'Server error'], 500)]);

        $this->artisan('domains:sync');

        $this->assertNotSame(DomainStatus::Active, $domain->fresh()->status, 'expired 15 days ago, still active');
    }

    /** Holds: expiry today is not lapsed; leap day; DST week. */
    public function test_day_boundaries_hold(): void
    {
        $tld = $this->tld(1, 1);
        $life = app(DomainLifecycle::class);
        $d = $this->domain(['domain_tld_id' => $tld->id, 'registrar_connection_id' => null, 'expires_at' => '2028-02-29']);
        $this->assertNull($life->next($d, Carbon::parse('2028-02-29')));
        $this->assertSame(DomainStatus::Grace, $life->next($d, Carbon::parse('2028-03-01')));
        $this->assertSame(DomainStatus::Redemption, $life->next($d, Carbon::parse('2028-03-02')));
        $d->update(['expires_at' => '2026-10-24']);
        Carbon::setTestNow(Carbon::parse('2026-10-26 00:00', 'Europe/Athens'));
        $this->assertSame(DomainStatus::Redemption, $life->next($d->fresh(), Carbon::parse('2026-10-26')));
    }

    // ── dry-run, audit, health ──────────────────────────────────────────────────────────────────────────────────────

    /** F10: the dry-run's lifecycle line is computed on the un-synced row: it reports a move that the real run won't do. */
    public function test_the_dry_run_reports_what_the_real_run_would_do(): void
    {
        $tld = $this->tld(40, 30);
        $this->domain(['domain_tld_id' => $tld->id, 'expires_at' => '2026-10-01']);
        $this->fakeRecord(['expiration_date' => '2027-10-01 00:00:00']); // renewed at the registrar

        $this->artisan('domains:sync', ['--dry-run' => true])
            ->expectsOutputToContain('expires_at 2026-10-01 → 2027-10-01')
            ->doesntExpectOutputToContain('status active → grace');
    }

    /** Holds: a failing dry-run writes nothing (no sync_error, no last_synced_at, no status). */
    public function test_a_failing_dry_run_writes_nothing(): void
    {
        $tld = $this->tld(40, 30);
        $domain = $this->domain(['domain_tld_id' => $tld->id, 'expires_at' => '2026-10-01']);
        Http::fake([self::PROD . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::PROD . '/v1beta/domains*' => Http::response(['desc' => 'Server error'], 500)]);
        $this->artisan('domains:sync', ['--dry-run' => true]);
        $domain->refresh();
        $this->assertSame([null, null, DomainStatus::Active], [$domain->sync_error, $domain->last_synced_at, $domain->status]);
    }

    /** F11: D-9 «every change is recorded»: a nameserver change from the registrar leaves no audit. */
    public function test_a_nameserver_change_from_the_registrar_is_audited(): void
    {
        $domain = $this->domain(['registrar_domain_id' => '30391502', 'transfer_lock' => true, 'expires_at' => '2027-11-17']);
        $domain->nameservers()->create(['host' => 'ns1.ours.gr', 'sort' => 0]);
        $this->fakeRecord([]);
        $before = Audit::query()->count();

        $this->artisan('domains:sync')->assertSuccessful();

        $this->assertSame(['ns1.nixpal.com', 'ns2.nixpal.com'], $domain->nameservers()->pluck('host')->all());
        $trail = Audit::query()->where('id', '>', $before)->get()->map(fn (Audit $a) => json_encode([$a->old_values, $a->new_values]))->implode(' ');
        $this->assertStringContainsString('ns1.ours.gr', $trail, 'the delegation changed with no trace of what it was');
    }

    /** F12: nothing changed at the registrar, yet every 4-hourly run writes an «updated» audit (last_synced_at). */
    public function test_a_sync_that_changes_nothing_writes_no_audit(): void
    {
        $domain = $this->domain();
        $this->fakeRecord([]);
        $this->artisan('domains:sync');
        $count = $domain->audits()->count();
        Carbon::setTestNow('2026-10-16 14:00:00');

        $this->artisan('domains:sync');

        $this->assertSame($count, $domain->audits()->count());
    }

    /** F13: «pending transfer for 14+ days» is measured on updated_at, which every sync touches: it never fires. */
    public function test_a_transfer_pending_for_20_days_is_reported(): void
    {
        $domain = $this->domain(['status' => DomainStatus::PendingTransfer]);
        Domain::query()->whereKey($domain->id)->update(['created_at' => now()->subDays(20), 'updated_at' => now()->subDays(20)]);
        $this->fakeRecord(['status' => 'REQ']);

        $this->artisan('domains:sync')->expectsOutputToContain('pending transfer for more than 14 days');

        $this->assertSame(1, Health::report()['checks']['domains']['data']['stale_transfers']);
    }

    // ── adopt ───────────────────────────────────────────────────────────────────────────────────────────────────────

    private function fakeByName(array $results): void
    {
        Http::fake([
            self::PROD . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::PROD . '/v1beta/domains?full_name=*' => Http::response(['data' => ['results' => $results]]),
        ]);
    }

    /** F14: the registrar holds only a tombstone (DEL) for the name: adopt «succeeds» with a deleted domain on the account. */
    public function test_adopt_refuses_a_tombstone(): void
    {
        $this->fakeByName([['id' => 5, 'status' => 'DEL', 'expiration_date' => '2025-01-01 00:00:00', 'domain' => ['name' => 'nixpal', 'extension' => 'com']]]);

        $this->artisan('domains:adopt', ['domain' => 'nixpal.com', '--client' => $this->user->client()->id, '--connection' => $this->op->id])->assertFailed();
        $this->assertSame(0, Domain::query()->count());
    }

    /** F15: a pending order at the registrar (REQ, no expiry given): adopted as «active» with no expiry. */
    public function test_adopt_does_not_create_an_active_domain_without_an_expiry(): void
    {
        $this->fakeByName([['id' => 6, 'status' => 'REQ', 'expiration_date' => '2027-10-16 00:00:00', 'domain' => ['name' => 'nixpal', 'extension' => 'com']]]);

        $this->artisan('domains:adopt', ['domain' => 'nixpal.com', '--client' => $this->user->client()->id, '--connection' => $this->op->id]);

        $this->assertSame(0, Domain::query()->where('status', DomainStatus::Active->value)->whereNull('expires_at')->count());
    }

    /** F16 (with F1): adopt takes another name's record as this one's. */
    public function test_adopt_refuses_another_domains_record(): void
    {
        $this->fakeByName([['id' => 7, 'status' => 'ACT', 'expiration_date' => '2027-01-01 00:00:00', 'domain' => ['name' => 'nixpal-shop', 'extension' => 'com']]]);

        $this->artisan('domains:adopt', ['domain' => 'nixpal.com', '--client' => $this->user->client()->id, '--connection' => $this->op->id])->assertFailed();
        $this->assertSame(0, Domain::query()->count());
    }

    /** Holds: manual / inactive connection, a name live on another account. */
    public function test_adopt_guards_hold(): void
    {
        $manual = DomainRegistrarConnection::query()->create(['registrar' => 'manual', 'label' => 'CNIC', 'is_active' => true, 'mode' => 'production']);
        $off = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP2', 'is_active' => false, 'mode' => 'production']);
        Http::fake();
        foreach ([$manual, $off] as $c) {
            $this->artisan('domains:adopt', ['domain' => 'nixpal.com', '--client' => $this->user->client()->id, '--connection' => $c->id])->assertFailed();
        }
        $this->domain(['client_id' => User::factory()->create()->client()->id]);
        $this->artisan('domains:adopt', ['domain' => 'NIXPAL.com', '--client' => $this->user->client()->id, '--connection' => $this->op->id])->assertFailed();
        $this->assertSame(1, Domain::query()->count());
        Http::assertNothingSent();
    }

    // ── Sync now ────────────────────────────────────────────────────────────────────────────────────────────────────

    /** Holds: staff without admin.domains.update cannot run the hidden action by a direct Livewire call. */
    public function test_sync_now_cannot_be_called_directly_without_the_permission(): void
    {
        $domain = $this->domain();
        Http::fake();
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['admin.domains.viewAny']])->id]);

        $page = $this->livewireAs($staff)->test(ViewDomain::class, ['record' => $domain->getRouteKey()]);
        try {
            $page->call('mountAction', 'sync')->call('callMountedAction');
        } catch (\Throwable) {
        }

        Http::assertNothingSent();
        $this->assertNull($domain->fresh()->last_synced_at);
    }
}
