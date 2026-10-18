<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * The registrar debug log (a connection's switch) holds contacts' names and addresses: its files go after DAYS days,
 * also once the switch is off (the log's own rotation prunes only when it writes: review 1 of #193).
 */
class RegistrarDebugLogPrune extends Command
{
    public const DAYS = 14;

    protected $signature = 'mycs:registrar-debug-log-prune {--days=' . self::DAYS . ' : Files older than this many days}';

    protected $description = 'Delete registrar debug log files (contacts\' details in them) after 14 days';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $before = now()->subDays($days)->getTimestamp();
        $deleted = 0;
        foreach (glob(storage_path('logs/registrar-debug*.log')) ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $before && @unlink($file)) {
                $deleted++;
            }
        }
        $this->info("{$deleted} registrar debug log file(s) older than {$days} days deleted.");

        return self::SUCCESS;
    }
}
