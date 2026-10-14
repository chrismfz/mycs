<?php

namespace App\Policies;

use App\Models\Extension;
use App\Models\Staff;
use App\Models\User;

class ExtensionPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.extensions.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User|Staff $user): bool
    {
        return $user->hasPermission('admin.extensions.viewAny');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User|Staff $user, Extension $extension): bool
    {
        return $user->hasPermission('admin.extensions.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function install(User|Staff $user): bool
    {
        return $user->hasPermission('admin.extensions.install');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User|Staff $user, Extension $extension): bool
    {
        return $user->hasPermission('admin.extensions.delete');
    }
}
