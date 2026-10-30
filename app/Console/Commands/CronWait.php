<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Waits for a run of the cron in progress (its lock, CronJob::handle) before a deploy changes the code and the database
 * under it (sweep 9γ, owner 2026-10-30): the scheduler starts none in maintenance mode, but one already running would go
 * on half in the old code, half in the new, over a migrating database. Exits 0 once it is free (taken and released at
 * once), 1 when it is still held after --timeout seconds: the deploy stops there.
 */
class CronWait extends Command
{
    protected $signature = 'app:cron-wait {--timeout=900 : Seconds to wait for a run in progress}';

    protected $description = 'Waits for a cron run in progress to finish (deploy/update.sh)';

    public function handle(): int
    {
        $timeout = max(0, (int) $this->option('timeout'));
        $lock = Cache::lock('app:cron-job', 60);
        if ($lock->get()) {
            $lock->release();

            return self::SUCCESS;
        }
        $this->line("A cron run is in progress: waiting for it (up to {$timeout}s)…");
        try {
            $lock->block($timeout);
            $lock->release();
            $this->info('The cron run is over.');

            return self::SUCCESS;
        } catch (LockTimeoutException) {
            $this->error("A cron run is still in progress after {$timeout}s (or the lock of one that crashed, for up to two hours).");

            return self::FAILURE;
        }
    }
}
