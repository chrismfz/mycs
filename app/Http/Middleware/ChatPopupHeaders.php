<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The chat popup and the login it leads to (docs/livechat.md §4, F8, G10): never in anyone's frame, not even ours, and a
 * window of its own. A site whose launcher was tampered with keeps no handle on the popup (no window.opener, no
 * tabnabbing) and learns nothing from where it goes (no Referer beyond our origin).
 *
 * Stricter than SecurityHeaders, which runs after and keeps these: its own CSP applies together with this one, and the
 * browser enforces both, so `frame-ancestors 'none'` wins.
 */
class ChatPopupHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;
        $headers->set('Content-Security-Policy', "frame-ancestors 'none'", false);
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
