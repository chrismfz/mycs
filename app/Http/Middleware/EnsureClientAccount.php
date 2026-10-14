<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Models\User;
use App\Support\CurrentClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The client area always works on an account (docs/accounts.md, PR 2a): a login without any (made by staff, or one
 * whose grants were all revoked) gets its own, as WHMCS asks a user without a client to create one.
 */
class EnsureClientAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        if ($user instanceof User && CurrentClient::model() === null) {
            Client::openFor($user);
            app(CurrentClient::class)->forget();
        }

        return $next($request);
    }
}
