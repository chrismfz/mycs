<?php

namespace App\Policies;

use App\Models\ClientNote;
use App\Models\Staff;
use App\Models\User;

/**
 * An account's notes are staff's (#243): read by who may see clients, written by who may update them. Never a
 * customer's, whatever the account (no condition for them). Without a policy Filament allows everything (reviews of #243).
 */
class ClientNotePolicy extends BasePolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.clients.viewAny');
    }

    public function view(User|Staff $user, ClientNote $note): bool
    {
        return $this->adminPermission($user, 'admin.clients.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.clients.update');
    }

    public function update(User|Staff $user, ClientNote $note): bool
    {
        return $this->adminPermission($user, 'admin.clients.update');
    }

    public function delete(User|Staff $user, ClientNote $note): bool
    {
        return $this->adminPermission($user, 'admin.clients.update');
    }

    public function deleteAny(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.clients.update');
    }

    public function restore(User|Staff $user, ClientNote $note): bool
    {
        return false;
    }

    public function forceDelete(User|Staff $user, ClientNote $note): bool
    {
        return false;
    }
}
