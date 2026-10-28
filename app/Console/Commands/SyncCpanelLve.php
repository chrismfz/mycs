<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Cpanel\AccountSync;
use App\Services\Cpanel\LveSync;
use App\Support\Redact;
use Illuminate\Console\Command;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Cache;
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

    /** The parent's own deadline for a child (SERVER_TIMEOUT; a test sets it shorter). */
    public static int $deadline = self::SERVER_TIMEOUT;

    public function handle(): int
    {
        $servers = AccountSync::servers()->when($this->option('server') !== null, fn ($c) => $c->where('id', (int) $this->option('server')));
        if ($this->option('server') !== null && $servers->isEmpty()) {
            $this->error('No enabled cPanel server with that id.');

            return self::FAILURE;
        }
        $failed = $servers->count() > 1 ? $this->inParallel($servers->pluck('id')->all()) : $this->read($servers->first());
        // The parent's, or a run by hand of all: never each child's (nine deletes at once: review 2 of #497).
        if ($this->option('server') === null) {
            LveSync::prune();
        }

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
     * Each server in its own `cpanel:lve --server=`, all at once, watched together (each one's output read as it comes,
     * none waiting on another's: review 2 of #497); one past its time stopped, named, and its lock let go. Their lines
     * told here. True when one failed.
     *
     * @param  list<int>  $ids
     */
    private function inParallel(array $ids): bool
    {
        $pool = Process::pool(function (Pool $pool) use ($ids) {
            foreach ($ids as $id) {
                $pool->as((string) $id)->path(base_path())->forever()
                    ->command([PHP_BINARY, 'artisan', 'cpanel:lve', '--server=' . $id]);
            }
        })->start();
        $started = microtime(true);
        $stopped = [];
        while ($pool->running()->isNotEmpty()) {
            if (microtime(true) - $started >= self::$deadline) {
                foreach ($pool as $id => $process) {
                    if ($process->running()) {
                        $process->stop(5);
                        $stopped[] = (string) $id;
                        Cache::lock(LveSync::lockName((int) $id))->forceRelease();
                    }
                }

                break;
            }
            usleep(200_000);
        }

        $names = Server::query()->whereIn('id', $ids)->pluck('name', 'id');
        $failed = false;
        foreach ($pool as $id => $process) {
            $name = $names[(int) $id] ?? "Server {$id}";
            if (in_array((string) $id, $stopped, true)) {
                report(new \RuntimeException("cpanel:lve: {$name}'s read took longer than " . self::$deadline . ' seconds: stopped.'));
                $this->error("{$name}: its read took longer than " . self::$deadline . ' seconds: stopped.');
                $failed = true;

                continue;
            }
            $result = $process->wait();
            foreach (array_filter(explode("\n", trim($result->output() . "\n" . $result->errorOutput()))) as $line) {
                $result->successful() ? $this->line($line) : $this->error($line);
            }
            if (!$result->successful() && trim($result->output() . $result->errorOutput()) === '') {
                $this->error("{$name}: its read stopped (exit {$result->exitCode()})");
            }
            $failed = $failed || !$result->successful();
        }

        return $failed;
    }
}
