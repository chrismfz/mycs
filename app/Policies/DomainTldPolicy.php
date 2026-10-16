<?php

namespace App\Policies;

use App\Models\DomainTld;
use App\Models\Staff;
use App\Models\User;

/** The TLDs MyCS sells (docs/domains.md D-7): staff only, by their role's permission. */
class DomainTldPolicy extends BasePolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.domain_tlds.viewAny');
    }

    public function view(User|Staff $user, DomainTld $tld): bool
    {
        return $this->adminPermission($user, 'admin.domain_tlds.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.domain_tlds.create');
    }

    public function update(User|Staff $user, DomainTld $tld): bool
    {
        return $this->adminPermission($user, 'admin.domain_tlds.update');
    }

    public function delete(User|Staff $user, DomainTld $tld): bool
    {
        return $this->adminPermission($user, 'admin.domain_tlds.delete');
    }

    public function deleteAny(User|Staff $user): bool
    {
        return false;
    }
}
