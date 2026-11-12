<?php

namespace MyCS\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * What a period of a quantity that changes within it costs (MyCS docs/zonecloud-bulk-license.md §6.1): a bulk license's
 * IPs, priced by Tiers for a whole month.
 *
 * - IP-days: a unit counts for every calendar day it was there at all; each day costs cost(units that day) / days of the
 *   cycle. Added and removed the same day is one day.
 * - Peak: the most units on any day, for the days of the period.
 *
 * Exact sums, rounded half-up to cents once. Days are those of the timezone the period's start is given in; the
 * intervals' instants are compared as instants.
 */
final class UsageCharge
{
    public const IP_DAYS = 'ip_days';

    public const PEAK = 'peak';

    /**
     * Units on each day from $start (inclusive) to $end (exclusive), both midnights.
     *
     * @param  iterable<array{0: DateTimeInterface, 1: DateTimeInterface|null}>  $intervals  [added, removed or null]
     * @return array<string, int> Y-m-d => units
     */
    public static function dailyCounts(iterable $intervals, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        // To the microsecond, and in one timezone: else a day of the next period is counted in this one (ADV-820-1, -2).
        if ($start->format('H:i:s.u') !== '00:00:00.000000' || $end->format('H:i:s.u') !== '00:00:00.000000') {
            throw new InvalidArgumentException('A period runs from midnight to midnight.');
        }
        if ($start->getTimezone()->getName() !== $end->getTimezone()->getName()) {
            throw new InvalidArgumentException('A period starts and ends in the same timezone.');
        }
        if ($end <= $start) {
            throw new InvalidArgumentException('A period must have at least one day.');
        }
        $spans = [];
        foreach ($intervals as [$added, $removed]) {
            // Never there: removed when (or before) it was added.
            if ($removed === null || $removed > $added) {
                $spans[] = [$added, $removed];
            }
        }

        $counts = [];
        for ($day = $start; $day < $end; $day = $next) {
            $next = $day->modify('+1 day');
            $units = 0;
            foreach ($spans as [$added, $removed]) {
                if ($added < $next && ($removed === null || $removed > $day)) {
                    $units++;
                }
            }
            $counts[$day->format('Y-m-d')] = $units;
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $dailyCounts  from dailyCounts()
     * @param  int  $cycleDays  days of the whole cycle the period is part of (31 for a whole October, and for a first
     *                          period from 20 October to 1 November too)
     * @return string the charge, net, in cents
     */
    public static function charge(Tiers $tiers, array $dailyCounts, int $cycleDays, string $counting = self::IP_DAYS): string
    {
        if (!in_array($counting, [self::IP_DAYS, self::PEAK], true)) {
            throw new InvalidArgumentException("Unknown counting «{$counting}».");
        }
        $days = count($dailyCounts);
        if ($days < 1 || $cycleDays < $days) {
            throw new InvalidArgumentException('A period has at least one day and no more than its cycle.');
        }

        if ($counting === self::PEAK) {
            $sum = $tiers->cost(max($dailyCounts))->multipliedBy($days);
        } else {
            $sum = BigDecimal::zero();
            foreach ($dailyCounts as $units) {
                $sum = $sum->plus($tiers->cost($units));
            }
        }

        return (string) $sum->dividedBy($cycleDays, 2, RoundingMode::HalfUp);
    }

    /**
     * The distinct units that were there at any moment of [$start, $end) (docs/zonecloud-bulk-license.md §6.0, the rule
     * decided on 2026-11-12): an IP on the list for a moment, even one taken off the same instant, counts for the whole
     * period. Intervals of one key count once.
     *
     * @param  iterable<array{0: string, 1: DateTimeInterface, 2: DateTimeInterface|null}>  $intervals  [key, added, removed or null]
     */
    public static function distinctUnits(iterable $intervals, DateTimeInterface $start, DateTimeInterface $end): int
    {
        if ($end <= $start) {
            throw new InvalidArgumentException('A period must have at least one day.');
        }
        $keys = [];
        foreach ($intervals as [$key, $added, $removed]) {
            // On the list within the period: added before its end, and still there at its start or added during it. One
            // added and removed at once counts too: a check may have been answered in between (ADV-825-1), and the agent
            // then runs to its stored due date.
            if ($added < $end && ($removed === null || $removed > $start || $added >= $start)) {
                $keys[(string) $key] = true;
            }
        }

        return count($keys);
    }

    /**
     * $units for $days of a cycle of $cycleDays: cost(units) × days / cycleDays, rounded half-up to cents once (a whole
     * period: cost(units)).
     */
    public static function periodCharge(Tiers $tiers, int $units, int $days, int $cycleDays): string
    {
        if ($days < 1 || $cycleDays < $days) {
            throw new InvalidArgumentException('A period has at least one day and no more than its cycle.');
        }

        return (string) $tiers->cost($units)->multipliedBy($days)->dividedBy($cycleDays, 2, RoundingMode::HalfUp);
    }

    /**
     * The correction a period gets once it is over: what it cost less what was billed for it in advance (negative: the
     * customer had fewer units than billed).
     */
    public static function adjustment(string $actual, string $billed): string
    {
        foreach ([$actual, $billed] as $amount) {
            if (preg_match('/^-?\d+(\.\d{1,2})?$/', $amount) !== 1) {
                throw new InvalidArgumentException("Not an amount in cents: «{$amount}».");
            }
        }

        return (string) BigDecimal::of($actual)->minus(BigDecimal::of($billed))->toScale(2);
    }

    /** @param  array<string, int>  $dailyCounts */
    public static function unitDays(array $dailyCounts): int
    {
        return array_sum($dailyCounts);
    }
}
