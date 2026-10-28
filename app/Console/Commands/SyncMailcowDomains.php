<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Mailcow\DomainSync;
use App\Support\Redact;
use Illuminate\Console\Command;
use Throwable;

/** Every mailcow server's domains and mailboxes read again (Mail Domains); a server that fails keeps what it had. */
class SyncMailcowDomains extends Command
{
    protected $signature = 'mailcow:sync {--server= : one server (its id)}';

    protected $description = 'Read the domains and mailboxes of every mailcow server (read-only)';

    public function handle(): int
    {
        $failed = false;
        $servers = DomainSync::servers()->when($this->option('server'), fn ($c, $id) => $c->where('id', (int) $id));
        if ($this->option('server') && $servers->isEmpty()) {
            $this->error('No enabled mailcow server with that id.'); // review of #492: never a silent success

            return self::FAILURE;
        }
        foreach ($servers as $server) {
            /** @var Server $server */
            try {
                $result = DomainSync::sync($server);
            } catch (Throwable $e) {
                report($e);
                $this->error("{$server->name}: " . Redact::text($e->getMessage()));
                $failed = true;

                continue;
            }
            $this->line("{$server->name}: {$result['seen']} domain(s), {$result['new']} new, {$result['gone']} gone, {$result['mailboxes']} mailbox(es)");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
