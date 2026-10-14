<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\User;

class FailedJobPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.failed_jobs.viewAny');
    }

    /** A retry runs the job again (a payment, provisioning): its own permission (AUTH-3). */
    public function retry(User|Staff $user): bool
    {
        return $user->hasPermission('admin.failed_jobs.retry');
    }

    public function delete(User|Staff $user): bool
    {
        return $user->hasPermission('admin.failed_jobs.delete');
    }

    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.failed_jobs.delete');
    }
}
