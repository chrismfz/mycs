<?php

namespace App\Console\Commands\Domains;

use App\Models\DomainTld;
use App\Services\Domains\Pricing\TldCostSync;
use Illuminate\Console\Command;
use Throwable;

/**
 * The registrars' costs onto the TLDs' prices (docs/domains.md D-6): costs only, never a price a customer pays. Weekly
 * from the scheduler; by hand too. A TLD its registrar cannot price (manual, off) is skipped; one failure never stops
 * the rest.
 */
class SyncCosts extends Command
{
    protected $signature = 'domains:sync-costs {--tld= : one TLD only}';

    protected $description = 'Read the registrars\' costs for the TLDs (never changes a price)';

    public function handle(TldCostSync $sync): int
    {
        $query = DomainTld::query()->where('is_active', true)->with('registrarConnection');
        if ($this->option('tld')) {
            $query->where('tld', ltrim((string) $this->option('tld'), '.'));
        }
        $failed = 0;
        foreach ($query->get() as $tld) {
            if ($sync->connection($tld) === null) {
                continue;
            }
            try {
                $this->line(".{$tld->tld}: " . $sync->sync($tld) . ' cost(s) changed');
            } catch (Throwable $e) {
                $failed++;
                $this->error(".{$tld->tld}: " . $e->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
