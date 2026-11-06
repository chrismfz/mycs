<?php

namespace App\Console\Commands;

use App\Services\Ops\CronSummary;
use Illuminate\Console\Command;

/**
 * The daily summary email (App\Services\Ops\CronSummary): scheduled at «Send at» (Settings → Automation → Daily Summary
 * Email); it sends as the settings say. --force sends it now whatever they say, and leaves the days to be covered.
 */
class CronSummaryCommand extends Command
{
    protected $signature = 'app:cron-summary {--force : Send it now, whatever the mode and the day (the days stay to be covered)}';

    protected $description = 'Emails staff the daily summary of the cron and what it suspends next (as the settings say)';

    public function handle(): int
    {
        $this->info(CronSummary::run(force: (bool) $this->option('force')));

        return self::SUCCESS;
    }
}
