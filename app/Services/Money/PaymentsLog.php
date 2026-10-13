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

    /** @param  array<string, mixed>  $context */
    private static function write(string $level, string $message, array $context): void
    {
        $channels = array_values(array_unique([(string) config('logging.default'), 'payments']));
        Log::stack($channels)->log($level, $message, $context);
    }
}
