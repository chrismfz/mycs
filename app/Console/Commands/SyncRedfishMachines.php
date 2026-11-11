<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Redfish\MachineSync;
use App\Support\Redact;
use Illuminate\Console\Command;
use Throwable;

/** Every Redfish machine's BMC read again (Utilities → Machines); one that fails keeps what it had, with why. */
class SyncRedfishMachines extends Command
{
    protected $signature = 'redfish:sync {--server= : one server (its id)}';

    protected $description = 'Read the BMC of every Redfish machine: health, power, parts (read-only)';

    public function handle(): int
    {
        $failed = false;
        $servers = MachineSync::servers()->when($this->option('server'), fn ($c, $id) => $c->where('id', (int) $id));
        foreach ($servers as $server) {
            /** @var Server $server */
            try {
                $row = MachineSync::sync($server);
            } catch (Throwable $e) {
                // A BMC that is off the network is no error of ours: said, not reported.
                $this->error("{$server->name}: " . Redact::text($e->getMessage()));
                $failed = true;

                continue;
            }
            $this->line("{$server->name}: " . ($row->power_state ?? '?') . ', health ' . ($row->health ?? '?')
                . ($row->watts !== null ? ", {$row->watts} W" : '') . ($row->attention !== null ? ', look at: ' . str_replace("\n", '; ', $row->attention) : ''));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
