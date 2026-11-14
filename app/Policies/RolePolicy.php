<?php

namespace App\Policies;

use App\Classes\PermissionGuard;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;

class RolePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.roles.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User|Staff $user, Role $role): bool
    {
        return $user->hasPermission('admin.roles.viewAny');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.roles.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User|Staff $user, Role $role): bool
    {
        // Only roles that carry nothing beyond your own permissions; the full admin role (#1) never. Here, not only in
        // RoleResource::canEdit: since Filament 5.11.2 the edit page asks the policy directly (#20957).
        return $user instanceof Staff && $role->id !== 1 && $user->hasPermission('admin.roles.update') && PermissionGuard::canGrant($user, $role->permissions ?? []);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User|Staff $user, Role $role): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.roles.delete') && PermissionGuard::canGrant($user, $role->permissions ?? []);
    }

    /**
     * Determine whether the user can delete multiple models.
     */
    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.roles.deleteAny');
    }
}
