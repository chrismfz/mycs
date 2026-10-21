<?php

namespace App\Console\Commands\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Services\Domains\DomainLifecycle;
use App\Services\Domains\DomainSyncService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Throwable;

/**
 * The domains against their registrars (docs/domains.md D-9), in batches (owner 2026-10-18: one cron, never all at once:
 * 1,400 .gr at myip, the registry's account one command at a time). Called every 10 minutes (`--scheduled`):
 * - a batch every «domain_sync_hours» (Settings → Domains, default 2; 0 = paused): «domain_sync_batch» domains of each
 *   registrar connection (default 200), up to half of it the most needed (pending orders, names about to be invoiced or
 *   reminded, a «not ours» waiting for its second sighting: each not read just now), the rest the longest unsynced; only
 *   ones that can be read; a short pause between them; one batch at a time (a lock);
 * - every live domain's life by its dates (D-8) at least every 2 hours, whatever the batch hours, read fresh.
 * By hand a batch and the dates now (paused: the dates only, unless --force); `--domain` one now; `--dry-run` the diff
 * report: what would change, nothing written. Ended domains are left alone. One domain's failure never stops the rest.
 */
class SyncDomains extends Command
{
    public const STALE_TRANSFER_DAYS = 14;

    /** When the last batch, and the last move by dates, ran. */
    public const LAST_BATCH = 'domains-sync-last-batch';

    public const LAST_DATES = 'domains-sync-last-dates';

    /** One batch at a time, by hand or scheduled (review ADV-6 of #204). */
    public const BATCH_LOCK = 'domains-sync-batch';

    /** Milliseconds between two domains of a batch (unit tests: none). */
    public static int $pauseMs = 500;

    /**
     * Pending longer than staleTransferDays: from its order (created_at), or for a transfer that replaced a renewal from
     * when it was sent (an old domain is not «stale» the minute it is sent: RAT-ADV-6 of #303).
     *
     * @template T of \Illuminate\Database\Eloquent\Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function stalePending($query)
    {
        $cutoff = now()->subDays(self::staleTransferDays());

        return $query->where(fn ($q) => $q->where(fn ($order) => $order->whereNull('meta->renew_transfer')->where('created_at', '<', $cutoff))
            ->orWhere('meta->renew_transfer->sent_at', '<', $cutoff->toIso8601String()));
    }

    /** Days a transfer (or registration) may stay pending before staff are told (Settings → Domains, default 14). */
    public static function staleTransferDays(): int
    {
        return max(1, (int) config('settings.domain_stale_transfer_days', self::STALE_TRANSFER_DAYS));
    }

    protected $signature = 'domains:sync {--domain= : one domain (its name)} {--scheduled : the scheduler\'s call: a batch when its hours have passed}
        {--force : a batch by hand even while paused} {--dry-run : report what would change, write nothing}';

    protected $description = 'Sync a batch of domains with their registrars, and move every live domain by its dates (grace, redemption)';

    /** @var array<int, array<string, mixed>> a dry run's planned registrar truth, by domain (review F10) */
    private array $planned = [];

    public function handle(DomainSyncService $sync, DomainLifecycle $lifecycle): int
    {
        Config::set('audit.console', true); // its changes are audited, as CronJob's (D-9)
        $write = !$this->option('dry-run');
        if ($this->option('domain')) {
            try {
                $fqdn = Domain::normalise((string) $this->option('domain'));
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $one = self::live()->where('fqdn', $fqdn)->pluck('id')->all();
            if ($one === []) {
                $this->error('No such live domain.');

                return self::FAILURE;
            }
            $failed = $this->syncIds($one, $sync, $write);

            return $failed + $this->moveByDates($lifecycle, $write, $one) === 0 ? self::SUCCESS : self::FAILURE;
        }

        $hours = max(0.0, (float) config('settings.domain_sync_hours', 2));
        $paused = $hours <= 0;
        $batchDue = !$paused && self::due(Cache::get(self::LAST_BATCH), $hours * 60);
        $datesDue = self::due(Cache::get(self::LAST_DATES), 120);
        if ($this->option('scheduled')) {
            [$batch, $dates] = [$batchDue, $datesDue];
        } else {
            [$batch, $dates] = [!$paused || $this->option('force'), true];
            if ($paused && !$this->option('force')) {
                $this->warn('The sync is paused (Settings → Domains): only the dates move the domains (--force reads a batch anyway).');
            }
        }
        $failed = 0;
        if ($batch) {
            $lock = Cache::lock(self::BATCH_LOCK, 3600);
            if (!$lock->get()) {
                $this->warn('A batch is running already: not another beside it.');
            } else {
                try {
                    if ($write) {
                        Cache::forever(self::LAST_BATCH, now()->toIso8601String());
                    }
                    $ids = self::batch(self::live()->get(), max(1, (int) config('settings.domain_sync_batch', 200)),
                        fn (Domain $d) => $sync->isSyncable($d));
                    $failed += $this->syncIds($ids, $sync, $write);
                } finally {
                    $lock->release();
                }
            }
        }
        if ($dates) {
            if ($write) {
                Cache::forever(self::LAST_DATES, now()->toIso8601String());
            }
            $failed += $this->moveByDates($lifecycle, $write);
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return Builder<Domain> */
    private static function live(): Builder
    {
        return Domain::query()->whereNotIn('status', DomainStatus::terminalValues())
            ->with(['domainTld.registrarConnection', 'registrarConnection', 'nameservers']);
    }

    /** Never ran, its minutes passed (5 minutes' slack for the 10-minute calls), or a time in the future (a clock moved). */
    private static function due(mixed $last, float $minutes): bool
    {
        if (!is_string($last)) {
            return true;
        }
        $at = Carbon::parse($last);

        return $at->greaterThan(now()) || $at->addMinutes((int) round($minutes) - 5)->lessThanOrEqualTo(now());
    }

    /**
     * Its batch, per registrar connection, among those $syncable (an unsent order, an ended registrar: never a slot):
     * up to half of it the most needed, each not read just now (pending: an hour; «not ours» waiting for its second
     * sighting: an hour; about to be invoiced or reminded: 12 hours), then the longest unsynced (never synced first).
     *
     * @param  Collection<int, Domain>  $domains
     * @param  (callable(Domain): bool)|null  $syncable
     * @return list<int> their ids
     */
    public static function batch(Collection $domains, int $size, ?callable $syncable = null): array
    {
        $hour = now()->subHour();
        $halfDay = now()->subHours(12);
        $day = now()->subDay();
        // Its invoice or its first reminder (review ADV-7): the window the sync must be fresh for.
        $reminders = array_map('intval', array_filter(array_map('trim', explode(',', (string) config('settings.domain_reminder_days', '30,7,1,-3'))), 'is_numeric'));
        $window = max(30, (int) config('settings.domain_invoice_days', 15), ...($reminders !== [] ? $reminders : [0]));
        $from = now()->subDays(60)->toDateString(); // its grace and redemption, not names long lapsed (review 2)
        $to = now()->addDays($window)->toDateString();
        $synced = fn (Domain $d) => $d->last_synced_at !== null ? Carbon::parse($d->last_synced_at) : null;
        $need = function (Domain $d) use ($synced, $hour, $halfDay, $day, $from, $to): ?int {
            $at = $synced($d);
            if (in_array($d->status, [DomainStatus::PendingRegister, DomainStatus::PendingTransfer], true) && ($at === null || $at->lt($hour))) {
                return 0;
            }
            $note = $d->meta['not_ours'] ?? null;
            if (is_array($note) && $d->status === DomainStatus::Active && is_string($note['since'] ?? null)
                && Carbon::parse($note['since'])->greaterThanOrEqualTo($day) && ($at === null || $at->lt($hour))) {
                return 1;
            }
            $expires = $d->expires_at?->toDateString();
            if ($expires !== null && $expires >= $from && $expires <= $to && ($at === null || $at->lt($halfDay))) {
                return 2;
            }

            return null;
        };

        return $domains->toBase()
            ->filter(fn (Domain $d) => $d->effectiveRegistrarConnection() !== null && ($syncable === null || $syncable($d)))
            ->groupBy(fn (Domain $d) => (string) $d->effectiveRegistrarConnection()?->id)
            ->flatMap(function (Collection $group) use ($size, $need, $synced) {
                $oldest = fn (Domain $d) => [$synced($d)?->getTimestamp() ?? 0, $d->id];
                [$needed, $rest] = $group->partition(fn (Domain $d) => $need($d) !== null);
                $needed = $needed->sortBy(fn (Domain $d) => [$need($d), ...$oldest($d)])->values();
                // Half for the most needed (review ADV-2: many pending never take it all).
                $first = $needed->take((int) ceil($size / 2));
                // The rest by age alone, the needed left over among them (never the same tier taking every slot).
                $then = $rest->concat($needed->slice($first->count()))->sortBy($oldest)->take($size - $first->count());

                return $first->concat($then)->pluck('id');
            })
            ->values()->all();
    }

    /**
     * These read from their registrars, a pause between them.
     *
     * @param  list<int>  $ids
     */
    private function syncIds(array $ids, DomainSyncService $syncer, bool $write): int
    {
        $failed = 0;
        $read = 0;
        foreach (self::live()->whereKey($ids)->lazyById(100) as $domain) {
            if (!$syncer->isSyncable($domain)) {
                continue;
            }
            if ($read++ > 0 && self::$pauseMs > 0 && !app()->runningUnitTests()) {
                usleep(self::$pauseMs * 1000); // the registrar's account left free for the shop between two
            }
            try {
                $changes = $syncer->sync($domain, $write);
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$domain->fqdn}: " . $e->getMessage());
                $changes = [];
            }
            foreach ($changes as $field => [$now, $then]) {
                $this->line("{$domain->fqdn}: " . ($field === '_warning' ? "warning: {$then}" : "{$field} " . self::show($now) . ' → ' . self::show($then)));
            }
            if (!$write) {
                // What the real run would see: the planned registrar truth, in memory only (review F10).
                $this->planned[$domain->id] = array_intersect_key(array_map(fn (array $c) => $c[1], $changes), array_flip(['expires_at', 'status', 'grace_ends_on', 'redemption_ends_on']));
            }
        }
        $this->info("Read {$read} domain(s) from their registrars.");

        return $failed;
    }

    /**
     * Every live domain (or these) moved by its dates, read fresh (review 4 of #204: never a model loaded before a batch
     * of minutes), even when its registrar could not be read or was not read this batch (review F9).
     *
     * @param  list<int>|null  $only
     */
    private function moveByDates(DomainLifecycle $lifecycle, bool $write, ?array $only = null): int
    {
        $failed = 0;
        $query = self::live();
        if ($only !== null) {
            $query->whereKey($only);
        }
        foreach ($query->lazyById(200) as $domain) {
            try {
                if (isset($this->planned[$domain->id])) {
                    $domain->forceFill($this->planned[$domain->id]);
                }
                $next = $lifecycle->next($domain);
                if ($next !== null) {
                    $this->line("{$domain->fqdn}: status {$domain->status->value} → {$next->value} (dates)");
                    if ($write) {
                        $lifecycle->advance($domain);
                    }
                }
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$domain->fqdn}: " . $e->getMessage());
            }
            $since = isset($domain->meta['renew_transfer']['sent_at']) ? Carbon::parse($domain->meta['renew_transfer']['sent_at']) : $domain->created_at;
            if ($domain->status === DomainStatus::PendingTransfer && $since?->lt(now()->subDays(self::staleTransferDays()))) {
                $this->warn("{$domain->fqdn}: pending transfer for more than " . self::staleTransferDays() . ' days');
            }
        }

        return $failed;
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'yes' : 'no',
            default => (string) $value,
        };
    }
}
