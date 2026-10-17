<?php

namespace App\Console\Commands\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Services\Domains\DomainLifecycle;
use App\Services\Domains\DomainSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Throwable;

/**
 * The domains against their registrars (docs/domains.md D-9), every 4 hours, as WHMCS's «Domain Status / Transfer
 * Synchronisation»; then their life by their dates (D-8). `--dry-run` is the diff report: what would change, nothing
 * written. Ended domains (cancelled, transferred away, deleted) are left alone. A pending transfer older than 14 days
 * (since it was asked) is reported. One domain's failure never stops the rest.
 */
class SyncDomains extends Command
{
    public const STALE_TRANSFER_DAYS = 14;

    /** Days a transfer (or registration) may stay pending before staff are told (Settings → Domains, default 14). */
    public static function staleTransferDays(): int
    {
        return max(1, (int) config('settings.domain_stale_transfer_days', self::STALE_TRANSFER_DAYS));
    }

    protected $signature = 'domains:sync {--domain= : one domain (its name)} {--dry-run : report what would change, write nothing}';

    protected $description = 'Sync domains with their registrars, then move them by their dates (grace, redemption)';

    public function handle(DomainSyncService $sync, DomainLifecycle $lifecycle): int
    {
        Config::set('audit.console', true); // its changes are audited, as CronJob's (D-9)
        $write = !$this->option('dry-run');
        $query = Domain::query()->whereNotIn('status', DomainStatus::terminalValues())
            ->with(['domainTld.registrarConnection', 'registrarConnection', 'nameservers']);
        if ($this->option('domain')) {
            try {
                $query->where('fqdn', Domain::normalise((string) $this->option('domain')));
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            if (!$query->clone()->exists()) {
                $this->error('No such live domain.');

                return self::FAILURE;
            }
        }
        $failed = 0;
        foreach ($query->lazyById() as $domain) {
            try {
                if ($sync->isSyncable($domain)) {
                    try {
                        $changes = $sync->sync($domain, $write);
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
                // The dates move it even when its registrar could not be read (review F9).
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
