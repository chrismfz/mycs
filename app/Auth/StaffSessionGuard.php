<?php

namespace App\Auth;

use App\Models\Staff;
use App\Support\AuthCookies;
use App\Support\StaffSessions;
use Illuminate\Auth\SessionGuard;

/**
 * The staff guard, whose «remember me» cookie carries the __Host- prefix like the session's (App\Support\AuthCookies):
 * no sibling or parent domain can set or shadow it. A cookie of the old name is never read: remembered logins made
 * before the change end once.
 *
 * It also refuses a session whose row was ended (StaffSessions), on every route that uses it: the admin, the export,
 * the MCP consent, «login as client» (review of #375).
 */
class StaffSessionGuard extends SessionGuard
{
    /** The request whose session was checked (once per request). */
    private ?int $checkedFor = null;

    public function user()
    {
        $user = parent::user();
        $request = spl_object_id($this->request);
        if ($user instanceof Staff && $this->checkedFor !== $request) {
            $this->checkedFor = $request;
            if (StaffSessions::endedIn($user, $this->session)) {
                // This browser only (whoever ended it rotated the «remember me» token), and its session with it: the
                // store kept it (review of #375), so nothing of it stays, a customer login in it neither.
                $this->logoutCurrentDevice();
                $this->session->invalidate();
                $this->session->regenerateToken();

                return null;
            }
        }

        return $user;
    }

    public function getRecallerName()
    {
        return AuthCookies::name('remember_' . $this->name . '_' . sha1(SessionGuard::class));
    }
}
