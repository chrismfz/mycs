<?php

namespace App\Policies;

use App\Models\DomainTldPrice;
use App\Models\Staff;
use App\Models\User;

/** A TLD's prices: changed by those who may change the TLD (docs/domains.md D-6). */
class DomainTldPricePolicy extends BasePolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.domain_tlds.viewAny');
    }

    public function view(User|Staff $user, DomainTldPrice $price): bool
    {
        return $this->adminPermission($user, 'admin.domain_tlds.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.domain_tlds.update');
    }

    public function update(User|Staff $user, DomainTldPrice $price): bool
    {
        return $this->adminPermission($user, 'admin.domain_tlds.update');
    }

    public function delete(User|Staff $user, DomainTldPrice $price): bool
    {
        return $this->adminPermission($user, 'admin.domain_tlds.update');
    }

    public function deleteAny(User|Staff $user): bool
    {
        return false;
    }
}
