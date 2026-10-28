<?php

namespace App\Policies;

use App\Models\CpanelAccount;
use App\Models\Staff;
use App\Models\User;

/** A WHM server's accounts belong to it: the servers' permissions (mapping one changes nothing on the server). */
class CpanelAccountPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.servers.viewAny');
    }

    public function view(User|Staff $user, CpanelAccount $account): bool
    {
        return $user->hasPermission('admin.servers.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return false; // read from the server only
    }

    public function update(User|Staff $user, CpanelAccount $account): bool
    {
        return $user->hasPermission('admin.servers.update');
    }

    public function delete(User|Staff $user, CpanelAccount $account): bool
    {
        return $user->hasPermission('admin.servers.update');
    }
}
