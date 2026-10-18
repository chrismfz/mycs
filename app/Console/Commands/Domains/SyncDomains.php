<?php

namespace App\Console\Commands\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Services\Domains\DomainLifecycle;
use App\Services\Domains\DomainSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Throwable;

/**
 * The domains against their registrars (docs/domains.md D-9), in batches (owner 2026-10-18: one cron, never all at once:
 * 1,400 .gr at myip, the registry's account one command at a time): called every 10 minutes, it reads a batch every
 * «domain_sync_hours» (Settings → Domains, default 2; 0 = paused), «domain_sync_batch» domains of each registrar
 * connection (default 200), the most needed first: pending orders, ones a registrar said are not ours, names expiring
 * within 30 days, then the longest unsynced; a short pause between them leaves the account free for the shop. Then
 * every live domain's life by its dates (D-8), synced or not. `--domain` one now; by hand (no `--scheduled`) a batch
 * now; `--dry-run` the
 * diff report: what would change, nothing written. Ended domains are left alone. A pending transfer older than its
 * days is reported. One domain's failure never stops the rest.
 */
class SyncDomains extends Command
{
    public const STALE_TRANSFER_DAYS = 14;

    /** When the last batch ran (its hours are counted from it). */
    public const LAST_BATCH = 'domains-sync-last-batch';

    /** Milliseconds between two domains of a batch (tests: 0). */
    public static int $pauseMs = 500;

    /** Days a transfer (or registration) may stay pending before staff are told (Settings → Domains, default 14). */
    public static function staleTransferDays(): int
    {
        return max(1, (int) config('settings.domain_stale_transfer_days', self::STALE_TRANSFER_DAYS));
    }

    protected $signature = 'domains:sync {--domain= : one domain (its name)} {--scheduled : the scheduler\'s call: a batch only when its hours have passed}
        {--dry-run : report what would change, write nothing}';

    protected $description = 'Sync a batch of domains with their registrars, then move every live domain by its dates (grace, redemption)';

    public function handle(DomainSyncService $sync, DomainLifecycle $lifecycle): int
    {
        Config::set('audit.console', true); // its changes are audited, as CronJob's (D-9)
        $write = !$this->option('dry-run');
        $live = fn () => Domain::query()->whereNotIn('status', DomainStatus::terminalValues())
            ->with(['domainTld.registrarConnection', 'registrarConnection', 'nameservers']);
        if ($this->option('domain')) {
            try {
                $one = $live()->where('fqdn', Domain::normalise((string) $this->option('domain')))->get();
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            if ($one->isEmpty()) {
                $this->error('No such live domain.');

                return self::FAILURE;
            }

            return $this->syncThenMove($one, $one->pluck('id')->all(), $sync, $lifecycle, $write);
        }

        // Its hours (0 = paused: the dates still move the domains, every 2 hours); 5 minutes' slack for the 10-minute calls.
        $hours = (float) config('settings.domain_sync_hours', 2);
        $paused = $hours <= 0;
        $last = Cache::get(self::LAST_BATCH);
        $due = !is_string($last) || Carbon::parse($last)->addMinutes((int) round(($paused ? 2 : $hours) * 60) - 5)->lessThanOrEqualTo(now());
        if ($this->option('scheduled') && !$due) {
            return self::SUCCESS; // not its time yet
        }
        if ($write) {
            Cache::forever(self::LAST_BATCH, now()->toIso8601String());
        }
        $all = $live()->get();
        if ($paused && $this->option('scheduled')) {
            $this->warn('The sync is paused (Settings → Domains): only the dates move the domains.');

            return $this->syncThenMove($all, [], $sync, $lifecycle, $write);
        }

        return $this->syncThenMove($all, self::batch($all, max(1, (int) config('settings.domain_sync_batch', 200))), $sync, $lifecycle, $write);
    }

    /**
     * Its batch: per registrar connection, the most needed first (pending, «not ours», expiring within 30 days and not read
     * within 12 hours), then the longest unsynced (never synced first).
     *
     * @param  Collection<int, Domain>  $domains
     * @return list<int> their ids
     */
    public static function batch(Collection $domains, int $size): array
    {
        $soon = now()->addDays(30)->toDateString();
        $recent = now()->subHours(12);

        return $domains->toBase()->groupBy(fn (Domain $d) => (string) ($d->effectiveRegistrarConnection()->id ?? 'none'))
            ->except('none')
            ->flatMap(fn (Collection $group) => $group->sortBy(fn (Domain $d) => self::priority($d, $soon, $recent))->take($size)->pluck('id'))
            ->values()->all();
    }

    /** @return list<int|string> its place in its connection's batch (lower first) */
    private static function priority(Domain $d, string $soon, Carbon $recent): array
    {
        $synced = $d->last_synced_at !== null ? Carbon::parse($d->last_synced_at) : null;

        return [
            in_array($d->status, [DomainStatus::PendingRegister, DomainStatus::PendingTransfer], true) ? 0 : 1,
            is_array($d->meta['not_ours'] ?? null) ? 0 : 1,
            // Expiring soon, unless read within 12 hours (never every batch: many would take it all).
            $d->expires_at !== null && $d->expires_at->toDateString() <= $soon && ($synced === null || $synced->lt($recent)) ? 0 : 1,
            $synced?->getTimestamp() ?? 0,
            $d->id,
        ];
    }

    /**
     * Those of $sync read from their registrars (a pause between them), then every one moved by its dates.
     *
     * @param  Collection<int, Domain>  $domains
     * @param  list<int>  $sync
     */
    private function syncThenMove(Collection $domains, array $sync, DomainSyncService $syncer, DomainLifecycle $lifecycle, bool $write): int
    {
        $failed = 0;
        $read = 0;
        foreach ($domains as $domain) {
            try {
                if (in_array($domain->id, $sync, true) && $syncer->isSyncable($domain)) {
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
                    if ($write) {
                        $domain->refresh();
                    } else {
                        // What the real run would see: the planned registrar truth, in memory only (review F10).
                        $planned = array_intersect_key(array_map(fn (array $c) => $c[1], $changes), array_flip(['expires_at', 'status', 'grace_ends_on', 'redemption_ends_on']));
                        $domain->forceFill($planned);
                    }
                }
                // The dates move it even when its registrar could not be read, or was not read this batch (review F9).
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
            if ($domain->status === DomainStatus::PendingTransfer && $domain->created_at?->lt(now()->subDays(self::staleTransferDays()))) {
                $this->warn("{$domain->fqdn}: pending transfer for more than " . self::staleTransferDays() . ' days');
            }
        }
        $this->info("Read {$read} of " . $domains->count() . ' live domain(s) from their registrars.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
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
