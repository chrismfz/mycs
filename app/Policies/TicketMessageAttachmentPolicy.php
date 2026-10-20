<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\TicketMessageAttachment;
use App\Models\User;

class TicketMessageAttachmentPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view(User|Staff $user, TicketMessageAttachment $attachment): bool
    {
        if ($user->hasPermission('admin.tickets.viewAny')) {
            return true;
        }

        // An internal note, and its files, are staff's only.
        return !$attachment->ticketMessage->is_internal && $this->owns($user, $attachment->ticketMessage->ticket->client_id, 'tickets');
    }
}
