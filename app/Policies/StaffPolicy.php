<?php

namespace App\Policies;

use App\Classes\PermissionGuard;
use App\Models\Staff;
use App\Models\User;

/**
 * Staff manage staff by permission, and another member only if they hold all of that member's permissions
 * (PermissionGuard::outranks, as for roles and API keys): nobody takes over more power than they have.
 */
class StaffPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.staff.viewAny');
    }

    public function view(User|Staff $user, Staff $staff): bool
    {
        return $user->hasPermission('admin.staff.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.staff.create');
    }

    public function update(User|Staff $user, Staff $staff): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.staff.update') && PermissionGuard::outranks($user, $staff);
    }

    /** Never oneself; never a member with history (disabled instead, DATA-3). */
    public function delete(User|Staff $user, Staff $staff): bool
    {
        return $user instanceof Staff && !$user->is($staff) && $user->hasPermission('admin.staff.delete')
            && PermissionGuard::outranks($user, $staff) && !$staff->hasHistory();
    }

    public function deleteAny(User|Staff $user): bool
    {
        return false;
    }
}
