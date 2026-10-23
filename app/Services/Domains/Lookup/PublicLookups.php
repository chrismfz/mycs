<?php

namespace App\Services\Domains\Lookup;

use Illuminate\Support\Facades\RateLimiter;

/**
 * All the public pages' lookups together (search, transfer, whois; owner 2026-10-23): besides each address's own limit,
 * at most GLOBAL per 10 minutes from everyone, so that many addresses at once (a botnet, proxies) cannot have the
 * registries block us. Past it every visitor is told to wait a little; staff's own lookups are not counted.
 */
final class PublicLookups
{
    public const GLOBAL = 300;

    private const KEY = 'domain-lookups:public';

    /** Whether $count more lookups may go now; if so they are counted. */
    public static function take(int $count = 1): bool
    {
        if (RateLimiter::remaining(self::KEY, self::GLOBAL) < $count) {
            return false;
        }
        for ($i = 0; $i < $count; $i++) {
            RateLimiter::hit(self::KEY, 600);
        }

        return true;
    }
}
