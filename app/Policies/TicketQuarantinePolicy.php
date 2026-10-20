<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\TicketQuarantine;
use App\Models\User;

/** The quarantine is staff's only: it holds mail of anyone (docs/tickets.md §3 Β). */
class TicketQuarantinePolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.ticket_quarantine.viewAny');
    }

    public function view(User|Staff $user, TicketQuarantine $quarantine): bool
    {
        return $this->viewAny($user);
    }

    /** Attach, open a ticket, dismiss, block the sender. */
    public function update(User|Staff $user, TicketQuarantine $quarantine): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.ticket_quarantine.update') && $quarantine->isPending();
    }

    public function create(User|Staff $user): bool
    {
        return false;
    }

    public function delete(User|Staff $user, TicketQuarantine $quarantine): bool
    {
        return false;
    }
}
