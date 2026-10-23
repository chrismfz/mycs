<?php

namespace App\Policies;

use App\Models\ClientUser;
use App\Models\Staff;
use App\Models\User;

/** An account's logins in the admin (its Users tab): seen with the clients' view, changed with their update (SA-12). */
class ClientUserPolicy extends BasePolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.clients.viewAny');
    }

    public function view(User|Staff $user, ClientUser $grant): bool
    {
        return $this->adminPermission($user, 'admin.clients.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.clients.update');
    }

    public function update(User|Staff $user, ClientUser $grant): bool
    {
        return $this->adminPermission($user, 'admin.clients.update');
    }

    public function delete(User|Staff $user, ClientUser $grant): bool
    {
        return $this->adminPermission($user, 'admin.clients.update');
    }

    public function deleteAny(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.clients.update');
    }
}
