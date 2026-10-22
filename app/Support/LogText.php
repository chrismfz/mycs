<?php

namespace App\Support;

use App\Services\Ops\Scrub;
use Throwable;

/**
 * Text kept or sent for staff (a module action's error, a system mail, an error row, laravel.log): its secrets masked
 * (stored ones, Authorization headers, secret URL keys, the app's own config), unless staff chose «Full logs» in the
 * settings, to see exactly what was sent (owner's decision, 2026-10-23: security audit PROV-2, SEC-6, SEC-9).
 *
 * Never fails and never logs: it runs inside the logger itself.
 */
final class LogText
{
    private static bool $busy = false;

    public static function full(): bool
    {
        try {
            return (bool) config('settings.logs_full', false);
        } catch (Throwable) {
            return false;
        }
    }

    public static function of(?string $text): ?string
    {
        if ($text === null || $text === '' || self::full() || self::$busy) {
            return $text;
        }
        self::$busy = true;
        try {
            return Scrub::text($text);
        } catch (Throwable) {
            return $text; // a store that is down (the stored secrets) never loses the line
        } finally {
            self::$busy = false;
        }
    }

    /**
     * @param  array<mixed>  $context
     * @return array<mixed>
     */
    public static function context(array $context): array
    {
        if (self::full()) {
            return $context;
        }
        try {
            $context = Redact::array($context);
        } catch (Throwable) {
            return $context;
        }

        // An exception is written from the object itself (its message and trace): as masked text instead.
        return array_map(fn ($value) => match (true) {
            is_string($value) => self::of($value),
            $value instanceof Throwable => self::of($value::class . ': ' . $value->getMessage() . ' in ' . $value->getFile() . ':' . $value->getLine()
                . "\n" . $value->getTraceAsString()),
            default => $value,
        }, $context);
    }
}
