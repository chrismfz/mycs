<?php

namespace App\Http\Controllers;

use App\Services\Licensing\LicenseTelemetry;
use App\Services\Licensing\LicenseTokens;
use App\Services\Licensing\ZlicLimiter;
use App\Support\CallerIp;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /licensing/v1/check (docs/licensing.md, «Τηλεμετρία»): the check of the new zonecloud releases. Its answer
 * is zlic.php's, decided by the same code with the same limits, byte for byte: a client that falls back on zlic.php when
 * this does not answer reads the same thing from both. Beyond it, the client's own description (`telemetry`: product,
 * version, build, install, clock, state, its check of the token), kept after the answer and never changing it; and, with
 * «Active», a signed `token` (LicenseTokens). JSON or a form; `license` and `license_PSK` as for zlic.php.
 */
class LicensingCheckController extends Controller
{
    public function __invoke(Request $request, ZlicLimiter $limiter, ZlicController $zlic): Response
    {
        $params = array_filter(['license' => $request->input('license'), 'license_PSK' => $request->input('license_PSK')], 'is_string');
        [$answer, $response] = $zlic->answer($request, $limiter, $params);

        // Only from where the license runs (LicenseTelemetry::fromItsPlace): not when a limit or an error answered, nor from
        // anyone who only knows a key (reviews R1 and 2-1 of #863).
        if ($response === null || ($params['license'] ?? '') === '') {
            return $answer;
        }
        LicenseTelemetry::record($params['license'], $response, CallerIp::of($request), $request->input('telemetry'));

        // zlic.php's answer plus `token`, the one field it does not have (LicenseTokens: Active only, when a key is set).
        // Its other fields, md5hash and localkey among them, stay as they were: the token is outside what they sign.
        $token = LicenseTokens::forAnswer($params['license'], CallerIp::of($request), $response);
        if ($token === null) {
            return $answer;
        }

        return response((string) json_encode($response->body + ['token' => $token]), $response->httpStatus, ['Content-Type' => 'application/json']);
    }
}
