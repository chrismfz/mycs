<?php

namespace App\Policies;

use App\Models\ProxmoxGuest;
use App\Models\Staff;
use App\Models\User;

/** A cluster's guests belong to its server: the servers' permissions (mapping one changes nothing on the cluster). */
class ProxmoxGuestPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.servers.viewAny');
    }

    public function view(User|Staff $user, ProxmoxGuest $guest): bool
    {
        return $user->hasPermission('admin.servers.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return false; // read from the cluster only
    }

    public function update(User|Staff $user, ProxmoxGuest $guest): bool
    {
        return $user->hasPermission('admin.servers.update');
    }

    public function delete(User|Staff $user, ProxmoxGuest $guest): bool
    {
        return $user->hasPermission('admin.servers.update');
    }
}
