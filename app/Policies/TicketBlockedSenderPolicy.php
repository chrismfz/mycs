<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\TicketBlockedSender;
use App\Models\User;

class TicketBlockedSenderPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.ticket_blocked_senders.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.ticket_blocked_senders.create');
    }

    public function update(User|Staff $user, TicketBlockedSender $sender): bool
    {
        return $this->create($user);
    }

    public function delete(User|Staff $user, TicketBlockedSender $sender): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.ticket_blocked_senders.delete');
    }
}
