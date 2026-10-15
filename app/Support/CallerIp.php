<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The address a request came from, for a check that binds or allows by address (licenses, API key allowlists): the
 * connection's, unless it comes through a proxy staff listed by address (review ADV-L3). «Trust all proxies» (*) would
 * let any caller name its address in X-Forwarded-For.
 */
final class CallerIp
{
    public static function of(Request $request): string
    {
        $proxies = (array) config('settings.trusted_proxies', []);

        return in_array('*', $proxies, true) ? (string) $request->server('REMOTE_ADDR') : (string) $request->ip();
    }
}
