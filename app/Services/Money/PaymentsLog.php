<?php

namespace App\Services\Money;

use Illuminate\Support\Facades\Log;

/**
 * Money worth a look in the logs (docs/money-trail.md §6): written to the app's log as before, and to the «payments»
 * channel, kept a year (the app's log is kept 14 days).
 */
final class PaymentsLog
{
    /** @param  array<string, mixed>  $context */
    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /** @param  array<string, mixed>  $context */
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    /**
     * Never throws: a log that cannot be written (its file made by another user, a full disk) must not fail a payment
     * or the money check. Then the app's own log, and the error is reported.
     *
     * @param  array<string, mixed>  $context
     */
    private static function write(string $level, string $message, array $context): void
    {
        try {
            Log::stack(array_values(array_unique([(string) config('logging.default'), 'payments'])))->log($level, $message, $context);
        } catch (\Throwable $e) {
            try {
                Log::log($level, $message, $context);
                report($e);
            } catch (\Throwable) {
                // nowhere to write: the money itself is in the database and the journal
            }
        }
    }
}
