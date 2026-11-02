<?php

namespace App\Console\Commands;

use App\Services\Service\OwedCreates;
use Illuminate\Console\Command;

/** Paid services still pending whose create never reached the queue (predeploy S4): asked for again (OwedCreates). */
class OwedCreatesCommand extends Command
{
    protected $signature = 'app:owed-creates';

    protected $description = 'Asks again for the accounts of paid services whose create never reached the queue';

    public function handle(): int
    {
        $this->info(OwedCreates::sweep() . ' asked for again.');

        return self::SUCCESS;
    }
}
