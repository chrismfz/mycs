<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

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

    /**
     * Tests only: look for the cron among this process's own children. pgrep sees the whole host, where a parallel test
     * process's fake cron, another checkout's suite or the live site's hourly run would pass for this test's live run.
     */
    public static bool $ownChildrenOnly = false;

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
        $started = time();
        if ($lock->get()) {
            $lock->release();
        } else {
            $this->line("A cron run is in progress: waiting for it (up to {$timeout}s)…");
            try {
                $lock->block($timeout);
                $lock->release();
                $this->info('The cron run is over.');
            } catch (LockTimeoutException) {
                $this->error("A cron run is still in progress after {$timeout}s (or the lock of one that crashed, for up to two hours).");
                $this->line((self::cronAlive() ? 'A cron process is running: wait for it.' : 'No cron process is running: the lock is a crashed run\'s.')
                    . ' A crashed run\'s lock is cleared with: php artisan app:cron-wait --release-stale (refused while a cron process runs).');

                return self::FAILURE;
            }
        }

        return $this->waitForScheduledTasks(max(0, $timeout - (time() - $started)), $timeout);
    }

    /**
     * The other scheduled commands already running (sweep 2, F-SCHEDULER-QUEUE-1): bank:alerts records payments,
     * ssl:sync charges at the registrar, domains:sync writes the domains. `down` stops only new starts, so one that
     * began before it would go on in the old code over the migrations. Each holds its withoutOverlapping mutex while it
     * runs: waited for, in the time --timeout leaves, and with them the scheduler process that starts them (busy()).
     */
    private function waitForScheduledTasks(int $seconds, int $timeout): int
    {
        $busy = $this->busy();
        if ($busy === []) {
            return self::SUCCESS;
        }
        $this->line('Scheduled tasks in progress: ' . implode(', ', $busy) . ": waiting for them (up to {$seconds}s)…");
        for ($i = 0; $i < $seconds && $busy !== []; $i++) {
            Sleep::for(1)->second();
            $busy = $this->busy();
        }
        if ($busy === []) {
            $this->info('The scheduled tasks are over.');

            return self::SUCCESS;
        }
        $this->error("Scheduled tasks still in progress after {$timeout}s: " . implode(', ', $busy) . '.');
        if (in_array(self::SCHEDULER, $busy, true)) {
            $this->line('A `php artisan schedule:run` of this install is still running: it goes on starting the tasks that were'
                . ' due when it began. Wait for it (pgrep -af "artisan schedule:run"), or raise --timeout.');
        }
        if ($busy !== [self::SCHEDULER]) {
            $this->line('A task killed mid-way leaves its mark until its withoutOverlapping expiry. When none of them is running'
                . ' (no such artisan process), it is cleared with: php artisan schedule:clear-cache');
        }

        return self::FAILURE;
    }

    private const SCHEDULER = 'schedule:run (pending tasks)';

    /**
     * What a deploy must wait for: the tasks holding their mutex, and a schedule:run of this install still alive
     * (sweep 6, S6-OPS-1). The scheduler works out its due tasks once, at its start, and runs them one after another:
     * between two of them no mutex is held, and one that began before `down` starts the rest after it.
     *
     * @return list<string>
     */
    private function busy(): array
    {
        $busy = $this->runningTasks();
        if (self::schedulerAlive()) {
            $busy[] = self::SCHEDULER;
        }

        return $busy;
    }

    /**
     * A `artisan schedule:run` of this install alive, other than this process: its cwd here, or (a cwd this user cannot
     * read) this install's artisan named by its path, as deploy/update.sh finds its queue workers. Another install's
     * scheduler on the host is not ours to wait for. No pgrep: none seen, as before. Not seen either: a scheduler of
     * another user whose cwd this user cannot read, started as `cd <app> && php artisan schedule:run` (no path on its
     * command line): the deploy runs as the cron's user, or as root (docs/install.md).
     */
    private static function schedulerAlive(): bool
    {
        $pids = [];
        $scope = self::$ownChildrenOnly ? '-P ' . getmypid() . ' ' : '';
        @exec('pgrep ' . $scope . '-f ' . escapeshellarg('[a]rtisan( .*)? schedule:run'), $pids);
        $here = realpath(base_path()) ?: base_path();
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0 || $pid === getmypid()) {
                continue;
            }
            $cwd = @readlink("/proc/{$pid}/cwd");
            if (is_string($cwd)) {
                if ($cwd === $here) {
                    return true;
                }

                continue;
            }
            $cmd = str_replace("\0", ' ', (string) @file_get_contents("/proc/{$pid}/cmdline"));
            if (str_contains($cmd, $here . '/artisan ')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the scheduled commands whose withoutOverlapping mutex is held (a run in progress) */
    private function runningTasks(): array
    {
        $busy = [];
        foreach (app(Schedule::class)->events() as $event) {
            if ($event->withoutOverlapping && $event->mutex->exists($event)) {
                $busy[] = self::taskName($event);
            }
        }

        return array_values(array_unique($busy));
    }

    private static function taskName(Event $event): string
    {
        if (is_string($event->command) && preg_match("/artisan['\"]?\\s+(\\S+(?:\\s+--\\S+)*)/", $event->command, $m)) {
            return str_replace(["'", '"'], '', $m[1]);
        }

        return (string) ($event->description ?? $event->command);
    }

    /** A process of the cron's command alive on this host (pgrep; unknown counts as alive, never clearing a live run's). */
    private static function cronAlive(): bool
    {
        $out = [];
        $code = 1;
        // «[a]rtisan»: the pattern does not match the shell that runs pgrep with it. Options before the command, or
        // its abbreviation, too (R2-3: `artisan -v app:cron-job`).
        $scope = self::$ownChildrenOnly ? '-P ' . getmypid() . ' ' : '';
        @exec('pgrep ' . $scope . '-f ' . escapeshellarg('[a]rtisan( .*)? app:cron-j'), $out, $code);

        return $code !== 1; // 0: found; 1: none; anything else (no pgrep): assume one
    }
}
