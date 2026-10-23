<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        if (session()->has('locale')) {
            $locale = session()->get('locale');
            // Only a language we have: a session from before one was dropped (2026-10-24) forgets it.
            if (is_string($locale) && array_key_exists($locale, (array) config('app.available_locales', []))) {
                App::setLocale($locale);
            } else {
                session()->forget('locale');
            }
        }

        return $next($request);
    }
}
