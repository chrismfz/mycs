<?php

namespace App\Http\Middleware;

use App\Models\McpOauthGrant;
use App\Services\Mcp\McpAccess;
use App\Services\Mcp\McpOauth;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The MCP server's door (docs/mcp.md). Either bearer: an admin API key that may open the MCP (McpAccess: enabled, the
 * permission, its owner active with it, its IP allowlist by the connection's address), or an OAuth access token a
 * member of staff approved (McpOauth: checked again on every call, with the key it was approved with). The request then
 * acts as that member of staff (the staff guard, this request only: no session). 120 calls a minute a key or grant.
 * Staff's alone: customers have no tokens here (the Passport server was removed). A 401 names the resource metadata, so an OAuth client
 * (the claude.ai connector) finds the way in.
 */
class McpAuth
{
    public const PERMISSION = McpAccess::PERMISSION;

    public const PER_MINUTE = 120;

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = (string) $request->bearerToken();
        if ($bearer === '') {
            return self::deny(401, 'A bearer is required: an admin API key with «Use the MCP Server», or an OAuth access token.');
        }

        if (str_starts_with($bearer, McpOauth::ACCESS_PREFIX)) {
            [$staff, $grant] = McpOauth::staffForAccessToken($bearer) ?? [null, null];
            if ($staff === null || !$grant instanceof McpOauthGrant) {
                return self::deny(401, 'The access token is invalid, expired or revoked.');
            }
            // Approved with a key: the key's allowlist still applies, by the connection's address.
            if ($grant->apiKey !== null && McpAccess::keyStaff($grant->apiKey, $request) === null) {
                return self::deny(403, 'This key may not be used from here.');
            }
            $limit = 'mcp:grant:' . $grant->id;
            $grant->forceFill(['last_used_at' => now()])->saveQuietly();
            $request->attributes->set('mcp_grant', $grant);
            $key = $grant->apiKey;
        } else {
            $key = McpAccess::keyFromBearer($bearer);
            if ($key === null || !$key->enabled) {
                return self::deny(401, 'The API key is invalid or disabled.');
            }
            $staff = McpAccess::keyStaff($key, $request);
            if ($staff === null) {
                return self::deny(403, 'This key, or its owner, may not use the MCP server from here.');
            }
            $limit = 'mcp:' . $key->id;
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        if (RateLimiter::tooManyAttempts($limit, self::PER_MINUTE)) {
            return self::deny(429, 'Too many calls: try again in a minute.');
        }
        RateLimiter::hit($limit, 60);

        Auth::shouldUse('staff');
        Auth::guard('staff')->setUser($staff);
        if ($key !== null) {
            $request->attributes->set('api_key', $key);
        }

        return $next($request);
    }

    private static function deny(int $status, string $message): Response
    {
        $response = response()->json(['error' => $message], $status);
        if ($status === 401) {
            $response->headers->set('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="' . route('mcp.oauth.protected-resource.nested', ['path' => 'mcp']) . '"');
        }

        return $response;
    }
}
