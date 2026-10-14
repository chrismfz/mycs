<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\User;

class BasePolicy
{
    /**
     * Staff by their role's permission; a customer by the condition (it is theirs). Staff and customers have their own
     * tables and ids (docs/accounts.md, PR 1): a staff member never passes as the owner of a customer's record.
     */
    protected function adminPermission(User|Staff $user, string $permission, bool $orCondition = false): bool
    {
        return $user instanceof Staff ? $user->hasPermission($permission) : $orCondition;
    }

    /** It is the customer's own: only a customer owns anything, never staff with the same id. */
    protected function owns(User|Staff $user, mixed $userId): bool
    {
        return $user instanceof User && $userId !== null && (int) $userId === $user->id;
    }
}
