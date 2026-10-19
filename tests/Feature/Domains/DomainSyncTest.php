<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource\Pages\ListDomains;
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
use Tests\TestCase;

/**
 * Domains D4 (docs/domains.md D-8, D-9): the registrar's truth onto a domain, the diff report, a domain's life by its
 * dates, a domain adopted from the registrar, the health check, «Sync now».
 */
class DomainSyncTest extends TestCase
{
    use RefreshDatabase;

    private const PROD = 'https://api.openprovider.eu';

    private DomainRegistrarConnection $op;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Cache::flush();
        Http::preventStrayRequests();
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->user = User::factory()->create();
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'nixpal', 'tld' => 'com', 'fqdn' => 'nixpal.com',
            'registrar_connection_id' => $this->op->id, 'expires_at' => '2026-11-17']);
    }

    /** @param  array<string, mixed>  $record */
    private function fakeRecord(array $record): void
    {
        $record += ['domain' => ['name' => 'nixpal', 'extension' => 'com'], 'id' => 30391502, 'status' => 'ACT', 'expiration_date' => '2027-11-17 00:00:00',
            'name_servers' => [['name' => 'ns1.nixpal.com'], ['name' => 'ns2.nixpal.com']], 'is_locked' => true];
        Http::fake([
            self::PROD . '/v1/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::PROD . '/v1/domains?full_name=*' => Http::response(['data' => ['results' => [$record]]]),
            self::PROD . '/v1/domains/*' => Http::response(['data' => $record]),
        ]);
    }

    public function test_the_registrars_truth_lands_and_the_dry_run_writes_nothing(): void
    {
        $domain = $this->domain();
        $this->fakeRecord([]);

        $this->artisan('domains:sync', ['--dry-run' => true])
            ->expectsOutputToContain('nixpal.com: expires_at 2026-11-17 → 2027-11-17')
            ->expectsOutputToContain('nameservers')->assertSuccessful();
        $this->assertSame(['2026-11-17', null, 0], [$domain->fresh()->expires_at->toDateString(), $domain->fresh()->registrar_domain_id, $domain->nameservers()->count()]);

        $this->artisan('domains:sync')->assertSuccessful();
        $domain->refresh();
        $this->assertSame(['2027-11-17', '30391502', true, 'ACT'], [$domain->expires_at->toDateString(), $domain->registrar_domain_id, $domain->transfer_lock, $domain->meta['registrar_status']]);
        $this->assertSame(['ns1.nixpal.com', 'ns2.nixpal.com'], $domain->nameservers()->pluck('host')->all());
        $this->assertNotNull($domain->last_synced_at);
        $this->assertTrue($domain->audits()->where('event', 'updated')->exists(), 'every change audited');
        $this->assertSame([], app(DomainSyncService::class)->sync($domain->fresh()->load('nameservers'), false), 'a second run: nothing');
    }

    public function test_a_pending_transfer_takes_no_expiry_and_staffs_ends_are_never_touched(): void
    {
        $pending = $this->domain(['status' => DomainStatus::PendingTransfer]);
        $this->fakeRecord(['status' => 'REQ', 'expiration_date' => '2027-10-16 00:00:00']);
        app(DomainSyncService::class)->sync($pending);
        $this->assertSame(['2026-11-17', DomainStatus::PendingTransfer], [$pending->fresh()->expires_at->toDateString(), $pending->fresh()->status]);

        $pending->update(['status' => DomainStatus::TransferredAway]);
        $this->assertFalse(app(DomainSyncService::class)->isSyncable($pending->fresh()));
        $manual = DomainRegistrarConnection::query()->create(['registrar' => 'manual', 'label' => 'CNIC', 'is_active' => true, 'mode' => 'production']);
        $this->assertFalse(app(DomainSyncService::class)->isSyncable(Domain::factory()->create(['client_id' => $this->user->client()->id, 'registrar_connection_id' => $manual->id])));
    }

    public function test_a_failure_is_written_on_the_domain_and_does_not_stop_the_rest(): void
    {
        $domain = $this->domain();
        Http::fake([self::PROD . '/v1/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::PROD . '/v1/domains*' => Http::response(['desc' => 'Server error'], 500)]);

        $this->artisan('domains:sync')->assertFailed();

        $this->assertStringContainsString('Server error', (string) $domain->fresh()->sync_error);
        $this->assertSame(Health::WARN, Health::report()['checks']['domains']['status']);
    }

    public function test_a_lapsed_domain_moves_by_its_dates_and_comes_back_when_renewed(): void
    {
        $tld = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 30]);
        $domain = $this->domain(['domain_tld_id' => $tld->id, 'registrar_connection_id' => null, 'expires_at' => '2026-10-01']);
        $life = app(DomainLifecycle::class);
        $at = fn (string $day) => $life->next($domain->fresh(), Carbon::parse($day));

        $this->assertNull($at('2026-10-01'), 'not yet');
        $this->assertSame(DomainStatus::Grace, $at('2026-10-02'));
        $this->assertSame(DomainStatus::Grace, $at('2026-11-10'));
        $this->assertSame(DomainStatus::Redemption, $at('2026-11-11'));
        $this->assertSame(DomainStatus::Deleted, $at('2026-12-11'));

        $life->advance($domain, Carbon::parse('2026-11-11'));
        $domain->update(['expires_at' => '2027-10-01']); // restored and renewed
        $this->assertSame(DomainStatus::Active, $life->advance($domain->fresh(), Carbon::parse('2026-11-12')));

        $unknown = Domain::factory()->create(['client_id' => $this->user->client()->id, 'expires_at' => '2026-01-01']);
        $this->assertSame(DomainStatus::Expired, $life->next($unknown, Carbon::parse('2026-10-16')), 'no days known: only expired');
        $pending = Domain::factory()->create(['client_id' => $this->user->client()->id, 'expires_at' => '2026-01-01', 'status' => DomainStatus::PendingRegister]);
        $this->assertNull($life->next($pending, Carbon::parse('2026-10-16')));
    }

    public function test_a_domain_is_adopted_from_the_registrar_or_not_at_all(): void
    {
        $record = ['domain' => ['name' => 'nixpal', 'extension' => 'com'], 'id' => 30391502, 'status' => 'ACT', 'expiration_date' => '2027-11-17 00:00:00', 'name_servers' => [['name' => 'ns1.nixpal.com']]];
        Http::fake([
            self::PROD . '/v1/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::PROD . '/v1/domains?full_name=*' => fn ($request) => Http::response(['data' => ['results' => str_contains($request->url(), 'nixpal.com') ? [$record] : []]]),
        ]);
        $client = $this->user->client();

        $this->artisan('domains:adopt', ['domain' => 'NixPal.com.', '--client' => $client->id, '--connection' => $this->op->id])
            ->expectsOutputToContain('nixpal.com: account #' . $client->id . ', expires 2027-11-17, active')->assertSuccessful();
        $domain = Domain::query()->sole();
        $this->assertSame([$client->primaryContact->id, $client->primaryContact->id], [$domain->registrant_contact_id, $domain->admin_contact_id]);
        $this->artisan('domains:adopt', ['domain' => 'nixpal.com', '--client' => $client->id, '--connection' => $this->op->id])->assertFailed();

        $this->artisan('domains:adopt', ['domain' => 'not-ours.com', '--client' => $client->id, '--connection' => $this->op->id])->assertFailed();
        $this->assertSame(1, Domain::query()->count(), 'not at the registrar: nothing created');
    }

    public function test_staff_add_an_existing_domain_from_the_admin(): void
    {
        $this->fakeRecord([]);
        $staff = fn (array $permissions) => Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);

        $this->livewireAs($staff(['admin.domains.viewAny']))->test(ListDomains::class)->assertActionHidden('addExisting');
        $this->livewireAs($staff(['admin.domains.viewAny', 'admin.domains.create']))->test(ListDomains::class)
            ->callAction('addExisting', ['domain' => 'nixpal.com', 'client_id' => $this->user->client()->id, 'connection_id' => $this->op->id])
            ->assertHasNoActionErrors();

        $domain = Domain::query()->sole();
        $this->assertSame(['nixpal.com', '2027-11-17', $this->user->client()->id], [$domain->fqdn, $domain->expires_at->toDateString(), $domain->client_id]);
    }

    public function test_sync_now_for_staff_who_may(): void
    {
        $domain = $this->domain();
        $this->fakeRecord([]);
        $staff = fn (array $permissions) => Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);

        $this->livewireAs($staff(['admin.domains.viewAny']))->test(ViewDomain::class, ['record' => $domain->getRouteKey()])->assertActionHidden('sync');
        $this->livewireAs($staff(['admin.domains.viewAny', 'admin.domains.update']))->test(ViewDomain::class, ['record' => $domain->getRouteKey()])
            ->callAction('sync')->assertNotified(__('Synced.'));
        $this->assertSame('2027-11-17', $domain->fresh()->expires_at->toDateString());
    }
}
