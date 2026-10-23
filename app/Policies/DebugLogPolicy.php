<?php

namespace App\Policies;

use App\Models\DebugLog;
use App\Models\Staff;
use App\Models\User;

/** The debug logs (HTTP and errors): seen and deleted with their own permissions, never made or changed (SA-12). */
class DebugLogPolicy extends BasePolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.debug_logs.view');
    }

    public function view(User|Staff $user, DebugLog $log): bool
    {
        return $this->adminPermission($user, 'admin.debug_logs.view');
    }

    public function create(User|Staff $user): bool
    {
        return false;
    }

    public function update(User|Staff $user, DebugLog $log): bool
    {
        return false;
    }

    public function delete(User|Staff $user, DebugLog $log): bool
    {
        return $this->adminPermission($user, 'admin.debug_logs.delete');
    }

    public function deleteAny(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.debug_logs.delete');
    }
}
