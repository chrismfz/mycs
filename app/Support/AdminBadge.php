<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * A count the admin menu shows on every page (unpaid and overdue invoices, tickets waiting, pending services...): kept
 * for a few seconds (config app.admin_badge_cache_seconds, 30 by default; 0 counts every time, as the tests do). A
 * badge a few seconds old is fine; a count of a large table on every page and every poll is not.
 */
final class AdminBadge
{
    /** @param  Closure(): int  $count */
    public static function count(string $name, Closure $count): int
    {
        $seconds = (int) config('app.admin_badge_cache_seconds', 30);

        return $seconds > 0 ? (int) Cache::remember('admin-badge:' . $name, $seconds, $count) : (int) $count();
    }

    /** As the menu shows it: null for none. @param  Closure(): int  $count */
    public static function label(string $name, Closure $count): ?string
    {
        $n = self::count($name, $count);

        return $n > 0 ? (string) $n : null;
    }
}
