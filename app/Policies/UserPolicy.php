<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.users.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User|Staff $user, User $model): bool
    {
        return $user->hasPermission('admin.users.viewAny');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.users.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User|Staff $user, User $model): bool
    {
        // Users are customers (no role): staff with the permission may edit any (roles are on Staff only).
        return $user->hasPermission('admin.users.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User|Staff $user, User $model): bool
    {
        return $user->hasPermission('admin.users.delete');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.users.deleteAny');
    }
}
