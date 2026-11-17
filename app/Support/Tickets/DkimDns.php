<?php

namespace App\Support\Tickets;

use Closure;
use Illuminate\Support\Facades\Cache;
use MyCS\TicketMail\Dkim;

/**
 * The DNS lookups of our own DKIM check as the poller makes them (audit 2026-11-17). The name is the sender's choice and
 * the resolver waits its whole timeout for name servers that never answer: a domain whose lookup failed is not asked
 * again for REMEMBER_MINUTES, so many mails of one dead domain cost one wait. A lookup not made is a lookup that failed:
 * the mail is not vouched for by our check, as before, never refused.
 *
 * Known and left (review of #899): a sender with a new dead subdomain in every mail still costs a wait each. A stop after
 * so many failures would let five such mails leave everyone's mail unvouched, which is worse than late.
 */
final class DkimDns
{
    public const REMEMBER_MINUTES = 15;

    /** @var (Closure(string): (list<string>|null))|null the resolver, for the tests */
    public static ?Closure $resolver = null;

    /** @return list<string>|null */
    public static function txt(string $name): ?array
    {
        $domain = 'dkim-dns-failed:' . sha1(strtolower((string) preg_replace('/^.*\._domainkey\./i', '', $name)));
        if (Cache::has($domain)) {
            return null;
        }
        $records = (self::$resolver ?? Dkim::dnsTxt(...))($name);
        if ($records === null) {
            Cache::put($domain, true, now()->addMinutes(self::REMEMBER_MINUTES));
        }

        return $records;
    }
}
