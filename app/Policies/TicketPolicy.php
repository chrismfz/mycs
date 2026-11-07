<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Actor;
use Illuminate\Support\Facades\Auth;

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
        return $this->adminPermission($user, 'admin.tickets.viewAny', $this->owns($user, $ticket->client_id, 'tickets'));
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
        // Replying or closing in «Login as client» is acting on the ticket, where `tickets` there is only seeing it: the
        // admin's own right to do the same (SA-5, sweep AUTH-SECURITY-2).
        if ($user instanceof User && Actor::impersonating() && !self::impersonatorMay('admin.tickets.update')) {
            return false;
        }

        return $this->adminPermission($user, 'admin.tickets.update', $this->owns($user, $ticket->client_id, 'tickets'));
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

    /** Staff in «Login as client» hold this admin permission (false when not staff). */
    public static function impersonatorMay(string $permission): bool
    {
        $staff = Auth::guard('staff')->user();

        return $staff instanceof Staff && $staff->hasPermission($permission);
    }
}
