<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\Staff;
use App\Models\User;

class ServicePolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.services.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User|Staff $user, Service $service): bool
    {
        return $this->adminPermission($user, 'admin.services.viewAny', $this->owns($user, $service->client_id, 'products'));
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.services.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User|Staff $user, Service $service): bool
    {
        return $this->adminPermission($user, 'admin.services.update', $this->owns($user, $service->client_id, 'manageproducts'));
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User|Staff $user, Service $service): bool
    {
        return $user->hasPermission('admin.services.delete');
    }

    /**
     * Determine whether the user can permanently delete any models.
     */
    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.services.deleteAny');
    }
}
