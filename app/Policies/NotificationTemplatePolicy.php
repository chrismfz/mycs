<?php

namespace App\Policies;

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
        return $user->hasPermission('admin.notification_templates.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User|Staff $user, NotificationTemplate $notificationTemplate): bool
    {
        return $user->hasPermission('admin.notification_templates.delete');
    }

    /**
     * Determine whether the user can bulk delete the model.
     */
    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.notification_templates.deleteAny');
    }
}
