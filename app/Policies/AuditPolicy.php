<?php

namespace App\Policies;

use App\Models\Audit;
use App\Models\Staff;
use App\Models\User;

class AuditPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.audit.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User|Staff $user, Audit $role): bool
    {
        return $user->hasPermission('admin.audit.viewAny');
    }

    // Append-only (the money trail's rule, audit sweep AUTH-3): Filament allows what a policy does not define, so every
    // write is refused here.
    public function create(User|Staff $user): bool
    {
        return false;
    }

    public function update(User|Staff $user, Audit $audit): bool
    {
        return false;
    }

    public function delete(User|Staff $user, Audit $audit): bool
    {
        return false;
    }

    public function deleteAny(User|Staff $user): bool
    {
        return false;
    }

    public function restore(User|Staff $user, Audit $audit): bool
    {
        return false;
    }

    public function forceDelete(User|Staff $user, Audit $audit): bool
    {
        return false;
    }
}
