<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\User;

class EmailLogPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.email_logs.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User|Staff $user): bool
    {
        return $user->hasPermission('admin.email_logs.view');
    }

    /** Send a logged mail again, to its address or another (the mail audit, docs/mail.md). */
    public function resend(User|Staff $user): bool
    {
        return $user->hasPermission('admin.email_logs.resend');
    }
}
