<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Cpanel\AccountSync;
use App\Services\Cpanel\LveSync;
use App\Support\Redact;
use Illuminate\Console\Command;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Every WHM server's accounts' CloudLinux usage read (Hosting Accounts, the service's page); a server that fails keeps
 * what it had. The servers read at once, each in its own process (owner 2026-10-28: the run as long as the biggest
 * server, not all their accounts), off the queue (its one worker is provisioning's).
 */
class SyncCpanelLve extends Command
{
    protected $signature = 'cpanel:lve {--server= : one server (its id)}';

    protected $description = 'Read what each cPanel account uses of its CloudLinux limits (read-only)';

    /** A server's read given this long (one call an account, a few hundred accounts). */
    public const SERVER_TIMEOUT = 3000;

    public function handle(): int
    {
        $servers = AccountSync::servers()->when($this->option('server') !== null, fn ($c) => $c->where('id', (int) $this->option('server')));
        if ($this->option('server') !== null && $servers->isEmpty()) {
            $this->error('No enabled cPanel server with that id.');

            return self::FAILURE;
        }
        $failed = $servers->count() > 1 ? $this->inParallel($servers->pluck('id')->all()) : $this->read($servers->first());
        LveSync::prune();

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** One server here; true when it failed. */
    private function read(?Server $server): bool
    {
        if ($server === null) {
            return false;
        }
        try {
            $result = LveSync::sync($server);
        } catch (Throwable $e) {
            report($e);
            $this->error("{$server->name}: " . Redact::text($e->getMessage()));

            return true;
        }
        $this->line("{$server->name}: " . match ($result['cloudlinux']) {
            true => "{$result['read']} account(s) read",
            false => 'no CloudLinux',
            null => 'nothing to read',
        });

        return false;
    }

    /**
     * Each server in its own `cpanel:lve --server=`, all at once; their lines told here. True when one failed.
     *
     * @param  list<int>  $ids
     */
    private function inParallel(array $ids): bool
    {
        try {
            $results = Process::pool(function (Pool $pool) use ($ids) {
                foreach ($ids as $id) {
                    $pool->as((string) $id)->path(base_path())->timeout(self::SERVER_TIMEOUT)
                        ->command([PHP_BINARY, 'artisan', 'cpanel:lve', '--server=' . $id]);
                }
            })->start()->wait();
        } catch (ProcessTimedOutException $e) {
            // A server's read past its time (the others' readings are saved by their own processes).
            report($e);
            $this->error('A server\'s read took longer than ' . self::SERVER_TIMEOUT . ' seconds: stopped.');

            return true;
        }

        $failed = false;
        $byServer = $results->collect();
        foreach ($ids as $id) {
            $result = $byServer->get((string) $id);
            if ($result === null) {
                continue;
            }
            foreach (array_filter(explode("\n", trim($result->output() . "\n" . $result->errorOutput()))) as $line) {
                $result->successful() ? $this->line($line) : $this->error($line);
            }
            if (!$result->successful() && trim($result->output() . $result->errorOutput()) === '') {
                $this->error("Server {$id}: its read stopped (exit {$result->exitCode()})"); // a timeout says nothing itself
            }
            $failed = $failed || !$result->successful();
        }

        return $failed;
    }
}
