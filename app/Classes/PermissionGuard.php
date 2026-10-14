<?php

namespace App\Classes;

use App\Models\Staff;
use Illuminate\Support\Arr;

/**
 * "Nobody grants what they do not have" (docs/history/audit-security.md M5). Staff with
 * users/roles/api_keys rights could otherwise hand out, or take over, more power than
 * their own role carries: an API key with every permission, a role with '*', another
 * admin's password or email.
 */
class PermissionGuard
{
    /**
     * API permissions whose name does not match a role permission (after view → viewAny).
     * Anything not here and not matching needs a full ('*') admin.
     */
    private const API_TO_ROLE = [
        'admin.invoice_items.create' => 'admin.invoices.update',
        'admin.invoice_items.update' => 'admin.invoices.update',
        'admin.invoice_items.delete' => 'admin.invoices.update',
        'admin.invoice_items.view' => 'admin.invoices.viewAny',
        'admin.ticket_messages.create' => 'admin.tickets.update',
        'admin.ticket_messages.view' => 'admin.tickets.viewAny',
        'admin.properties.view' => 'admin.clients.viewAny', // the account's details (PR 2a)
    ];

    public static function isFullAdmin(?Staff $actor): bool
    {
        return $actor !== null && $actor->active && $actor->role !== null && in_array('*', $actor->role->permissions ?? [], true);
    }

    /** @param  array<int, string>  $permissions  role permissions */
    public static function canGrant(?Staff $actor, array $permissions): bool
    {
        if (self::isFullAdmin($actor)) {
            return true;
        }
        if ($actor === null || in_array('*', $permissions, true)) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (!$actor->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    /** The role permission an API permission stands for; null when only a full admin may grant it. */
    public static function roleFor(string $apiPermission): ?string
    {
        if (isset(self::API_TO_ROLE[$apiPermission])) {
            return self::API_TO_ROLE[$apiPermission];
        }
        $role = preg_replace('/\.view$/', '.viewAny', $apiPermission);

        return array_key_exists($role, Arr::dot(config('permissions.role'))) ? $role : null;
    }

    public static function canGrantApi(?Staff $actor, string $apiPermission): bool
    {
        if (self::isFullAdmin($actor)) {
            return true;
        }
        $role = self::roleFor($apiPermission);

        return $role !== null && self::canGrant($actor, [$role]);
    }

    /** @param  array<int, string>  $apiPermissions */
    public static function canGrantAllApi(?Staff $actor, array $apiPermissions): bool
    {
        foreach ($apiPermissions as $permission) {
            if (!self::canGrantApi($actor, $permission)) {
                return false;
            }
        }

        return true;
    }

    /** May $actor change $target's account (password, email, role) or act as them? */
    public static function outranks(?Staff $actor, Staff $target): bool
    {
        if ($actor === null) {
            return false;
        }
        if ($actor->is($target) || $target->role === null) {
            return true;
        }

        return self::canGrant($actor, $target->role->permissions ?? []);
    }
}
