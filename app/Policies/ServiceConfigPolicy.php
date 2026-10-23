<?php

namespace App\Policies;

use App\Models\ServiceConfig;
use App\Models\Staff;
use App\Models\User;

/** A service's configurable options in its admin form: seen with the services' view, changed with their update (SA-12). */
class ServiceConfigPolicy extends BasePolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.services.viewAny');
    }

    public function view(User|Staff $user, ServiceConfig $config): bool
    {
        return $this->adminPermission($user, 'admin.services.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.services.update');
    }

    public function update(User|Staff $user, ServiceConfig $config): bool
    {
        return $this->adminPermission($user, 'admin.services.update');
    }

    public function delete(User|Staff $user, ServiceConfig $config): bool
    {
        return $this->adminPermission($user, 'admin.services.update');
    }

    public function deleteAny(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.services.update');
    }
}
