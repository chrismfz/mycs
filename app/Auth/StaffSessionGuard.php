<?php

namespace App\Auth;

use App\Support\AuthCookies;
use Illuminate\Auth\SessionGuard;

/**
 * The staff guard, whose «remember me» cookie carries the __Host- prefix like the session's (App\Support\AuthCookies):
 * no sibling or parent domain can set or shadow it. A cookie of the old name is never read: remembered logins made
 * before the change end once.
 */
class StaffSessionGuard extends SessionGuard
{
    public function getRecallerName()
    {
        return AuthCookies::name('remember_' . $this->name . '_' . sha1(SessionGuard::class));
    }
}
