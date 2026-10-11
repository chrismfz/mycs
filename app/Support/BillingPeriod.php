<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Billing-period arithmetic (docs/billing-cursor.md). Month and year cycles keep the anchor day:
 * a service started on the 31st bills on the 28th/29th/30th in short months and back on the 31st
 * after them, instead of drifting to the 28th for good.
 */
final class BillingPeriod
{
    /**
     * End of the period that starts on $start (= start of the next one).
     *
     * @param  string  $unit  hour | day | week | month | year
     * @param  int|null  $anchorDay  day of month the service bills on (default: $start's day)
     */
    public static function end(CarbonInterface $start, string $unit, int $count, ?int $anchorDay = null): CarbonImmutable
    {
        if ($unit === 'hour') {
            return CarbonImmutable::instance($start)->addHours($count); // legacy plans; not day-based
        }
        if (!in_array($unit, ['day', 'week', 'month', 'year'], true)) {
            throw new InvalidArgumentException("Unsupported billing unit «{$unit}».");
        }

        $start = CarbonImmutable::instance($start)->startOfDay();

        if ($unit === 'day') {
            return $start->addDays($count);
        }
        if ($unit === 'week') {
            return $start->addWeeks($count);
        }

        $months = $unit === 'year' ? 12 * $count : $count;
        $end = $start->addMonthsNoOverflow($months);
        // The anchor only applies to a period that starts on it (the 31st, or the 28th/30th it was
        // clamped to); a period starting on another day bills on that day.
        $anchor = $anchorDay !== null && $start->day === min($anchorDay, $start->daysInMonth) ? $anchorDay : $start->day;

        return $end->setDay(min($anchor, $end->daysInMonth));
    }
}
