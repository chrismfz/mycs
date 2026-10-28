<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Cpanel\AccountSync;
use App\Services\Cpanel\LveSync;
use App\Support\Redact;
use Illuminate\Console\Command;
use Throwable;

/** Every WHM server's accounts' CloudLinux usage read (Hosting Accounts, the service's page); a server that fails keeps what it had. */
class SyncCpanelLve extends Command
{
    protected $signature = 'cpanel:lve {--server= : one server (its id)}';

    protected $description = 'Read what each cPanel account uses of its CloudLinux limits (read-only)';

    public function handle(): int
    {
        $failed = false;
        $servers = AccountSync::servers()->when($this->option('server') !== null, fn ($c) => $c->where('id', (int) $this->option('server')));
        if ($this->option('server') !== null && $servers->isEmpty()) {
            $this->error('No enabled cPanel server with that id.');

            return self::FAILURE;
        }
        foreach ($servers as $server) {
            /** @var Server $server */
            try {
                $result = LveSync::sync($server);
            } catch (Throwable $e) {
                report($e);
                $this->error("{$server->name}: " . Redact::text($e->getMessage()));
                $failed = true;

                continue;
            }
            $this->line("{$server->name}: " . ($result['cloudlinux'] ? "{$result['read']} account(s) read" : 'no CloudLinux'));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
