<?php

namespace App\Policies;

use App\Models\Gateway;
use App\Models\Staff;
use App\Models\User;

class GatewayPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.gateways.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User|Staff $user, Gateway $gateway): bool
    {
        return $user->hasPermission('admin.gateways.viewAny');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.gateways.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User|Staff $user, Gateway $gateway): bool
    {
        return $user->hasPermission('admin.gateways.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User|Staff $user, Gateway $gateway): bool
    {
        return $user->hasPermission('admin.gateways.delete');
    }

    /**
     * Determine whether the user can delete multiple models.
     */
    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.gateways.deleteAny');
    }
}
