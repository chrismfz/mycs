<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\Order;
use App\Models\User;
use App\Services\Domains\DomainNotFoundAtRegistrar;
use App\Services\Domains\Registrars\OpenproviderRegistrar;
use App\Services\Ops\Health;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Adversarial review of #204 (the sync in batches): ways a domain is never (or far too late) read, the dates stop
 * moving, the schedule stops silently, or two batches run at once.
 */
class DomainBatchedSyncAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Cache::flush();
        Adv204Registrar::reset();
        config(['domains.registrars.adv204' => Adv204Registrar::class]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Adv204Registrar::reset();
        parent::tearDown();
    }

    private function connection(string $label = 'A'): DomainRegistrarConnection
    {
        return DomainRegistrarConnection::query()->create(['registrar' => 'adv204', 'label' => $label, 'is_active' => true, 'mode' => 'production']);
    }

    private function domain(DomainRegistrarConnection $connection, string $sld, array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => $sld, 'tld' => 'com', 'fqdn' => "{$sld}.com",
            'domain_tld_id' => null, 'registrar_connection_id' => $connection->id, 'status' => DomainStatus::Active, 'expires_at' => '2028-01-01',
            'currency_code' => 'EUR', 'years' => 1, 'last_synced_at' => '2026-10-19 10:00:00']);
    }

    /**
     * ADV-1: an order not yet sent to its registrar (unpaid, or held for staff) is pending, so first in every batch,
     * but never read (isSyncable: unsent), so its last_synced_at never moves: it keeps its place for ever. As many of
     * them as the batch and nothing else of that connection is ever read again, an expiring one included.
     */
    public function test_adv1_unsent_orders_never_take_the_batch_for_ever(): void
    {
        config(['settings.domain_sync_batch' => 2]);
        $a = $this->connection();
        foreach (['cart1', 'cart2'] as $sld) {
            $order = Order::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'EUR']);
            $this->domain($a, $sld, ['status' => DomainStatus::PendingTransfer, 'expires_at' => null, 'order_id' => $order->id, 'last_synced_at' => null]);
        }
        $this->domain($a, 'expiring', ['expires_at' => '2026-11-01', 'last_synced_at' => '2026-10-10 10:00:00']);

        foreach (range(1, 3) as $i) {
            Carbon::setTestNow(Carbon::now()->addHours(13));
            $this->artisan('domains:sync')->assertExitCode(0);
        }

        $this->assertContains('expiring.com', Adv204Registrar::$read, 'an expiring domain is read, not starved by orders the sync never reads');
    }

    /**
     * ADV-2: pending (sent) orders come first in every batch, always: as many pending transfers on a connection as its
     * batch (a migration CNIC → Openprovider, D-10) and none of its other domains is read for as long as they pend
     * (days): their renewals billed and reminded on stale expiries. «The pending more often» (ROADMAP), not «only».
     */
    public function test_adv2_pending_transfers_starve_the_rest_of_their_connection(): void
    {
        config(['settings.domain_sync_batch' => 2]);
        $a = $this->connection();
        foreach (['moving1', 'moving2'] as $sld) {
            $this->domain($a, $sld, ['status' => DomainStatus::PendingTransfer, 'expires_at' => null, 'meta' => ['sent_at' => '2026-10-19T10:00:00+00:00']]);
        }
        Adv204Registrar::$pending = ['moving1.com', 'moving2.com']; // still pending at the registrar
        $this->domain($a, 'renewed-elsewhere', ['expires_at' => '2026-11-10', 'last_synced_at' => '2026-10-01 10:00:00']);

        foreach (range(1, 6) as $i) { // three days of batches every 13 hours
            Carbon::setTestNow(Carbon::now()->addHours(13));
            $this->artisan('domains:sync')->assertExitCode(0);
        }

        $this->assertContains('renewed-elsewhere.com', Adv204Registrar::$read, 'its other domains are read while transfers pend');
    }

    /**
     * ADV-3: a lapsed domain its registrar does not know (an expired name long deleted: the importer brings WHMCS's
     * Expired ones) is only noted «not ours», never marked: the note keeps it in the first tier of every batch for ever.
     * As many as the batch and nothing else of that connection is read.
     */
    public function test_adv3_lapsed_not_ours_notes_take_the_batch_for_ever(): void
    {
        config(['settings.domain_sync_batch' => 2]);
        $a = $this->connection();
        foreach (['gone1', 'gone2'] as $sld) {
            $this->domain($a, $sld, ['status' => DomainStatus::Expired, 'expires_at' => '2025-01-01']);
        }
        Adv204Registrar::$missing = ['gone1.com', 'gone2.com'];
        $this->domain($a, 'live', ['last_synced_at' => '2026-10-15 10:00:00']);

        foreach (range(1, 4) as $i) {
            Carbon::setTestNow(Carbon::now()->addHours(13));
            $this->artisan('domains:sync'); // exit 1: the «not found» ones fail
        }

        $this->assertContains('live.com', Adv204Registrar::$read, 'a live domain is read, not starved by lapsed «not ours» notes');
    }

    /**
     * ADV-4: the dates (D-8: expired, grace, redemption, deleted) now move only when a batch is due: with
     * «domain_sync_hours» 24 an expired domain stays Active (billed, shown, reminded as Active) up to a day, longer than
     * when the sync is paused (every 2 hours) and than before (every 4 hours).
     */
    public function test_adv4_the_dates_move_at_most_every_two_hours_whatever_the_batch_hours(): void
    {
        config(['settings.domain_sync_hours' => 24]);
        $a = $this->connection();
        $this->domain($a, 'one');
        $this->artisan('domains:sync', ['--scheduled' => true])->assertExitCode(0);

        $lapsed = $this->domain($a, 'lapsed', ['expires_at' => '2026-10-15', 'status' => DomainStatus::Active]);
        Carbon::setTestNow('2026-10-20 12:10:00');
        $this->artisan('domains:sync', ['--scheduled' => true])->assertExitCode(0);

        $this->assertNotSame(DomainStatus::Active, $lapsed->fresh()->status, 'moved by its dates within 2 hours, batch due or not');
    }

    /**
     * ADV-5: the sync paused (0 hours) and forgotten, or stopped: nothing says so. Health's «domains» check stays OK
     * with no domain read for a month (it reports the oldest sync but never warns on it).
     */
    public function test_adv5_a_forgotten_pause_is_seen_by_health(): void
    {
        config(['settings.domain_sync_hours' => 0]);
        $a = $this->connection();
        $this->domain($a, 'one', ['last_synced_at' => '2026-09-20 10:00:00']);
        $this->domain($a, 'two', ['last_synced_at' => '2026-09-20 10:00:00']);
        $this->artisan('domains:sync', ['--scheduled' => true])->assertExitCode(0);

        $check = Health::report()['checks']['domains'];
        $this->assertNotSame(Health::OK, $check['status'], 'no domain read for a month (the sync paused): a warning, not OK');
    }

    /**
     * ADV-6: two batches at once. The scheduler's withoutOverlapping only guards the scheduler; `domains:sync` by hand
     * (docs: «a batch at once») while the scheduled batch runs reads the same connection's batch again, in parallel:
     * the registrar asked twice as hard, what the batching was for (one command at a time at the .gr registry).
     */
    public function test_adv6_a_batch_by_hand_does_not_run_beside_a_running_batch(): void
    {
        $a = $this->connection();
        foreach (range(1, 4) as $i) {
            $this->domain($a, "d{$i}", ['last_synced_at' => "2026-10-19 0{$i}:00:00"]);
        }
        Adv204Registrar::$nested = function () {
            Artisan::call('domains:sync'); // staff run it by hand meanwhile
        };

        $this->artisan('domains:sync', ['--scheduled' => true]);

        $this->assertSame(0, Adv204Registrar::$readNested, 'a second batch while one runs reads nothing');
    }

    /**
     * ADV-7: «expiring within 30 days» is fixed while the renewal invoice («domain_invoice_days») and the first notice
     * («domain_reminder_days») are settings up to a year: at 45 days a domain about to be invoiced has no priority over
     * one expiring in 2028, so it is invoiced on whatever its last read said (a renewal at the registrar's console unseen).
     */
    public function test_adv7_the_priority_window_follows_the_invoice_days(): void
    {
        config(['settings.domain_sync_batch' => 1, 'settings.domain_invoice_days' => 45]);
        $a = $this->connection();
        $this->domain($a, 'far', ['expires_at' => '2028-01-01', 'last_synced_at' => '2026-10-19 13:00:00']);
        $this->domain($a, 'invoiced-soon', ['expires_at' => '2026-11-29', 'last_synced_at' => '2026-10-19 14:00:00']);

        $this->artisan('domains:sync')->assertExitCode(0);

        $this->assertSame(['invoiced-soon.com'], Adv204Registrar::$read, 'the one invoiced within its invoice days first');
    }
}

/** A registrar answering active (or pending, or «not found»), counting what it reads. */
class Adv204Registrar extends OpenproviderRegistrar
{
    /** @var list<string> */
    public static array $read = [];

    /** @var list<string> */
    public static array $pending = [];

    /** @var list<string> */
    public static array $missing = [];

    /** @var (\Closure(): void)|null */
    public static ?\Closure $nested = null;

    public static int $readNested = 0;

    private static bool $inNested = false;

    public static function reset(): void
    {
        self::$read = [];
        self::$pending = [];
        self::$missing = [];
        self::$nested = null;
        self::$readNested = 0;
        self::$inNested = false;
    }

    public function syncDomain(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        self::$read[] = $domain->fqdn;
        if (self::$inNested) {
            self::$readNested++;
        } elseif (self::$nested !== null) {
            $nested = self::$nested;
            self::$nested = null;
            self::$inNested = true;
            try {
                $nested();
            } finally {
                self::$inNested = false;
            }
        }
        if (in_array($domain->fqdn, self::$missing, true)) {
            throw new DomainNotFoundAtRegistrar("{$domain->fqdn}: not in this account");
        }
        if (in_array($domain->fqdn, self::$pending, true)) {
            return new DomainSyncResult(expiresAt: null, status: DomainStatus::PendingTransfer, rawStatus: 'REQ');
        }

        return new DomainSyncResult(expiresAt: $domain->expires_at?->toDateString(), status: DomainStatus::Active, rawStatus: 'ok');
    }
}
