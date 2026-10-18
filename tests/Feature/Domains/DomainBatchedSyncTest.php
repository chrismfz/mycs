<?php

namespace Tests\Feature\Domains;

use App\Console\Commands\Domains\SyncDomains;
use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\User;
use App\Services\Domains\Registrars\OpenproviderRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The sync in batches (owner 2026-10-18): one cron, every «domain_sync_hours» (default 2; 0 = paused), so many domains
 * of each registrar connection (default 200), the most needed first: pending, «not ours», expiring within 30 days, then
 * the longest unsynced. Every live domain still moves by its dates. By hand it reads a batch at once.
 */
class DomainBatchedSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Cache::flush();
        CountingRegistrar::$read = [];
        config(['domains.registrars.counting' => CountingRegistrar::class]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function connection(string $label): DomainRegistrarConnection
    {
        return DomainRegistrarConnection::query()->create(['registrar' => 'counting', 'label' => $label, 'is_active' => true, 'mode' => 'production']);
    }

    private function domain(DomainRegistrarConnection $connection, string $sld, array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => $sld, 'tld' => 'com', 'fqdn' => "{$sld}.com",
            'domain_tld_id' => null, 'registrar_connection_id' => $connection->id, 'status' => DomainStatus::Active, 'expires_at' => '2028-01-01',
            'currency_code' => 'EUR', 'years' => 1, 'last_synced_at' => '2026-10-19 10:00:00']);
    }

    public function test_a_batch_per_connection_the_most_needed_first(): void
    {
        config(['settings.domain_sync_batch' => 3]);
        $a = $this->connection('A');
        $b = $this->connection('B');
        foreach (range(1, 6) as $i) {
            $this->domain($a, "a{$i}", ['last_synced_at' => "2026-10-19 0{$i}:00:00"]);
            $this->domain($b, "b{$i}");
        }
        $this->domain($a, 'pending', ['status' => DomainStatus::PendingTransfer, 'expires_at' => null, 'meta' => ['sent_at' => '2026-10-19T10:00:00+00:00']]);
        $this->domain($a, 'expiring', ['expires_at' => '2026-11-01']);
        $this->domain($a, 'notours', ['meta' => ['not_ours' => ['since' => '2026-10-20T09:00:00+00:00']]]);
        $this->domain($a, 'never', ['last_synced_at' => null]);

        $this->artisan('domains:sync')->assertExitCode(0);

        $readA = array_values(array_filter(CountingRegistrar::$read, fn ($f) => str_starts_with($f, 'a') || in_array($f, ['pending.com', 'expiring.com', 'notours.com', 'never.com'], true)));
        $this->assertSame(['pending.com', 'expiring.com', 'notours.com'], array_values(array_intersect(['pending.com', 'expiring.com', 'notours.com', 'never.com', 'a1.com'], $readA)),
            'pending, «not ours» and expiring first');
        $this->assertCount(3, $readA, 'its batch for connection A');
        $this->assertCount(3, array_filter(CountingRegistrar::$read, fn ($f) => str_starts_with($f, 'b')), 'and B its own');

        // The next batch: the longest unsynced next (never synced, then a1, a2 …).
        CountingRegistrar::$read = [];
        $this->artisan('domains:sync')->assertExitCode(0);
        $next = array_values(array_filter(CountingRegistrar::$read, fn ($f) => !str_starts_with($f, 'b')));
        sort($next);
        $this->assertSame(['a1.com', 'a2.com', 'never.com'], $next, 'expiring.com read an hour ago: not again');
    }

    public function test_the_schedule_keeps_its_hours_and_paused_only_the_dates_move(): void
    {
        $a = $this->connection('A');
        $this->domain($a, 'one');
        $lapsed = $this->domain($a, 'lapsed', ['expires_at' => '2026-10-10', 'status' => DomainStatus::Active]);

        $this->artisan('domains:sync', ['--scheduled' => true])->assertExitCode(0);
        $this->assertCount(2, CountingRegistrar::$read, 'the first call reads its batch');

        CountingRegistrar::$read = [];
        Carbon::setTestNow('2026-10-20 11:00:00');
        $this->artisan('domains:sync', ['--scheduled' => true])->assertExitCode(0);
        $this->assertSame([], CountingRegistrar::$read, 'within its 2 hours: nothing');

        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->artisan('domains:sync', ['--scheduled' => true])->assertExitCode(0);
        $this->assertCount(2, CountingRegistrar::$read, '2 hours on: the next batch');

        // Paused (0 hours): no registrar read; the dates still move a domain past its expiry.
        config(['settings.domain_sync_hours' => 0]);
        CountingRegistrar::$read = [];
        CountingRegistrar::$expires = '2026-10-10';
        Carbon::setTestNow('2026-10-20 14:00:00');
        $lapsed->forceFill(['status' => DomainStatus::Active])->saveQuietly();
        $this->artisan('domains:sync', ['--scheduled' => true])->assertExitCode(0);
        $this->assertSame([], CountingRegistrar::$read);
        $this->assertNotSame(DomainStatus::Active, $lapsed->fresh()->status, 'moved by its dates');

        // By hand, a batch whatever its hours.
        $this->artisan('domains:sync')->assertExitCode(0);
        $this->assertNotSame([], CountingRegistrar::$read);
    }

    public function test_one_domain_by_hand_and_the_batch_function(): void
    {
        $a = $this->connection('A');
        $this->domain($a, 'one');
        $this->domain($a, 'two');
        $this->artisan('domains:sync', ['--domain' => 'two.com'])->assertExitCode(0);
        $this->assertSame(['two.com'], CountingRegistrar::$read);

        $this->assertCount(1, SyncDomains::batch(Domain::query()->get(), 1));
    }
}

/** A registrar that answers every domain as active (its expiry as kept, or $expires), counting what it reads. */
class CountingRegistrar extends OpenproviderRegistrar
{
    /** @var list<string> */
    public static array $read = [];

    public static ?string $expires = null;

    public function syncDomain(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        self::$read[] = $domain->fqdn;

        return new DomainSyncResult(expiresAt: self::$expires ?? $domain->expires_at?->toDateString(), status: DomainStatus::Active, rawStatus: 'ok');
    }
}
