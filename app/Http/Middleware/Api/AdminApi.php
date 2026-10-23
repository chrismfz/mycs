<?php

namespace App\Http\Middleware\Api;

use App\Classes\PermissionGuard;
use App\Models\ApiKey;
use App\Support\CallerIp;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/** Marked as authentication (AuthenticatesRequests): it runs before route-model binding, so a request without a key
 * learns nothing about which records exist (404 or 401: security audit API-8). */
class AdminApi implements AuthenticatesRequests
{
    public const BAD_PER_MINUTE = 60;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request):Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Running first, before the route's throttle, it limits the requests without a valid key itself: so many a
        // minute from one network (review of PR #90, R1).
        // A valid key is never held back by others' bad tries on its network (review of #369).
        $token = $request->bearerToken() ? ApiKey::where('token', hash('sha256', $request->bearerToken()))->where('enabled', true)->first() : null;
        if ($token === null) {
            $bad = 'admin-api-bad:' . CallerIp::key($request);
            if (RateLimiter::tooManyAttempts($bad, self::BAD_PER_MINUTE)) {
                return response()->json(['error' => 'Too many requests.'], 429, ['Retry-After' => RateLimiter::availableIn($bad)]);
            }
            RateLimiter::hit($bad, 60);

            return $request->bearerToken()
                ? response()->json(['message' => 'The provided API key is invalid or has been disabled.'], 401)
                : response()->json(['error' => 'The request is missing a valid bearer token.'], 401);
        }

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
