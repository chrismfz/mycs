<?php

namespace App\Policies;

use App\Models\LiveChat;
use App\Models\Staff;
use App\Models\User;
use App\Support\LiveChats;

/**
 * Customers' live chats in the admin (docs/livechat.md §6α): read only, by LiveChats' own rule (F3). Nothing here creates,
 * changes or deletes a chat: those go through LiveChats only, and a customer never passes here.
 */
class LiveChatPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user instanceof Staff && LiveChats::isOperator($user);
    }

    public function view(User|Staff $user, LiveChat $chat): bool
    {
        return $user instanceof Staff && LiveChats::canSee($user, $chat);
    }

    public function create(User|Staff $user): bool
    {
        return false;
    }

    public function update(User|Staff $user, LiveChat $chat): bool
    {
        return false;
    }

    public function delete(User|Staff $user, LiveChat $chat): bool
    {
        return false;
    }
}
