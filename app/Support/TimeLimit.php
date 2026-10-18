<?php

namespace App\Support;

/**
 * Time for a long registrar call under a lock, so `finally` releases it. set_time_limit() alone would also put a
 * limit where there is none: the CLI (cron, a queue worker, phpunit) runs unlimited, and a limit set there counts for
 * the rest of the process, so it killed a worker, or the test suite, minutes after the call was done.
 */
final class TimeLimit
{
    /** At least $seconds from now, where the process has a limit; none stays none. */
    public static function atLeast(int $seconds): void
    {
        if ((int) ini_get('max_execution_time') === 0) {
            return;
        }
        set_time_limit($seconds);
    }
}
