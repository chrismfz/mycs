<?php

namespace App\Http\Controllers;

use App\Services\Ops\Health;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * GET /health, for a monitor (docs/ops.md). The status code is whether the instance works: 503 when a critical check
 * fails (Health::CRITICAL: the database, the scheduler), or the report cannot be read; 200 otherwise. The body is the
 * whole state in one word (ok / warn / fail), for a keyword monitor that also wants what needs staff (a mailbox not
 * read, a stuck mail), and nothing else to anyone. The full report only with MYCS_HEALTH_TOKEN (32 characters or more).
 * The checks of the last minute (Health::cached()): no number of calls runs them more than once a minute.
 */
class HealthController extends Controller
{
    /** A shorter token is taken as unset (review H5 of #259). */
    public const TOKEN_MIN_LENGTH = 32;

    public function __invoke(Request $request): Response
    {
        try {
            $report = Health::cached();
        } catch (Throwable $e) {
            self::reportOncePerMinute($e);
            $report = ['status' => Health::FAIL, 'checks' => []];
        }
        $down = $report['checks'] === [] || collect(Health::CRITICAL)->contains(fn (string $name) => ($report['checks'][$name]['status'] ?? Health::FAIL) === Health::FAIL);
        $code = $down ? 503 : 200;
        $headers = ['Cache-Control' => 'no-store'];

        $token = config('services.health.token');
        $given = (string) $request->bearerToken();
        if (is_string($token) && strlen($token) >= self::TOKEN_MIN_LENGTH && $given !== '' && hash_equals($token, $given)) {
            return response(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $code,
                $headers + ['Content-Type' => 'application/json']);
        }

        return response($report['status'], $code, $headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** A cache down is said once a minute, not on every monitor's call (review H6 of #259). */
    private static function reportOncePerMinute(Throwable $e): void
    {
        $mark = storage_path('framework/health-report-failed');
        if (!is_file($mark) || filemtime($mark) < time() - 60) {
            @touch($mark);
            report($e);
        }
    }
}
