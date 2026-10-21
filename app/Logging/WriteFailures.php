<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger as Monolog;
use Throwable;

/**
 * A log that cannot be written (a full disk: Monolog throws "Writing to the log file failed"; a file owned by another
 * user) never changes what the code does, and is not lost in silence either: the failure is kept here, and the cron
 * mails it to staff (CronJob, notice `log_not_written`).
 *
 * The tap of the `stack` channel (config/logging.php): each handler's write failure is caught and kept. report() is
 * covered also on a channel without the tap (ErrorHandler::reportThrowable).
 */
final class WriteFailures
{
    /** The first failure since forget(): what broke first is what staff need to see. */
    private static ?string $first = null;

    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if ($monolog instanceof Monolog) {
            $monolog->setHandlers(array_map(fn (HandlerInterface $handler) => $handler instanceof WriteFailuresHandler ? $handler : new WriteFailuresHandler($handler), $monolog->getHandlers()));
        }
    }

    public static function record(Throwable $e): void
    {
        self::$first ??= $e::class . ': ' . $e->getMessage();
    }

    public static function first(): ?string
    {
        return self::$first;
    }

    public static function forget(): void
    {
        self::$first = null;
    }
}
