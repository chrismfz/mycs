<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every answer of the app carries the browser's basic protections itself, whatever web server is in front (security
 * audit INJ-6): no page in another site's frame (clickjacking), no plugins, no <base> elsewhere, no content sniffing,
 * and only the origin in a Referer to another site. HSTS is the web server's (decided 2026-10-22: not yet). Nor does
 * it say which PHP runs it (X-Powered-By, audit flow 10): the web server's config hides it too, for zlic.php.
 *
 * A route's own stricter values stay: its Referrer-Policy wins, and its own CSP (an attachment's sandbox) applies
 * together with this one, the browser enforcing both.
 */
class SecurityHeaders
{
    public const CSP = "frame-ancestors 'self'; object-src 'none'; base-uri 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }

        if (!in_array(self::CSP, $headers->all('content-security-policy'), true)) {
            $headers->set('Content-Security-Policy', self::CSP, false);
        }
        if (!$headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
        }
        $headers->set('X-Content-Type-Options', 'nosniff');
        if (!$headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        return $response;
    }
}
