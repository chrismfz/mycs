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
    protected $signature = 'app:cron-wait {--timeout=900 : Seconds to wait for a run in progress}
        {--release-stale : Clear the lock of a run that crashed (only when no cron process is running)}';

    protected $description = 'Waits for a cron run in progress to finish (deploy/update.sh)';

    public function handle(): int
    {
        $timeout = max(0, (int) $this->option('timeout'));
        $lock = Cache::lock('app:cron-job', 60);
        // A run killed mid-way (OOM, reboot) leaves its lock up to two hours: cleared only with no cron process alive
        // (ADV-CW-3).
        if ($this->option('release-stale')) {
            if (self::cronAlive()) {
                $this->error('A cron process is running: its lock is not stale. Wait for it.');

                return self::FAILURE;
            }
            $lock->forceRelease();
            $this->info('The cron lock is cleared.');

            return self::SUCCESS;
        }
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
            $this->line((self::cronAlive() ? 'A cron process is running: wait for it.' : 'No cron process is running: the lock is a crashed run\'s.')
                . ' A crashed run\'s lock is cleared with: php artisan app:cron-wait --release-stale (refused while a cron process runs).');

            return self::FAILURE;
        }
    }

    /** A process of the cron's command alive on this host (pgrep; unknown counts as alive, never clearing a live run's). */
    private static function cronAlive(): bool
    {
        $out = [];
        $code = 1;
        // «[a]rtisan»: the pattern does not match the shell that runs pgrep with it. Options before the command, or
        // its abbreviation, too (R2-3: `artisan -v app:cron-job`).
        @exec('pgrep -f ' . escapeshellarg('[a]rtisan( .*)? app:cron-j'), $out, $code);

        return $code !== 1; // 0: found; 1: none; anything else (no pgrep): assume one
    }
}
