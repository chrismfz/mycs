<?php

namespace App\Http\Controllers;

use App\Services\Licensing\LicenseActivity;
use App\Services\Licensing\MycsLicenseRepository;
use App\Support\CallerIp;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Nixpal\Licensing\Zlic;
use Throwable;

/**
 * clients.nixpal.com/zlic.php (CLAUDE.md: same URL, same input, same JSON; md5hash and localkey byte-for-byte with
 * WHMCS). All the logic is packages/nixpal-licensing's Zlic; here the MyCS repository, POST before GET (the C++ clients
 * send GET), the caller's address, and its signs of life. No session, cookie or CSRF: a machine endpoint.
 */
class ZlicController extends Controller
{
    /** The «dir» zlic.php has always reported (the WHMCS docroot), kept for the bindings' history. */
    public const DIRECTORY = '/home/clnixpal/public_html';

    /**
     * Per address: wrong answers (an unknown key, a wrong PSK, another IP, a missing parameter) an hour, which is what a
     * key search sends; checks that pass never count, so a client that works is never held back by its own checks. And
     * any check a minute, far above a client's (daily, or one per start). Past either: «Remote Check Failed», what
     * zlic.php answered when it could not check (clients fall back to their local key), the database never asked.
     */
    public const FAILURES_PER_HOUR = 30;

    public const CHECKS_PER_MINUTE = 120;

    public function __invoke(Request $request): Response
    {
        // Strings only (review ADV-L5): «license[]=…» is a missing parameter, as for zlic.php, not an exception.
        $params = array_filter($request->post() + $request->query(), 'is_string');
        $ip = CallerIp::of($request);
        if (RateLimiter::tooManyAttempts("zlic-failed:{$ip}", self::FAILURES_PER_HOUR) || RateLimiter::tooManyAttempts("zlic:{$ip}", self::CHECKS_PER_MINUTE)) {
            return self::remoteCheckFailed();
        }
        RateLimiter::hit("zlic:{$ip}", 60);
        try {
            $response = (new Zlic(new MycsLicenseRepository, null, null, null, self::DIRECTORY))->handle($params, $ip);
        } catch (Throwable $e) {
            report($e);

            return self::remoteCheckFailed();
        }
        if (($response->body['status'] ?? null) === 'Invalid') {
            RateLimiter::hit("zlic-failed:{$ip}", 3600);
        }

        if (isset($params['license']) && $params['license'] !== '') {
            try {
                LicenseActivity::record($params['license'], $response, $ip, $request->method() . ' ' . $request->userAgent());
            } catch (Throwable $e) {
                report($e); // never changes the answer
            }
        }

        return response($response->json(), $response->httpStatus, ['Content-Type' => 'application/json']);
    }

    /** What zlic.php answered when verify.php was unreachable: clients fall back to their stored due date. */
    private static function remoteCheckFailed(): Response
    {
        return response(json_encode(['status' => 'Invalid', 'description' => 'Remote Check Failed']), 200, ['Content-Type' => 'application/json']);
    }
}
