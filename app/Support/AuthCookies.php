<?php

namespace App\Support;

/**
 * The cookies that are a login (the session, «remember me», a staff member's known device) carry the __Host- prefix
 * whenever the site allows it: the browser then takes them only from this host itself, over HTTPS, for the whole site.
 * Without it any page of a sibling or the parent domain (myip.gr's own site, WHMCS, an XSS on one of them) could set
 * its own for this host and sign a visitor in to the attacker's account (security audit, live chat review G4).
 *
 * The prefix needs Secure and no Domain: an HTTP setup or one with SESSION_DOMAIN set keeps the plain names. A cookie of
 * the old name is never read, so logins made before the change end once.
 */
final class AuthCookies
{
    public const PREFIX = '__Host-';

    public static function prefixed(bool $secure, ?string $domain): bool
    {
        return $secure && ($domain === null || $domain === '');
    }

    public static function name(string $name): string
    {
        return (self::prefixed((bool) config('session.secure'), config('session.domain')) ? self::PREFIX : '') . $name;
    }

    public static function remember(): string
    {
        return self::name('mycs_remember');
    }

    /** The cart: a login takes it into its account, so no other site may set it either (review of #346). */
    public static function cart(): string
    {
        return self::name('cart');
    }
}
