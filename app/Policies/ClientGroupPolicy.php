<?php

namespace App\Policies;

use App\Models\ClientGroup;
use App\Models\Staff;
use App\Models\User;

class ClientGroupPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.client_groups.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User|Staff $user, ClientGroup $group): bool
    {
        return $user->hasPermission('admin.client_groups.viewAny');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.client_groups.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User|Staff $user, ClientGroup $group): bool
    {
        return $user->hasPermission('admin.client_groups.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User|Staff $user, ClientGroup $group): bool
    {
        return $user->hasPermission('admin.client_groups.delete');
    }

    /**
     * Determine whether the user can delete multiple models.
     */
    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.client_groups.deleteAny');
    }
}
