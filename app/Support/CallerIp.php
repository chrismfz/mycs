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

    /** One caller for a limit: an IPv4 address, or an IPv6 one's /64 (one customer's network: else 2^64 callers). */
    /** The current request's caller as a rate limit's key: its own address, an IPv6 one by its /64 (AUTHN-2). */
    public static function key(?Request $request = null): string
    {
        return self::bucket(self::of($request ?? request()));
    }

    public static function bucket(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return bin2hex(substr((string) inet_pton($ip), 0, 8)) . '::/64';
        }

        return $ip;
    }
}
