<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;

class TicketPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.tickets.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User|Staff $user, Ticket $ticket): bool
    {
        return $this->adminPermission($user, 'admin.tickets.viewAny', $this->owns($user, $ticket->user_id));
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.tickets.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User|Staff $user, Ticket $ticket): bool
    {
        return $this->adminPermission($user, 'admin.tickets.update', $this->owns($user, $ticket->user_id));
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User|Staff $user, Ticket $ticket): bool
    {
        return $user->hasPermission('admin.tickets.delete');
    }

    /**
     * Determine whether the user can delete multiple models.
     */
    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.tickets.deleteAny');
    }
}
