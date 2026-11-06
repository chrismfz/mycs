<?php

namespace App\Http\Middleware;

use App\Http\Controllers\LegacyWhmcsController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WHMCS's index.php?rp=/store/… (and /?rp=…) on the home page: sent for good (301) where it leads here (the cut-over's
 * 301 map, LegacyWhmcsController). Laravel answers /index.php as the home page, so the map reads it here.
 */
class LegacyWhmcsRp
{
    public function handle(Request $request, Closure $next): Response
    {
        $rp = $request->query('rp');
        if ($request->isMethod('GET') && is_string($rp) && $rp !== '') {
            return redirect()->to(LegacyWhmcsController::rp(mb_substr($rp, 0, 500)), 301);
        }

        return $next($request);
    }
}
