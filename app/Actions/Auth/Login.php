<?php

namespace App\Actions\Auth;

use App\Events\Auth\Login as LoginEvent;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Models\UserSession;
use App\Providers\Filament\AdminPanelProvider;
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

    /**
     * Where a customer's login goes on: back where it started (an invitation's link, PR 2b), unless that is the admin
     * panel (owner 2026-11-02: after staff logged out there, the shared session still held /sysadmin as intended, and a
     * customer's login landed on the staff login), another site, or not an http(s) address; then $default. The path is
     * read as the router reads it (review of #575): decoded once, without the front controller or the app's base path,
     * any case. This site's hosts: the request's and APP_URL's (an invitation's link carries APP_URL's). Taken out of
     * the session either way.
     */
    public static function intended(string $default): string
    {
        $url = (string) Session::pull('url.intended', '');
        $parts = $url === '' || str_contains($url, '\\') ? false : parse_url($url);
        if ($parts === false) {
            return $default;
        }
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : null;
        $host = isset($parts['host']) ? strtolower($parts['host']) : null;
        $ours = array_filter([strtolower(request()->getHost()), strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST))]);
        if (($scheme !== null && !in_array($scheme, ['http', 'https'], true)) || ($host !== null && !in_array($host, $ours, true))) {
            return $default;
        }
        $path = '/' . ltrim(rawurldecode((string) ($parts['path'] ?? '/')), '/');
        $path = (string) preg_replace('#/+#', '/', $path);
        if (preg_match('#(^|/)\.\.?(/|$)#', $path) === 1) {
            return $default; // dot segments: a browser resolves them, maybe onto the panel
        }
        $base = rtrim((string) parse_url(url('/'), PHP_URL_PATH), '/');
        foreach ([$base, '/index.php'] as $prefix) {
            if ($prefix !== '' && ($path === $prefix || str_starts_with(strtolower($path), strtolower($prefix) . '/'))) {
                $path = '/' . ltrim(substr($path, strlen($prefix)), '/');
            }
        }
        $admin = '/' . strtolower(trim(AdminPanelProvider::PATH, '/'));
        $lower = strtolower($path);

        return $lower === $admin || str_starts_with($lower, $admin . '/') ? $default : $url;
    }
}
