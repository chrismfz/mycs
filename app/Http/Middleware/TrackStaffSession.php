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

/** Every admin request of a logged-in member: its session's row is seen, and an ended one is logged out (StaffSessions). */
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
