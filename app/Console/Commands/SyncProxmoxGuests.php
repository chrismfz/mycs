<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Proxmox\GuestSync;
use App\Support\Redact;
use Illuminate\Console\Command;
use Throwable;

/** Every Proxmox cluster's VMs and containers read again (Proxmox guests); a cluster that fails keeps what it had. */
class SyncProxmoxGuests extends Command
{
    protected $signature = 'proxmox:sync {--server= : one server (its id)}';

    protected $description = 'Read the VMs and containers of every Proxmox cluster (read-only)';

    public function handle(): int
    {
        $failed = false;
        $servers = GuestSync::servers()->when($this->option('server'), fn ($c, $id) => $c->where('id', (int) $id));
        foreach ($servers as $server) {
            /** @var Server $server */
            try {
                $result = GuestSync::sync($server);
            } catch (Throwable $e) {
                report($e);
                $this->error("{$server->name}: " . Redact::text($e->getMessage()));
                $failed = true;

                continue;
            }
            $this->line("{$server->name}: {$result['seen']} guest(s), {$result['new']} new, {$result['gone']} gone");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
