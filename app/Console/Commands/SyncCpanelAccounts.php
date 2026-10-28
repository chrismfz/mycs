<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Cpanel\AccountSync;
use App\Support\Redact;
use Illuminate\Console\Command;
use Throwable;

/** Every WHM server's accounts read again (Hosting Accounts); a server that fails keeps what it had. */
class SyncCpanelAccounts extends Command
{
    protected $signature = 'cpanel:sync {--server= : one server (its id)}';

    protected $description = 'Read the accounts of every cPanel server (read-only)';

    public function handle(): int
    {
        $failed = false;
        $servers = AccountSync::servers()->when($this->option('server') !== null, fn ($c) => $c->where('id', (int) $this->option('server')));
        if ($this->option('server') !== null && $servers->isEmpty()) {
            $this->error('No enabled cPanel server with that id.'); // review of #492: never a silent success

            return self::FAILURE;
        }
        foreach ($servers as $server) {
            /** @var Server $server */
            try {
                $result = AccountSync::sync($server);
            } catch (Throwable $e) {
                report($e);
                $this->error("{$server->name}: " . Redact::text($e->getMessage()));
                $failed = true;

                continue;
            }
            $this->line("{$server->name}: {$result['seen']} account(s), {$result['new']} new, {$result['gone']} gone");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
