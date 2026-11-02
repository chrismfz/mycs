<?php

namespace App\Http\Middleware;

use App\Helpers\ExtensionHelper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * An extension's route (routes/extensions.php) answers only while the extension boots: a gateway's webhook while the
 * gateway has a record, enabled or not (a payment taken before it was disabled is still refunded or disputed). Without
 * one, a 404, as when the route was added by boot() and so missing.
 */
class EnsureExtensionBoots
{
    public function handle(Request $request, Closure $next, string $type, string $extension): Response
    {
        abort_unless(self::answers($request), 404);

        return $next($request);
    }

    /**
     * Whether the request's route answers: true for any route but an extension's whose extension does not boot. Read
     * once a request. The gateway log and its throttle ask too (AppServiceProvider): a route that answers 404 here is
     * as a path that matched none, neither logged nor counted as a refused callback (review of #579).
     */
    public static function answers(Request $request): bool
    {
        if (!$request->attributes->has(self::class)) {
            $guard = collect($request->route()?->middleware() ?? [])
                ->first(fn (string $middleware) => str_starts_with($middleware, self::class . ':'));
            [$type, $extension] = $guard === null ? [null, null] : explode(',', Str::after($guard, ':'), 2) + [1 => ''];
            $request->attributes->set(self::class, $type === null || ExtensionHelper::boots($type, $extension));
        }

        return (bool) $request->attributes->get(self::class);
    }
}
