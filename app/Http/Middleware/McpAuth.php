<?php

namespace App\Http\Middleware;

use App\Classes\PermissionGuard;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The MCP server's door (docs/mcp.md): an admin API key (sha256 of the bearer, enabled, its IP allowlist), with «Use
 * the MCP Server» among the permissions its owner can still give, and its owner an active member of staff. The request
 * then acts as that member of staff (the staff guard, for this request only: no session). 120 calls a minute a key.
 * Never the customers' Passport tokens: /mcp is staff's alone.
 */
class McpAuth
{
    public const PERMISSION = 'admin.mcp.use';

    public const PER_MINUTE = 120;

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if (!$bearer) {
            return self::deny(401, 'A bearer token (an admin API key with «Use the MCP Server») is required.');
        }
        $key = ApiKey::query()->where('token', hash('sha256', $bearer))->where('enabled', true)->first();
        if ($key === null) {
            return self::deny(401, 'The API key is invalid or disabled.');
        }
        if ($key->type !== 'admin' || ($key->ip_addresses && !in_array($request->ip(), $key->ip_addresses, true))) {
            return self::deny(403, 'This key may not be used from here.');
        }
        $staff = $key->staff;
        if ($staff === null || !$staff->active || !in_array(self::PERMISSION, $key->permissions ?? [], true)
            || !PermissionGuard::canGrantApi($staff, self::PERMISSION)) {
            return self::deny(403, 'This key, or its owner, may not use the MCP server.');
        }
        if (RateLimiter::tooManyAttempts($limit = 'mcp:' . $key->id, self::PER_MINUTE)) {
            return self::deny(429, 'Too many calls: try again in a minute.');
        }
        RateLimiter::hit($limit, 60);

        $key->forceFill(['last_used_at' => now()])->saveQuietly();
        Auth::shouldUse('staff');
        Auth::guard('staff')->setUser($staff);
        $request->attributes->set('api_key', $key);

        return $next($request);
    }

    private static function deny(int $status, string $message): Response
    {
        return response()->json(['error' => $message], $status);
    }
}
