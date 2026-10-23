<?php

namespace App\Actions\Auth;

use App\Events\Auth\Login as LoginEvent;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Models\UserSession;
use App\Support\AuthCookies;
use App\Support\Locales;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Session;

class Login
{
    public function execute(User $user, bool $remember = false): void
    {
        // A new id, the old one's record gone (what was in it, an intended URL, comes along).
        Session::regenerate(true);

        // Create user session token - middleware will handle actual login
        $userSession = UserSession::create([
            'user_id' => $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => substr(request()->userAgent() ?? '', 0, 512),
            'last_activity' => Carbon::now(),
            'expires_at' => $remember ? Carbon::now()->addDays(30) : null,
        ]);

        Session::put('user_session', $userSession->ulid);

        Auth::guard('web')->login($user);
        // Its pages in its own language, as it last chose (docs/accounts.md); one chosen on this visit before signing in stays.
        if (!Session::has('locale') && ($locale = Locales::allowed($user->language)) !== null) {
            Session::put('locale', $locale);
        }

        if ($remember) {
            Cookie::queue(AuthCookies::remember(), $userSession->ulid, 60 * 24 * 30); // 30 days
        }

        event(new LoginEvent($user));
        LoginAttempt::record(LoginAttempt::CLIENT, $user, $user->email, LoginAttempt::SUCCESS);
    }
}
