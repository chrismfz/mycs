<?php

namespace App\Policies;

use App\Classes\PermissionGuard;
use App\Models\ApiKey;
use App\Models\Staff;
use App\Models\User;

class ApiKeyPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.api_keys.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User|Staff $user, ApiKey $api_key): bool
    {
        return $user->hasPermission('admin.api_keys.viewAny');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.api_keys.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User|Staff $user, ApiKey $api_key): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.api_keys.update') && PermissionGuard::canGrantAllApi($user, $api_key->permissions ?? []);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User|Staff $user, ApiKey $api_key): bool
    {
        // As for update: nobody removes a key that does more than they could grant.
        return $user instanceof Staff && $user->hasPermission('admin.api_keys.delete') && PermissionGuard::canGrantAllApi($user, $api_key->permissions ?? []);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.api_keys.deleteAny');
    }
}
