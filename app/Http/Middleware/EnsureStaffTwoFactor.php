<?php

namespace App\Http\Middleware;

use App\Support\StaffTwoFactor;
use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Illuminate\Http\Request;

/**
 * Filament's «2FA set up first», asked on every request whether staff 2FA is required (Settings: «Require 2FA for
 * staff»): the panel's routes, cached once, always carry it, and the setting decides at the time.
 */
class EnsureStaffTwoFactor extends EnsureMultiFactorAuthenticationIsEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        return StaffTwoFactor::required() ? parent::handle($request, $next) : $next($request);
    }
}
