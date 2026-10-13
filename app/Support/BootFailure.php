<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use PDOException;
use Symfony\Component\Console\Input\ArgvInput;
use Throwable;

/**
 * A failure while the app boots its settings or extensions (audit sweep KR-8, EVT-12). It used to be swallowed: a
 * database blip when a queue worker started left it running until restart on code defaults (suspend after 2 days,
 * credits off) and without the Stripe/PayPal listeners that cancel subscriptions. (AppServiceProvider boots the
 * extensions before SettingsProvider loads the settings: with the database down, the extensions query fails first.)
 *
 * A database failure is fatal where running on defaults is worse than failing: web requests (not in maintenance:
 * the 503 page must still answer while the database is worked on) and the processes that move money or live long
 * (systemd/cron restart them). Anything else is reported and the app goes on: a bad stored value (a timezone, a
 * theme) must not lock staff out of the settings page that fixes it. A fresh install (no table yet) is no failure.
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

        $fatal = self::isDatabaseFailure($e) && !app()->runningUnitTests()
            && self::mustFail(app()->runningInConsole(), self::downForMaintenance(), self::command());
        if ($fatal) {
            throw $e; // reported once, by the exception handler
        }

        report($e);
    }

    /** Fatal for a web request (unless the app is down for maintenance), and for the commands that must not run on defaults. */
    public static function mustFail(bool $inConsole, bool $downForMaintenance, ?string $command): bool
    {
        return $inConsole ? in_array($command, self::STRICT_COMMANDS, true) : !$downForMaintenance;
    }

    /** The artisan command, wherever it stands among the options (`artisan -v queue:work`). */
    public static function command(?array $argv = null): ?string
    {
        return (new ArgvInput($argv ?? ($_SERVER['argv'] ?? [])))->getFirstArgument();
    }

    /**
     * The maintenance file, read directly: the settings can load while the panels register (AdminPanelProvider),
     * before the maintenance-mode driver is usable.
     */
    private static function downForMaintenance(): bool
    {
        if (is_file(app()->storagePath('framework/down'))) {
            return true;
        }
        try {
            return app()->isDownForMaintenance();
        } catch (Throwable) {
            return false;
        }
    }

    private static function isDatabaseFailure(Throwable $e): bool
    {
        return $e instanceof QueryException || $e instanceof PDOException;
    }

    /** MariaDB 1146 / SQLSTATE 42S02, SQLite «no such table»: not «doesn't exist in engine» (1932, a corrupt table). */
    private static function isMissingTable(Throwable $e): bool
    {
        if (!$e instanceof QueryException) {
            return false;
        }

        return ($e->errorInfo[1] ?? null) === 1146 || str_contains($e->getMessage(), 'no such table');
    }
}
