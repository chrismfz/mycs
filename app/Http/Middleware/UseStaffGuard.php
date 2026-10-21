<?php

namespace App\Http\Middleware;

use App\Providers\Filament\AdminPanelProvider;
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
        // Back in the admin (a page, not the panel's Livewire requests): «Login as client» ends (AUTH-19).
        if ($request->is(AdminPanelProvider::PATH, AdminPanelProvider::PATH . '/*')) {
            $request->session()->forget('impersonating');
        }

        return $next($request);
    }
}
