<?php

namespace App\Policies;

use App\Models\ServerGroup;
use App\Models\Staff;
use App\Models\User;

/** Server groups are part of the servers' setup: the servers' permissions. */
class ServerGroupPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.servers.viewAny');
    }

    public function view(User|Staff $user, ServerGroup $group): bool
    {
        return $user->hasPermission('admin.servers.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.servers.create');
    }

    public function update(User|Staff $user, ServerGroup $group): bool
    {
        return $user->hasPermission('admin.servers.update');
    }

    public function delete(User|Staff $user, ServerGroup $group): bool
    {
        return $user->hasPermission('admin.servers.delete');
    }

    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.servers.deleteAny');
    }
}
