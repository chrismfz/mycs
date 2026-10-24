<?php

namespace Tests\Concerns;

/**
 * How the row-lock tests' forked workers end and are waited for. A child that ended with exit() ran the forked
 * PHPUnit/paratest process's shutdown functions and destructors, and the parent waited for it without a limit: the
 * MariaDB CI job hung for hours on its last tests (#428, 2026-10-24).
 */
trait ForksWorkers
{
    /**
     * In the child: marks how it ended, then becomes a shell that exits with $code (pcntl_exec: nothing of the forked
     * PHP process runs, and posix is not always installed).
     */
    protected static function endWorker(string $dir, int $index, int $code): never
    {
        touch("$dir/exit-$index-$code");
        try {
            pcntl_exec('/bin/sh', ['-c', 'exit ' . $code]);
        } finally {
            // Only if the exec failed. Laravel turns its warning into an ErrorException: without the finally, the
            // forked child would unwind into the test (deleting the shared dir) and go on running the suite.
            exit($code);
        }
    }

    /** In the parent: whether the worker ended well, never waiting past $seconds (then it is killed and fails). */
    protected function awaitWorker(int $pid, string $dir, int $index, int $seconds = 60): bool
    {
        $deadline = microtime(true) + $seconds;
        while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            if (microtime(true) > $deadline) {
                self::killWorker($pid);
                if (!file_exists("$dir/error-$index")) {
                    file_put_contents("$dir/error-$index", "Worker $index did not finish within {$seconds}s.");
                }

                return false;
            }
            usleep(10_000);
        }

        return pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0 && file_exists("$dir/exit-$index-0");
    }

    /**
     * In the parent's finally: kills and reaps the workers still running (an assertion or a waitFor failed first).
     * Without posix_kill(), which is not always installed: calling it there threw and hid the real failure.
     *
     * @param  list<int>  $pids
     */
    protected static function reapWorkers(array $pids): void
    {
        foreach ($pids as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                self::killWorker($pid);
            }
        }
    }

    private static function killWorker(int $pid): void
    {
        exec('kill -9 ' . $pid);
        pcntl_waitpid($pid, $status);
    }
}
