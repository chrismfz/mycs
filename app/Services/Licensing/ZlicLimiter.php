<?php

namespace App\Services\Licensing;

use Illuminate\Support\Facades\DB;

/**
 * zlic.php's counters (#254), in their own table: one statement counts a hit and starts a new window when the last one
 * ended, so checks in parallel are each counted (the file cache's increment is a read then a write, review F2).
 */
class ZlicLimiter
{
    /** Counts one hit in a window of $seconds from its first hit; the count now (this hit included). */
    public function hit(string $key, int $seconds): int
    {
        $now = now()->getTimestamp();
        $expires = $now + $seconds;
        DB::table('zlic_limits')->upsert(['key' => $key, 'hits' => 1, 'expires_at' => $expires], ['key'], [
            // Integers of our own, never input: a window that ended starts again at 1.
            'hits' => DB::raw("CASE WHEN expires_at <= {$now} THEN 1 ELSE hits + 1 END"),
            'expires_at' => DB::raw("CASE WHEN expires_at <= {$now} THEN {$expires} ELSE expires_at END"),
        ]);

        return (int) DB::table('zlic_limits')->where('key', $key)->value('hits');
    }

    /** A hit given back (the check was not what this counter counts). */
    public function giveBack(string $key): void
    {
        DB::table('zlic_limits')->where('key', $key)->where('hits', '>', 0)->decrement('hits');
    }

    /** Set for $seconds from now (a mark, not a count). */
    public function mark(string $key, int $seconds): void
    {
        $expires = now()->getTimestamp() + $seconds;
        DB::table('zlic_limits')->upsert(['key' => $key, 'hits' => 1, 'expires_at' => $expires], ['key'], ['hits' => 1, 'expires_at' => $expires]);
    }

    public function marked(string $key): bool
    {
        return DB::table('zlic_limits')->where('key', $key)->where('expires_at', '>', now()->getTimestamp())->exists();
    }

    /** A license reissued: its failures counted anywhere start again (it is about to bind to a new address). */
    public function forgetKey(string $licenseKey): void
    {
        DB::table('zlic_limits')->where('key', 'like', 'k:%:' . sha1($licenseKey))->delete();
    }

    /** Windows that ended (the daily cron). */
    public static function prune(): int
    {
        return DB::table('zlic_limits')->where('expires_at', '<', now()->getTimestamp())->delete();
    }
}
