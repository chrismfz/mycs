<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\Staff;
use App\Models\User;

/** An account's contacts in the admin: seen with the clients' view, changed with their update (SA-12). */
class ContactPolicy extends BasePolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.clients.viewAny');
    }

    public function view(User|Staff $user, Contact $contact): bool
    {
        return $this->adminPermission($user, 'admin.clients.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.clients.update');
    }

    public function update(User|Staff $user, Contact $contact): bool
    {
        return $this->adminPermission($user, 'admin.clients.update');
    }

    public function delete(User|Staff $user, Contact $contact): bool
    {
        return $this->adminPermission($user, 'admin.clients.update');
    }

    public function deleteAny(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.clients.update');
    }
}
