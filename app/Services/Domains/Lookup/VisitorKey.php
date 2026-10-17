<?php

namespace App\Services\Domains\Lookup;

use App\Support\CallerIp;
use Illuminate\Http\Request;

/**
 * Whose search it is, for the public limits (D8a reviews of #178): the caller's own address (not one an X-Forwarded-For
 * header names, CallerIp), an IPv6 one by its /64 (one customer's network; else 2^64 addresses each with its own limit).
 */
final class VisitorKey
{
    public static function of(Request $request): string
    {
        $ip = CallerIp::of($request);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $bytes = (string) inet_pton($ip);

            return bin2hex(substr($bytes, 0, 8)) . '::/64';
        }

        return $ip;
    }
}
