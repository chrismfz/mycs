<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\Staff;
use App\Models\User;

/** The customer accounts in the admin (docs/accounts.md, PR 2a): staff by their role's permission only. */
class ClientPolicy extends BasePolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.clients.viewAny');
    }

    public function view(User|Staff $user, Client $client): bool
    {
        return $user->hasPermission('admin.clients.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.clients.create');
    }

    public function update(User|Staff $user, Client $client): bool
    {
        return $user->hasPermission('admin.clients.update');
    }

    public function delete(User|Staff $user, Client $client): bool
    {
        return $user->hasPermission('admin.clients.delete');
    }

    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.clients.delete');
    }
}
