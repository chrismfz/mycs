<?php

namespace App\Http\Middleware;

use App\Helpers\ExtensionHelper;
use Closure;
use Illuminate\Http\Request;
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
        abort_unless(ExtensionHelper::boots($type, $extension), 404);

        return $next($request);
    }
}
