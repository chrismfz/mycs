<?php

namespace App\Policies;

use App\Models\ChatNotificationRule;
use App\Models\Staff;
use App\Models\User;

class ChatNotificationRulePolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.chat_notifications.viewAny');
    }

    public function view(User|Staff $user, ChatNotificationRule $rule): bool
    {
        return $user->hasPermission('admin.chat_notifications.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.chat_notifications.create');
    }

    public function update(User|Staff $user, ChatNotificationRule $rule): bool
    {
        return $user->hasPermission('admin.chat_notifications.update');
    }

    public function delete(User|Staff $user, ChatNotificationRule $rule): bool
    {
        return $user->hasPermission('admin.chat_notifications.delete');
    }
}
