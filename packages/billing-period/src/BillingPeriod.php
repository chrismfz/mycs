<?php

namespace MyCS\BillingPeriod;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Billing-period arithmetic (MyCS docs/billing-cursor.md). Month and year cycles keep the billing
 * day: a service on the 31st bills on the 28th/29th/30th in short months and back on the 31st after
 * them, instead of drifting to the 28th for good. Periods are whole days (the invoice line stores
 * dates), so only day, week, month and year cycles exist.
 */
final class BillingPeriod
{
    public const UNITS = ['day', 'week', 'month', 'year'];

    /**
     * End of the period that starts on $start (= start of the next one).
     *
     * @param  int|null  $anchorDay  day of month the service bills on; applied only to a period that
     *                               starts on it (or on the day it was clamped to in a short month)
     */
    public static function end(CarbonInterface $start, ?string $unit, int $count, ?int $anchorDay = null): CarbonImmutable
    {
        if (!in_array($unit, self::UNITS, true)) {
            throw new InvalidArgumentException('Unsupported billing unit «' . ($unit ?? 'null') . '»: periods are whole days.');
        }
        if ($count < 1) {
            throw new InvalidArgumentException("A billing period must be at least 1 {$unit}, got {$count}.");
        }

        $start = CarbonImmutable::instance($start)->startOfDay();

        if ($unit === 'day') {
            return $start->addDays($count);
        }
        if ($unit === 'week') {
            return $start->addWeeks($count);
        }

        $end = $start->addMonthsNoOverflow($unit === 'year' ? 12 * $count : $count);
        $anchor = $anchorDay !== null && $start->day === min($anchorDay, $start->daysInMonth) ? $anchorDay : $start->day;

        return $end->setDay(min($anchor, $end->daysInMonth));
    }

    /** Prorate in decimal, rounding once to cents. Full cycles retain their recurring amount. */
    public static function prorate(string $price, CarbonInterface $start, CarbonInterface $end, CarbonInterface $fullEnd): string
    {
        $days = (int) CarbonImmutable::instance($start)->startOfDay()->diffInDays(CarbonImmutable::instance($end)->startOfDay());
        $fullDays = (int) CarbonImmutable::instance($start)->startOfDay()->diffInDays(CarbonImmutable::instance($fullEnd)->startOfDay());
        if ($days < 1 || $fullDays < 1) {
            throw new InvalidArgumentException('A priced period must have a positive duration.');
        }

        return (string) BigDecimal::of($price)->multipliedBy($days)->dividedBy($fullDays, 2, RoundingMode::HalfUp);
    }

    /** Last day of a month shorter than 31 days: it may be a later billing day clamped. */
    public static function isAmbiguousDay(CarbonInterface $day): bool
    {
        return $day->day === $day->daysInMonth && $day->daysInMonth < 31;
    }
}
