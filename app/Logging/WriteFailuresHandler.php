<?php

namespace App\Logging;

use Monolog\Handler\HandlerWrapper;
use Monolog\LogRecord;
use Throwable;

/** @internal WriteFailures' wrapper around one handler. */
final class WriteFailuresHandler extends HandlerWrapper
{
    public function handle(LogRecord $record): bool
    {
        try {
            return $this->handler->handle($record);
        } catch (Throwable $e) {
            WriteFailures::record($e);

            return false;
        }
    }

    public function handleBatch(array $records): void
    {
        try {
            $this->handler->handleBatch($records);
        } catch (Throwable $e) {
            WriteFailures::record($e);
        }
    }
}
