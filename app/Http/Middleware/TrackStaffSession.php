<?php

namespace App\Http\Middleware;

use App\Models\Staff;
use App\Support\StaffSessions;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every admin request of a logged-in member: a session without a row gets one (StaffSessions). An ended one is refused
 * by the staff guard itself, before this runs; the branch below is its fallback.
 */
class TrackStaffSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $staff = $request->hasSession() ? Auth::guard('staff')->user() : null;
        if (!$staff instanceof Staff || StaffSessions::check($staff, $request)) {
            return $next($request);
        }

        // Ended elsewhere: out, with a session of its own (another login starts afresh).
        // This browser only: the «remember me» token was rotated by whoever ended it.
        $guard = Auth::guard('staff');
        $guard instanceof SessionGuard ? $guard->logoutCurrentDevice() : $guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $login = Filament::getPanel('admin')->getLoginUrl() ?? '/';

        return $request->expectsJson() || $request->hasHeader('X-Livewire')
            ? response()->json(['message' => __('Your session was ended.')], 401)
            : redirect()->to($login);
    }
}
