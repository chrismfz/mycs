<?php

namespace App\Policies;

use App\Models\ClientUser;
use App\Models\Staff;
use App\Models\User;
use App\Support\Actor;

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
     * It is the account's, the login has a live grant on that account (docs/accounts.md, PR 2a) and the grant allows
     * the area (WHMCS permission names, PR 2b; the owner has them all): only a customer login owns anything, never
     * staff with the same id. Staff in «Login as client» act as the owner (full control, decision 2026-10-14).
     */
    protected function owns(User|Staff $user, mixed $clientId, ?string $permission = null): bool
    {
        if (!$user instanceof User || $clientId === null) {
            return false;
        }
        $grant = ClientUser::query()->where('user_id', $user->id)->where('client_id', (int) $clientId)->whereNull('revoked_at')->first();

        return $grant !== null && ($permission === null || Actor::impersonating() || $grant->allows($permission));
    }
}
