<?php

namespace App\Http\Middleware;

use App\Actions\Auth\Logout;
use App\Models\LoginAttempt;
use App\Models\UserSession;
use App\Support\AuthCookies;
use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class ResolveUserSession
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $token = null;

        if ($session->has('user_session')) {
            $token = UserSession::findValid($session->get('user_session'));
        }

        $remembered = false;
        if (!$token) {
            $id = Cookie::get(AuthCookies::remember());

            if ($id) {
                $token = UserSession::findValid($id);
                $remembered = $token !== null;
            }
        }

        if ($token) {
            $request->attributes->set('user_session', $token);
            $token->touchRequest($request);

            // Store in session for next request
            if (!$session->has('user_session') || $session->get('user_session') !== $token->ulid) {
                $session->put('user_session', $token->ulid);
            }

            // Login user
            // Customers only (the `web` guard): staff have their own guard and plain session (docs/accounts.md, PR 1).
            if (!Auth::guard('web')->check() || Auth::guard('web')->id() !== $token->user_id) {
                Auth::guard('web')->login($token->user);
                if ($remembered) {
                    // Back by «Remember me»: in the Login History, as staff's (review of #409).
                    LoginAttempt::record(LoginAttempt::CLIENT, $token->user, $token->user->email, LoginAttempt::REMEMBERED);
                }
                // Back by its remember cookie: its pages in its own language, as at a login (docs/accounts.md).
                if (!$session->has('locale') && ($locale = Locales::allowed($token->user->language)) !== null) {
                    $session->put('locale', $locale);
                    App::setLocale($locale);
                }
            }
        }

        // If no valid token, ensure user is logged out
        if (!$token && Auth::guard('web')->check()) {
            return $this->fail($request);
        }

        // Lottery-based garbage collection
        $this->garbageCollection();

        return $next($request);
    }

    private function garbageCollection(): void
    {
        // Run lottery
        if (!$this->shouldRunGarbageCollection()) {
            return;
        }

        $now = now();

        // Delete expired remember sessions
        UserSession::whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->delete();

        // Delete inactive normal sessions
        UserSession::whereNull('expires_at')
            ->where('last_activity', '<', $now->copy()->subMinutes(config('session.lifetime')))
            ->delete();
    }

    private function shouldRunGarbageCollection(): bool
    {
        [$chances, $total] = config('session.lottery');

        return random_int(1, $total) <= $chances;
    }

    private function fail(Request $request): Response
    {
        app(Logout::class)->execute();

        return redirect('/');
    }
}
