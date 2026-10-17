<?php

namespace App\Policies;

use App\Models\ModuleAction;
use App\Models\Staff;
use App\Models\User;

/** Module actions belong to the services: the services' permissions (retrying one acts on the service). */
class ModuleActionPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.services.viewAny');
    }

    public function view(User|Staff $user, ModuleAction $action): bool
    {
        return $user->hasPermission('admin.services.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return false; // recorded by the modules only
    }

    public function update(User|Staff $user, ModuleAction $action): bool
    {
        // A domain's renewal: Retry charges its registrar, «renewed by hand» applies a paid renewal (D5b-2 review 8).
        if ($action->domain_id !== null) {
            return $user->hasPermission('admin.domains.renew');
        }

        return $user->hasPermission('admin.services.update');
    }

    public function delete(User|Staff $user, ModuleAction $action): bool
    {
        return false; // the log stays
    }
}
