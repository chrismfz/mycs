<?php

namespace App\Policies;

use App\Models\DomainRegistrarLog;
use App\Models\Staff;
use App\Models\User;

/** A domain's calls to its registrar: a read-only log, seen with the view permission of what it belongs to (SA-12). */
class DomainRegistrarLogPolicy extends BasePolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.domains.viewAny');
    }

    public function view(User|Staff $user, DomainRegistrarLog $log): bool
    {
        return $this->adminPermission($user, 'admin.domains.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return false;
    }

    public function update(User|Staff $user, DomainRegistrarLog $log): bool
    {
        return false;
    }

    public function delete(User|Staff $user, DomainRegistrarLog $log): bool
    {
        return false;
    }

    public function deleteAny(User|Staff $user): bool
    {
        return false;
    }
}
