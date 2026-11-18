<?php

namespace App\Policies;

use App\Models\NotificationTemplate;
use App\Models\Staff;
use App\Models\User;

/**
 * Staff read the templates with «view»; their text is a full admin's only (security audit 2026-11-18). A mail's text
 * sees its data: a reset or invitation link, a new server's password, a guest's ticket link, an unsubscribe link. An
 * image or a link added to it sends them to another host, so a list of «private» templates was always one short (the
 * reviews found the guest ticket mails, then the product snippet): the case is cut, not listed.
 */
class NotificationTemplatePolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.notification_templates.viewAny');
    }

    public function view(User|Staff $user, NotificationTemplate $notificationTemplate): bool
    {
        return $user->hasPermission('admin.notification_templates.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('*');
    }

    public function update(User|Staff $user, NotificationTemplate $notificationTemplate): bool
    {
        return $user->hasPermission('*');
    }

    public function delete(User|Staff $user, NotificationTemplate $notificationTemplate): bool
    {
        return $user->hasPermission('*');
    }

    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('*');
    }
}
