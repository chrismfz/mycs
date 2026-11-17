<?php

namespace App\Policies;

use App\Helpers\NotificationHelper;
use App\Models\NotificationTemplate;
use App\Models\Staff;
use App\Models\User;

class NotificationTemplatePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.notification_templates.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User|Staff $user, NotificationTemplate $notificationTemplate): bool
    {
        return $user->hasPermission('admin.notification_templates.viewAny');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.notification_templates.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User|Staff $user, NotificationTemplate $notificationTemplate): bool
    {
        return $user->hasPermission(self::private($notificationTemplate) ? '*' : 'admin.notification_templates.update');
    }

    /**
     * A mail that carries a login link or a password (NotificationHelper::PRIVATE_TEMPLATES): its text is a full admin's
     * only, else an image or a link in it would send the reset link or the new server's password elsewhere (audit 2026-11-18).
     */
    private static function private(NotificationTemplate $template): bool
    {
        return in_array($template->key, NotificationHelper::PRIVATE_TEMPLATES, true);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User|Staff $user, NotificationTemplate $notificationTemplate): bool
    {
        return $user->hasPermission(self::private($notificationTemplate) ? '*' : 'admin.notification_templates.delete');
    }

    /**
     * Determine whether the user can bulk delete the model.
     */
    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.notification_templates.deleteAny');
    }
}
