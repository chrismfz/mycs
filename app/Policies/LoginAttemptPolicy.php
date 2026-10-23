<?php

namespace App\Policies;

use App\Models\LoginAttempt;
use App\Models\Staff;
use App\Models\User;

/** Login History: to staff who see the Activity Log; written only by the sign-ins. */
class LoginAttemptPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.audit.viewAny');
    }

    public function view(User|Staff $user, LoginAttempt $attempt): bool
    {
        return $this->viewAny($user);
    }

    public function create(User|Staff $user): bool
    {
        return false;
    }

    public function update(User|Staff $user, LoginAttempt $attempt): bool
    {
        return false;
    }

    public function delete(User|Staff $user, LoginAttempt $attempt): bool
    {
        return false;
    }

    public function deleteAny(User|Staff $user): bool
    {
        return false;
    }
}
