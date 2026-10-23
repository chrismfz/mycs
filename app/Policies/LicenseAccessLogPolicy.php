<?php

namespace App\Policies;

use App\Models\LicenseAccessLog;
use App\Models\Staff;
use App\Models\User;

/** A license's checks: a read-only log, seen with the view permission of what it belongs to (SA-12). */
class LicenseAccessLogPolicy extends BasePolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.services.viewAny');
    }

    public function view(User|Staff $user, LicenseAccessLog $log): bool
    {
        return $this->adminPermission($user, 'admin.services.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return false;
    }

    public function update(User|Staff $user, LicenseAccessLog $log): bool
    {
        return false;
    }

    public function delete(User|Staff $user, LicenseAccessLog $log): bool
    {
        return false;
    }

    public function deleteAny(User|Staff $user): bool
    {
        return false;
    }
}
