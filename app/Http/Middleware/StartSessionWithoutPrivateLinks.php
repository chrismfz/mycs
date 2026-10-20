<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

/**
 * The session as Laravel starts it, except that a page reached by a secret in its URL (a guest's ticket link, docs/
 * tickets.md §3 Δ) is never kept as the «previous URL»: the token is stored only hashed, and the session (in the database
 * or in files, for its whole lifetime) would keep it whole (adversarial review of #252).
 */
class StartSessionWithoutPrivateLinks extends StartSession
{
    /** The routes whose URL holds a secret. */
    public const PRIVATE_ROUTES = ['tickets.guest', 'tickets.guest.attachment'];

    protected function storeCurrentUrl(Request $request, $session)
    {
        if ($request->routeIs(...self::PRIVATE_ROUTES)) {
            return;
        }
        parent::storeCurrentUrl($request, $session);
    }
}
