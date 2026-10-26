<?php

namespace App\Http\Middleware;

use App\Models\Staff;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * «Login as client» (AUTH-19): a member of staff with the permission sees the client area as the customer, for this
 * request only. The staff member stays the one who acts in audits and the money journal (App\Support\Actor). It has
 * its own tab (owner 2026-10-26): the admin's pages leave it on but never see the customer (UseStaffGuard); «Leave» or
 * the client area's «Log out» end it.
 */
class ImpersonateMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->session()->has('impersonating')) {
            return $next($request);
        }
        $staff = Auth::guard('staff')->user();
        $customer = User::query()->find($request->session()->get('impersonating'));
        if (!$staff instanceof Staff || !$staff->hasPermission('admin.users.impersonate') || $customer === null) {
            $request->session()->forget('impersonating');

            return $next($request);
        }

        Auth::guard('web')->onceUsingId($customer->id);

        return $next($request);
    }
}
