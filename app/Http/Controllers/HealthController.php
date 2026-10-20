<?php

namespace App\Http\Controllers;

use App\Services\Ops\Health;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * GET /health, for a monitor (docs/ops.md): «ok» or «warn» with 200, «fail» with 503, and nothing else to anyone; the
 * full report (Health's checks) with the token of MYCS_HEALTH_TOKEN. The checks of the last minute (Health::cached()),
 * so no number of calls runs them more than once a minute. A report that cannot be read is a failure, never an error page.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request): Response
    {
        try {
            $report = Health::cached();
        } catch (Throwable $e) {
            report($e);
            $report = ['status' => Health::FAIL, 'checks' => []];
        }
        $code = $report['status'] === Health::FAIL ? 503 : 200;
        $headers = ['Cache-Control' => 'no-store'];

        $token = config('services.health.token');
        $given = (string) $request->bearerToken();
        if (is_string($token) && $token !== '' && $given !== '' && hash_equals($token, $given)) {
            return response(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $code, $headers + ['Content-Type' => 'application/json']);
        }

        return response($report['status'], $code, $headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
