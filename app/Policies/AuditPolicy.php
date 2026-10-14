<?php

namespace App\Policies;

use App\Models\Audit;
use App\Models\User;

class AuditPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admin.audit.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Audit $role): bool
    {
        return $user->hasPermission('admin.audit.viewAny');
    }

    // Append-only (the money trail's rule, audit sweep AUTH-3): Filament allows what a policy does not define, so every
    // write is refused here.
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Audit $audit): bool
    {
        return false;
    }

    public function delete(User $user, Audit $audit): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Audit $audit): bool
    {
        return false;
    }

    public function forceDelete(User $user, Audit $audit): bool
    {
        return false;
    }
}
