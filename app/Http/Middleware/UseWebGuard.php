<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The client area is the customers' (guard `web`), never the staff guard (docs/accounts.md, PR 1): `auth()->user()` there
 * is a customer even when the same browser is logged in to the admin. Livewire requests of the admin panel pass here
 * too; the panel's persistent UseStaffGuard runs after and switches them back to staff.
 */
class UseWebGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('web');

        return $next($request);
    }
}
