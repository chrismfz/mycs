<?php

namespace App\Http\Controllers;

use App\Services\Licensing\LicenseTelemetry;
use App\Support\CallerIp;
use App\Services\Licensing\ZlicLimiter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api/v1/licensing/check (docs/licensing.md, «Τηλεμετρία»): the check of the new zonecloud releases. Its answer
 * is zlic.php's, decided by the same code with the same limits, byte for byte: a client that falls back on zlic.php when
 * this does not answer reads the same thing from both. Beyond it, the client's own description (`telemetry`: product,
 * version, build, install, clock, state), kept after the answer and never changing it. JSON or a form; `license` and
 * `license_PSK` as for zlic.php.
 */
class LicensingCheckController extends Controller
{
    public function __invoke(Request $request, ZlicLimiter $limiter, ZlicController $zlic): Response
    {
        $params = array_filter(['license' => $request->input('license'), 'license_PSK' => $request->input('license_PSK')], 'is_string');
        [$answer, $response] = $zlic->answer($request, $limiter, $params);

        // Not when a limit or an error answered: what the client said was not checked against anything.
        if ($response !== null && ($params['license'] ?? '') !== '') {
            LicenseTelemetry::record($params['license'], $response, CallerIp::of($request), $request->input('telemetry'));
        }

        return $answer;
    }
}
