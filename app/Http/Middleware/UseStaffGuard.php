<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * In the admin panel the authenticated one is the staff member (guard `staff`): `auth()->user()`, policies and gates
 * get a Staff, never a customer logged in in the same browser.
 */
class UseStaffGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('staff');
        // «Login as client» (AUTH-19) goes on in its own tab while staff use the admin in this one (owner 2026-10-26):
        // it no longer ends here, but the admin never sees the customer it set as the client area's login
        // (ImpersonateMiddleware, on the panel's Livewire requests too); a customer really logged in stays so.
        if ($request->hasSession() && $request->session()->has('impersonating')) {
            Auth::guard('web')->forgetUser();
        }

        return $next($request);
    }
}
