<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\TicketMailImport;
use App\Models\User;

/** The Ticket Mail Import Log: to staff who see tickets; written only by the poller. */
class TicketMailImportPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.tickets.viewAny');
    }

    public function view(User|Staff $user, TicketMailImport $import): bool
    {
        return $this->viewAny($user);
    }

    public function create(User|Staff $user): bool
    {
        return false;
    }

    public function update(User|Staff $user, TicketMailImport $import): bool
    {
        return false;
    }

    public function delete(User|Staff $user, TicketMailImport $import): bool
    {
        return false;
    }

    public function deleteAny(User|Staff $user): bool
    {
        return false;
    }
}
