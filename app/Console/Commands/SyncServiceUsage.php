<?php

namespace App\Console\Commands;

use App\Contracts\ReadsServiceUsage;
use App\Helpers\ExtensionHelper;
use App\Models\Service;
use App\Models\ServiceUsage;
use App\Support\Redact;
use Illuminate\Console\Command;
use Throwable;

/**
 * Every live service's usage (owner 2026-10-22: WHMCS's «Usage Statistics», plain accounts and resellers alike), read
 * from its server in one go per server and kept with the time it was read. Reads only; a server that fails is told and
 * skipped, its services keep what was last read (with its time).
 */
class SyncServiceUsage extends Command
{
    protected $signature = 'services:usage {--service= : one service (its id)}';

    protected $description = 'Read the disk and bandwidth usage of every live service from its server (cPanel)';

    public function handle(): int
    {
        $failed = false;
        $services = Service::query()->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])
            ->when($this->option('service'), fn ($q, $id) => $q->whereKey((int) $id))
            ->with(['server.settings', 'product.server.settings', 'properties', 'product.settings'])->get();
        foreach ($services->groupBy(fn (Service $service) => $service->provisioningServer()->id ?? 0) as $serverId => $group) {
            $server = $group->first()->provisioningServer();
            if ($serverId === 0 || $server === null) {
                continue;
            }
            try {
                $extension = ExtensionHelper::getExtension('server', $server->extension, $server->settings);
                if (!$extension instanceof ReadsServiceUsage) {
                    continue;
                }
                $read = $extension->usage($group->values()->all());
            } catch (Throwable $e) {
                report($e);
                $this->error("{$server->name}: " . Redact::text($e->getMessage()));
                $failed = true; // told to the scheduler too (code review 6)

                continue;
            }
            foreach ($read as $serviceId => $usage) {
                ServiceUsage::query()->updateOrCreate(['service_id' => $serviceId], [
                    'disk_used_mb' => $usage->diskUsedMb, 'disk_limit_mb' => $usage->diskLimitMb,
                    'bandwidth_used_mb' => $usage->bandwidthUsedMb, 'bandwidth_limit_mb' => $usage->bandwidthLimitMb,
                    'accounts' => $usage->accounts, 'accounts_limit' => $usage->accountsLimit, 'read_at' => now(),
                ]);
            }
            $this->line("{$server->name}: " . count($read) . ' of ' . $group->count() . ' service(s) read');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
