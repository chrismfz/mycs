<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Whether the app is in maintenance, by its own driver (file or cache): deploy/update.sh asks before it goes down, so a
 * failed second wait brings up only an app it put down itself (ADV-CW-4, R2-4). Exits 0 when down, 1 when up.
 */
class IsDown extends Command
{
    protected $signature = 'app:is-down';

    protected $description = 'Exits 0 when the app is in maintenance, 1 when it is up (deploy/update.sh)';

    public function handle(): int
    {
        return app()->isDownForMaintenance() ? self::SUCCESS : self::FAILURE;
    }
}
