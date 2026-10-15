<?php

namespace App\Services\Mcp;

use App\Classes\PermissionGuard;
use App\Models\ApiKey;
use App\Models\Staff;
use App\Support\CallerIp;
use Illuminate\Http\Request;

/**
 * Who may use the MCP server (docs/mcp.md), one rule for every way in: an active member of staff whose role has «Use
 * the MCP Server»; through a key, an enabled admin key with that permission, its owner such a member of staff, called
 * from an address its allowlist names (the connection's, CallerIp).
 */
final class McpAccess
{
    public const PERMISSION = 'admin.mcp.use';

    public static function staffMay(?Staff $staff): bool
    {
        return $staff !== null && $staff->active && PermissionGuard::canGrantApi($staff, self::PERMISSION);
    }

    /** The key's owner when the key may open the MCP; null otherwise. The address is checked only with a request. */
    public static function keyStaff(?ApiKey $key, ?Request $request = null): ?Staff
    {
        if ($key === null || !$key->enabled || $key->type !== 'admin' || !in_array(self::PERMISSION, $key->permissions ?? [], true)) {
            return null;
        }
        if ($request !== null && $key->ip_addresses && !in_array(CallerIp::of($request), $key->ip_addresses, true)) {
            return null;
        }
        $staff = $key->staff;

        return self::staffMay($staff) ? $staff : null;
    }

    public static function keyFromBearer(string $bearer): ?ApiKey
    {
        return ApiKey::query()->where('token', hash('sha256', $bearer))->first();
    }
}
