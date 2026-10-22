<?php

namespace App\Actions\Auth;

use App\Models\UserSession;
use App\Support\AuthCookies;
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

        // Clear session and cookie
        Session::invalidate();
        Session::regenerateToken();
        Cookie::queue(Cookie::forget(AuthCookies::remember()));

        // One session for both: invalidated, so neither the customer nor staff stay logged in it.
        Auth::guard('web')->logout();
        Auth::guard('staff')->logout();
    }
}
