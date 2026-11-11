<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Models\User;
use App\Support\CurrentClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

/**
 * The client area always works on an account (docs/accounts.md, PR 2a): a login without any (made by staff, or one
 * whose grants were all revoked) gets its own, as WHMCS asks a user without a client to create one. Not on the
 * invitation's page: see onInvitation().
 */
class EnsureClientAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        if ($user instanceof User && !self::onInvitation($request) && CurrentClient::model() === null) {
            Client::openFor($user);
            app(CurrentClient::class)->forget();
        }

        return $next($request);
    }

    /**
     * The invitation's page (/invite, /invite/{token}) and its own Livewire calls («Accept»): a login made to accept an
     * invitation gets no account of its own there; it would be its default at every later login, before the account it
     * was invited to (sweep 8, S8-ACCESS-1). Anywhere else it still gets one, should it never accept.
     *
     * The page by its route's name. The page a call «came from» (the snapshot's path, the caller's own word until
     * Livewire checks its checksum) is asked only on Livewire's update route, where a forged snapshot is refused before
     * any component runs; on any other route it is never believed (review of #796, REV-796-1).
     */
    private static function onInvitation(Request $request): bool
    {
        if ($request->routeIs('invites.accept', 'invites.continue')) {
            return true;
        }

        return $request->routeIs('*livewire.update') && preg_match('#^/?invite(/|$)#', (string) Livewire::originalPath()) === 1;
    }
}
