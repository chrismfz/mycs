<?php

namespace App\Policies;

use App\Models\ConfigOption;
use App\Models\Staff;
use App\Models\User;

class ConfigOptionPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.config_options.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User|Staff $user, ConfigOption $configOption): bool
    {
        return $user->hasPermission('admin.config_options.viewAny');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.config_options.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User|Staff $user, ConfigOption $configOption): bool
    {
        return $user->hasPermission('admin.config_options.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User|Staff $user, ConfigOption $configOption): bool
    {
        return $user->hasPermission('admin.config_options.delete');
    }

    /**
     * Determine whether the user can delete multiple models.
     */
    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.config_options.deleteAny');
    }

    /** Reordering changes the catalog: the right to change it, not only to see it (Filament allows an undefined ability). */
    public function reorder(User|Staff $user): bool
    {
        return $user->hasPermission('admin.config_options.update');
    }
}
