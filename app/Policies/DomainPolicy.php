<?php

namespace App\Policies;

use App\Models\Domain;
use App\Models\Staff;
use App\Models\User;

/**
 * Domains in the admin (docs/domains.md, D1): seen by staff whose role may. Created, changed and ended through their
 * own actions (D2–D5), never as a plain form; a customer sees theirs with the client area's `domains` permission and
 * manages them with `managedomains` (D7-2).
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

    /**
     * The customer manages theirs in the client area (D7-2: nameservers, EPP code, auto renew, renew) with `managedomains`
     * (WHMCS's name). Staff use their own actions and permissions (admin.domains.*), not this.
     */
    public function update(User|Staff $user, Domain $domain): bool
    {
        return !$domain->status->isTerminal() && $this->owns($user, $domain->client_id, 'managedomains');
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
