<?php

namespace App\Console\Commands;

use App\Services\Service\OwedCreates;
use Illuminate\Console\Command;

/** Paid services still pending with no create recorded (predeploy S4): staff are told (OwedCreates). */
class OwedCreatesCommand extends Command
{
    protected $signature = 'app:owed-creates';

    protected $description = 'Tells staff of paid services still waiting for their account';

    public function handle(): int
    {
        $this->info(OwedCreates::sweep() . ' told to staff.');

        return self::SUCCESS;
    }
}
