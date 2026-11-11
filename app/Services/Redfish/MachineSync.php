<?php

namespace App\Services\Redfish;

use App\Helpers\ExtensionHelper;
use App\Models\RedfishMachine;
use App\Models\Server;
use App\Support\Redact;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use MyCS\Extensions\Servers\Redfish\Redfish;
use RuntimeException;
use Throwable;

/**
 * Utilities → Machines: each Redfish server's BMC read with its parts (fans, temperatures, power supplies, processors,
 * memory, drives) and kept, the last read only. Reads only. A machine that cannot be read keeps what it had, with why
 * and when it failed. Nothing is told to anyone: the list shows it (owner 2026-11-11: an indication only, for now).
 */
final class MachineSync
{
    /**
     * @param  float  $budget  seconds for the parts (a page's request waits less than the cron)
     *
     * @throws Throwable when the BMC could not be read (kept on the row too)
     */
    public static function sync(Server $server, float $budget = 60.0): RedfishMachine
    {
        $extension = ExtensionHelper::getExtension('server', $server->extension, $server->settings);
        if (!$extension instanceof Redfish) {
            throw new \InvalidArgumentException("{$server->name} is not a Redfish machine.");
        }
        $lock = Cache::lock("redfish.sync.{$server->id}", 300);
        if (!$lock->get()) {
            throw new RuntimeException("{$server->name} is being read already: try again in a minute.");
        }
        try {
            $row = RedfishMachine::query()->firstOrNew(['server_id' => $server->id]);
            try {
                $machine = $extension->hardware(parts: true, budget: $budget);
            } catch (Throwable $e) {
                $row->fill(['failed_at' => now(), 'error' => Str::limit(Redact::text($e->getMessage()), 480)])->save();
                throw $e;
            }
            $inlet = $machine->inletCelsius();
            $row->fill([
                'power_state' => $machine->powerState === null ? null : Str::limit($machine->powerState, 32, ''),
                'health' => $machine->health,
                'watts' => $machine->watts === null ? null : (int) round($machine->watts),
                'inlet_celsius' => $inlet === null ? null : (int) round(min($inlet, 999)),
                'attention' => implode("\n", $machine->attention()) ?: null,
                'data' => $machine->toArray(),
                'read_at' => now(), 'failed_at' => null, 'error' => null,
            ])->save();

            return $row;
        } finally {
            $lock->release();
        }
    }

    /** A row for every Redfish server, read or not yet: a machine just added is on the list before its first read. */
    public static function ensureRows(): void
    {
        $have = RedfishMachine::query()->pluck('server_id')->all();
        foreach (Server::query()->where('extension', 'Redfish')->whereNotIn('id', $have)->pluck('id') as $id) {
            RedfishMachine::query()->firstOrCreate(['server_id' => $id]);
        }
    }

    /** @return Collection<int, Server> */
    public static function servers(): Collection
    {
        return Server::query()->where('extension', 'Redfish')->where('enabled', true)->with('settings')->orderBy('name')->get();
    }
}
