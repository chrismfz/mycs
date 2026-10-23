<?php

namespace App\Actions\Auth;

use App\Models\Staff;
use App\Models\UserSession;
use App\Support\AuthCookies;
use App\Support\StaffSessions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Session;

class Logout
{
    public function execute(): void
    {
        if (Session::has('user_session')) {
            $token = UserSession::firstWhere('ulid', Session::get('user_session'));
            if ($token) {
                $token->delete();
            }
        }

        // The staff login's row ends while the session still names it (review of #375).
        $staff = Auth::guard('staff')->user();
        if ($staff instanceof Staff) {
            StaffSessions::loggedOut($staff, request());
        }

        // Clear session and cookie
        Session::invalidate();
        Session::regenerateToken();
        Cookie::queue(Cookie::forget(AuthCookies::remember()));

        // One session for both: invalidated, so neither the customer nor staff stay logged in it.
        Auth::guard('web')->logout();
        Auth::guard('staff')->logout();
    }
}
