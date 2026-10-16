<?php

namespace App\Console\Commands\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Services\Domains\DomainLifecycle;
use App\Services\Domains\DomainSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * The domains against their registrars (docs/domains.md D-9), every 4 hours, as WHMCS's «Domain Status / Transfer
 * Synchronisation»; then their life by their dates (D-8). `--dry-run` is the diff report: what would change, nothing
 * written. A pending transfer older than 14 days is reported. One domain's failure never stops the rest.
 */
class SyncDomains extends Command
{
    public const STALE_TRANSFER_DAYS = 14;

    protected $signature = 'domains:sync {--domain= : one domain (its name)} {--dry-run : report what would change, write nothing}';

    protected $description = 'Sync domains with their registrars, then move them by their dates (grace, redemption)';

    public function handle(DomainSyncService $sync, DomainLifecycle $lifecycle): int
    {
        Config::set('audit.console', true); // its changes are audited, as CronJob's (D-9)
        $write = !$this->option('dry-run');
        $query = Domain::query()->whereNotIn('status', [DomainStatus::Cancelled->value, DomainStatus::TransferredAway->value])
            ->with(['domainTld.registrarConnection', 'registrarConnection', 'nameservers'])->orderBy('id');
        if ($this->option('domain')) {
            $query->where('fqdn', Domain::normalise((string) $this->option('domain')));
        }
        $failed = 0;
        foreach ($query->lazy() as $domain) {
            if ($sync->isSyncable($domain)) {
                try {
                    foreach ($sync->sync($domain, $write) as $field => [$now, $then]) {
                        $this->line("{$domain->fqdn}: {$field} " . self::show($now) . ' → ' . self::show($then));
                    }
                    $domain->refresh();
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("{$domain->fqdn}: " . $e->getMessage());

                    continue;
                }
            }
            $next = $lifecycle->next($domain);
            if ($next !== null) {
                $this->line("{$domain->fqdn}: status {$domain->status->value} → {$next->value} (dates)");
                if ($write) {
                    $lifecycle->advance($domain);
                }
            }
            if ($domain->status === DomainStatus::PendingTransfer && $domain->updated_at?->lt(now()->subDays(self::STALE_TRANSFER_DAYS))) {
                $this->warn("{$domain->fqdn}: pending transfer for more than " . self::STALE_TRANSFER_DAYS . ' days');
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
