<?php

namespace App\Console;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Stringable;

/**
 * A scheduled command that fails says why (nixpal 2026-11-16): Laravel logs only «failed with exit code [1]», its output
 * gone, so staff had to look for the reason in the data. Each command's output is kept (the run's own file, overwritten
 * each run) and, on a failure, its last lines go to the log.
 */
class ScheduleFailureOutput
{
    /** The last lines and characters logged: the reason is at the end, and a long output stays out. */
    public const LINES = 20;

    public const CHARS = 2000;

    public static function apply(Schedule $schedule): void
    {
        foreach ($schedule->events() as $event) {
            if ($event instanceof CallbackEvent) {
                continue; // a closure has no output; its exception is logged as ever
            }
            $event->onFailureWithOutput(function (Stringable $output) use ($event) {
                Log::error('Scheduled command failed: ' . ($event->description ?: $event->command), [
                    'exit_code' => $event->exitCode,
                    'output' => self::tail((string) $output),
                ]);
            });
            // The last run's file out first (review of #883): one left by another user (a `schedule:run` as root) would
            // fail the shell's redirect, so the command would never run; and a failure never shows an older run's output.
            // The app owns storage/logs, so it may remove a file it cannot write.
            $event->before(function () use ($event) {
                if (is_file($event->output)) {
                    @unlink($event->output);
                }
            });
        }
    }

    public static function tail(string $output): string
    {
        $lines = array_slice(preg_split('/\R/', rtrim($output)) ?: [], -self::LINES);

        return mb_substr(implode("\n", $lines), -self::CHARS);
    }
}
