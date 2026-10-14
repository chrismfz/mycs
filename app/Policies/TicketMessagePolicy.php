<?php

namespace App\Policies;

use App\Models\TicketMessage;
use App\Models\Staff;
use App\Models\User;

class TicketMessagePolicy
{
    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User|Staff $user, TicketMessage $ticket): bool
    {
        return $user->hasPermission('admin.ticket_messages.delete');
    }
}
