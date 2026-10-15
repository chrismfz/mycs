<?php

namespace App\Policies;

use App\Models\License;
use App\Models\Staff;
use App\Models\User;

/** Licenses belong to the services: the services' permissions (the console only lists; changes are on the service). */
class LicensePolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.services.viewAny');
    }

    public function view(User|Staff $user, License $license): bool
    {
        return $user->hasPermission('admin.services.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return false;
    }

    public function update(User|Staff $user, License $license): bool
    {
        return false; // on its service (Edit license, Reissue)
    }

    public function delete(User|Staff $user, License $license): bool
    {
        return false;
    }
}
