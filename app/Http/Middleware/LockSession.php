<?php

namespace App\Http\Middleware;

use App\Actions\Auth\Logout;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LockSession
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $setting = config('settings.session_validation', 'none');
        if (!auth()->check() || $setting === 'none') {
            return $next($request);
        }

        // The admin panel runs on the staff guard (UseStaffGuard): its rules are the admin's.
        if ($resp = $this->refuses($request, auth()->getDefaultDriver() === 'staff' ? 'admin' : 'client')) {
            return $resp;
        }

        return $next($request);
    }

    /**
     * The session checked by one side's rules: a redirect, the session logged out, when its address or browser is not
     * the one it started with. Also for staff in «Login as client» with the admin's rules (ImpersonateMiddleware, SEC-1
     * of the review of #465): it is the staff member's session.
     */
    public function refuses(Request $request, string $role): ?Response
    {
        $setting = config('settings.session_validation', 'none');

        $ipModes = [
            'admin' => ['ip_admin', 'ip_both', 'ip_user_agent_admin', 'ip_user_agent_both'],
            'client' => ['ip_client', 'ip_both', 'ip_user_agent_client', 'ip_user_agent_both'],
        ];

        $uaModes = [
            'admin' => ['user_agent_admin', 'user_agent', 'ip_user_agent_admin', 'ip_user_agent_both'],
            'client' => ['user_agent_client', 'user_agent', 'ip_user_agent_client', 'ip_user_agent_both'],
        ];

        if (in_array($setting, $ipModes[$role], true)) {
            if ($resp = $this->checkAndStore($request->session(), 'login_ip', $request->ip(), $request)) {
                return $resp;
            }
        }

        if (in_array($setting, $uaModes[$role], true)) {
            if ($resp = $this->checkAndStore($request->session(), 'login_ua', hash('sha256', (string) $request->header('User-Agent')), $request)) {
                return $resp;
            }
        }

        return null;
    }

    private function checkAndStore(Session $session, string $key, string $value, $request)
    {
        $stored = $session->get($key);

        if ($stored === null) {
            $session->put($key, $value);
        } elseif ($stored !== $value) {
            return $this->invalidateSession($request);
        }

        return null;
    }

    private function invalidateSession(Request $request)
    {
        app(Logout::class)->execute();

        return redirect('/');
    }
}
