<?php

namespace App\Policies;

use App\Models\DomainRegistrarConnection;
use App\Models\Staff;
use App\Models\User;

/** Registrar connections (docs/domains.md D-21): staff only, by their role's permission. */
class DomainRegistrarConnectionPolicy extends BasePolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.domain_registrars.viewAny');
    }

    public function view(User|Staff $user, DomainRegistrarConnection $connection): bool
    {
        return $this->adminPermission($user, 'admin.domain_registrars.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.domain_registrars.create');
    }

    public function update(User|Staff $user, DomainRegistrarConnection $connection): bool
    {
        return $this->adminPermission($user, 'admin.domain_registrars.update');
    }

    public function delete(User|Staff $user, DomainRegistrarConnection $connection): bool
    {
        return $this->adminPermission($user, 'admin.domain_registrars.delete');
    }

    public function deleteAny(User|Staff $user): bool
    {
        return false;
    }
}
