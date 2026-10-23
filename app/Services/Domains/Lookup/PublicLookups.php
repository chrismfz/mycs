<?php

namespace App\Services\Domains\Lookup;

use Illuminate\Support\Facades\RateLimiter;

/**
 * All the public pages' lookups together (search, transfer, whois; owner 2026-10-23): besides each address's own limit,
 * at most GLOBAL names checked per 10 minutes from everyone (a search checks up to DomainSearch::ALTERNATIVES + 1), so
 * that many addresses at once (a botnet, proxies) cannot have the registries block us. Past it every visitor is told to
 * wait a little. Staff's own lookups (Utilities → WHOIS Lookup) are not counted. Counted first, then checked: parallel
 * requests never pass it together (code review).
 */
final class PublicLookups
{
    public const GLOBAL = 1500;

    private const KEY = 'domain-lookups:public';

    /** Whether $count more names may be checked now. Counted either way once under the limit. */
    public static function take(int $count = 1): bool
    {
        if (RateLimiter::attempts(self::KEY) >= self::GLOBAL) {
            return false;
        }

        return RateLimiter::increment(self::KEY, 600, $count) <= self::GLOBAL;
    }
}
