<?php

namespace App\Http\Middleware\Api;

use App\Classes\PermissionGuard;
use App\Models\ApiKey;
use App\Support\CallerIp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminApi
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request):Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Validate bearers token
        if (!$request->bearerToken()) {
            return response()->json(['error' => 'The request is missing a valid bearer token.'], 401);
        }

        $token = ApiKey::where('token', hash('sha256', $request->bearerToken()))
            ->where('enabled', true)
            ->firstOr(function () {
                abort(401, 'The provided API key is invalid or has been disabled.');
            });

        // Check if the token is of type 'admin'
        if ($token->type !== 'admin' || ($token->ip_addresses && !in_array(CallerIp::of($request), $token->ip_addresses))) {
            abort(403, 'You do not have permission to access this resource.');
        }

        $token->last_used_at = now();
        $token->saveQuietly();

        // Attach the token to the request for further use
        $request->attributes->set('api_key', $token);
        // A key is a member of staff's and never does more than they can do today (nothing once they are disabled). One
        // without an owner (made before keys had one) has nobody to bound it: refused.
        if ($token->staff === null) {
            abort(403, 'This API key has no owner; create a new one.');
        }
        $permissions = array_values(array_filter($token->permissions ?? [], fn (string $p) => PermissionGuard::canGrantApi($token->staff, $p)));
        $request->attributes->set('api_key_permissions', $permissions);

        return $next($request);
    }
}
