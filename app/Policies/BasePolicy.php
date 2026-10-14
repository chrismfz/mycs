<?php

namespace App\Policies;

use App\Models\ClientUser;
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

    /**
     * It is the account's, and the login has a live grant on that account (docs/accounts.md, PR 2a): only a customer
     * login owns anything, never staff with the same id. 2b adds the grant's permission.
     */
    protected function owns(User|Staff $user, mixed $clientId): bool
    {
        return $user instanceof User && $clientId !== null
            && ClientUser::query()->where('user_id', $user->id)->where('client_id', (int) $clientId)->whereNull('revoked_at')->exists();
    }
}
