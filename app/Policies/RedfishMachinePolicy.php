<?php

namespace App\Policies;

use App\Models\RedfishMachine;
use App\Models\Staff;
use App\Models\User;

/** A machine's read belongs to its server: the servers' permissions (as a cluster's guests). */
class RedfishMachinePolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.servers.viewAny');
    }

    public function view(User|Staff $user, RedfishMachine $machine): bool
    {
        return $user->hasPermission('admin.servers.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return false; // a machine is a server (Servers → Create server)
    }

    /** Reading its BMC now. */
    public function update(User|Staff $user, RedfishMachine $machine): bool
    {
        return $user->hasPermission('admin.servers.update');
    }

    public function delete(User|Staff $user, RedfishMachine $machine): bool
    {
        return false; // gone with its server
    }
}
