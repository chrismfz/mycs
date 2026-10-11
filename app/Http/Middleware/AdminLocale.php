<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * The admin panel uses its own language setting (admin_language, default English),
 * independent of the client area's default or the language a visitor picked.
 */
class AdminLocale
{
    public function handle(Request $request, Closure $next)
    {
        App::setLocale((string) config('settings.admin_language', 'en'));

        return $next($request);
    }
}
