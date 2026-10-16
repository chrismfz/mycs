<?php

namespace App\Policies;

use App\Models\Domain;
use App\Models\Staff;
use App\Models\User;

/**
 * Domains in the admin (docs/domains.md, D1): seen by staff whose role may. Created, changed and ended through their
 * own actions (D2–D5), never as a plain form; a customer sees theirs with the client area's `domains` permission.
 */
class DomainPolicy extends BasePolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.domains.viewAny');
    }

    public function view(User|Staff $user, Domain $domain): bool
    {
        return $this->adminPermission($user, 'admin.domains.viewAny', $this->owns($user, $domain->client_id, 'domains'));
    }

    public function create(User|Staff $user): bool
    {
        return false;
    }

    public function update(User|Staff $user, Domain $domain): bool
    {
        return false;
    }

    public function delete(User|Staff $user, Domain $domain): bool
    {
        return false;
    }

    public function deleteAny(User|Staff $user): bool
    {
        return false;
    }
}
