<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * A failure while the app boots its settings or extensions (audit sweep KR-8, EVT-12). It used to be swallowed: a
 * database blip when a queue worker started left it running until restart on code defaults (suspend after 2 days,
 * credits off) and without the Stripe/PayPal listeners that cancel subscriptions.
 *
 * Always reported. Rethrown where running on defaults is worse than failing: web requests and the processes that
 * move money or live long (they are restarted by systemd/cron). Swallowed only for a fresh install (no table yet)
 * and for console commands that do not need the database (composer's package:discover, config:cache, ...).
 */
final class BootFailure
{
    /** Console commands that must not run on defaults. */
    private const STRICT_COMMANDS = ['queue:work', 'queue:listen', 'schedule:run', 'schedule:work', 'app:cron-job'];

    public static function handle(Throwable $e): void
    {
        if (self::isMissingTable($e)) {
            return; // a fresh install: migrations have not run yet
        }

        report($e);

        if (!app()->runningUnitTests() && self::mustFail(app()->runningInConsole(), $_SERVER['argv'][1] ?? null)) {
            throw $e;
        }
    }

    /** Fatal for a web request, and for the console commands that must not run on defaults. */
    public static function mustFail(bool $inConsole, ?string $command): bool
    {
        return !$inConsole || in_array($command, self::STRICT_COMMANDS, true);
    }

    private static function isMissingTable(Throwable $e): bool
    {
        return $e instanceof QueryException
            && preg_match('/Base table or view not found|no such table|doesn\'t exist/i', $e->getMessage()) === 1;
    }
}
