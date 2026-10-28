<?php

namespace App\Policies;

use App\Models\IpPool;
use App\Models\Staff;
use App\Models\User;

/** The VPS networks are the servers': the servers' permissions; a pool with an address given is not deleted. */
class IpPoolPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.servers.viewAny');
    }

    public function view(User|Staff $user, IpPool $pool): bool
    {
        return $user->hasPermission('admin.servers.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.servers.update');
    }

    public function update(User|Staff $user, IpPool $pool): bool
    {
        return $user->hasPermission('admin.servers.update');
    }

    public function delete(User|Staff $user, IpPool $pool): bool
    {
        return $user->hasPermission('admin.servers.update') && !$pool->addresses()->whereNotNull('service_id')->exists();
    }
}
