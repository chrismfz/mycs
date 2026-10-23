<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * When the cron may suspend and terminate (owner 2026-10-23): on the days and from the hour staff set, so someone is in
 * the office to unsuspend a good customer's service the same morning (WHMCS needed cron tricks for it). What falls on
 * another day waits for the next allowed one; nothing is skipped, only later. All days from 00:00 is as before.
 */
final class DunningWindow
{
    /** ISO days: 1 = Monday … 7 = Sunday. */
    public const ALL_DAYS = [1, 2, 3, 4, 5, 6, 7];

    /** @return list<int> the days allowed, all when none is set */
    public static function days(): array
    {
        $days = array_values(array_unique(array_filter(array_map('intval', (array) config('settings.cronjob_dunning_days', self::ALL_DAYS)),
            fn (int $day) => $day >= 1 && $day <= 7)));
        sort($days);

        return $days === [] ? self::ALL_DAYS : $days;
    }

    /**
     * The minute of the day it opens, on the hour («10:00» = 600; «10:30» opens at 10:00, as the hourly run checks at
     * :05 and a later minute would slip to the next hour, at 23 to never: round 2 of #411); 0 when unset or unreadable.
     */
    public static function fromMinute(): int
    {
        $from = (string) config('settings.cronjob_dunning_from', '00:00');

        return preg_match('/^(\d{1,2}):(\d{2})/', $from, $m) === 1 ? min(23, (int) $m[1]) * 60 : 0;
    }

    public static function isOpen(?Carbon $at = null): bool
    {
        $at ??= Carbon::now();

        return in_array($at->isoWeekday(), self::days(), true) && $at->hour * 60 + $at->minute >= self::fromMinute();
    }

    /** The first day on or after $day it opens (for the mail that tells the customer the day). */
    public static function firstDayFrom(Carbon $day): Carbon
    {
        $day = $day->copy()->startOfDay();
        for ($i = 0; $i < 7 && !in_array($day->isoWeekday(), self::days(), true); $i++) {
            $day->addDay();
        }

        return $day;
    }

    /** Every day, at any hour: the window changes nothing. */
    public static function always(): bool
    {
        return self::days() === self::ALL_DAYS && self::fromMinute() === 0;
    }
}
