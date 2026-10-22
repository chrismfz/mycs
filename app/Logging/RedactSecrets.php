<?php

namespace App\Logging;

use App\Support\LogText;
use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;

/** The `stack` channel's tap: laravel.log keeps no secret unless «Full logs» is on (App\Support\LogText). */
final class RedactSecrets
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(fn (LogRecord $record) => $record->with(
                message: (string) LogText::of($record->message),
                context: LogText::context($record->context),
            ));
        }
    }
}
