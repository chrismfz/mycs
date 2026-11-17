<?php

namespace App\Support\Tickets;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use MyCS\TicketMail\Dkim;

/**
 * The DNS lookups of our own DKIM check, bounded (audit 2026-11-17). The name is the sender's choice and the resolver
 * waits its whole timeout for name servers that never answer: fifty such mails held the reading of real mail for many
 * minutes, every run. A domain whose lookup failed is not asked again for REMEMBER_MINUTES, and after FAILURES of any
 * domain within WINDOW_SECONDS no lookup is made at all. A lookup not made is a lookup that failed: the mail is not
 * vouched for by our check, as before, never refused.
 */
final class DkimDns
{
    public const REMEMBER_MINUTES = 15;

    public const FAILURES = 5;

    public const WINDOW_SECONDS = 180;

    private const FAILED = 'dkim-dns-failures';

    /** @var (Closure(string): (list<string>|null))|null the resolver, for the tests */
    public static ?Closure $resolver = null;

    /** @return list<string>|null */
    public static function txt(string $name): ?array
    {
        $domain = 'dkim-dns-failed:' . sha1(strtolower((string) preg_replace('/^.*\._domainkey\./i', '', $name)));
        if (Cache::has($domain) || RateLimiter::tooManyAttempts(self::FAILED, self::FAILURES)) {
            return null;
        }
        $records = (self::$resolver ?? Dkim::dnsTxt(...))($name);
        if ($records === null) {
            Cache::put($domain, true, now()->addMinutes(self::REMEMBER_MINUTES));
            RateLimiter::hit(self::FAILED, self::WINDOW_SECONDS);
        }

        return $records;
    }
}
