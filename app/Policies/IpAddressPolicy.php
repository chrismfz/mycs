<?php

namespace App\Policies;

use App\Models\IpAddress;
use App\Models\Staff;
use App\Models\User;

/** As its pool's; one a service holds is released before it is deleted. */
class IpAddressPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.servers.viewAny');
    }

    public function view(User|Staff $user, IpAddress $address): bool
    {
        return $user->hasPermission('admin.servers.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.servers.update');
    }

    public function update(User|Staff $user, IpAddress $address): bool
    {
        return $user->hasPermission('admin.servers.update');
    }

    public function delete(User|Staff $user, IpAddress $address): bool
    {
        return $user->hasPermission('admin.servers.update') && $address->service_id === null;
    }
}
