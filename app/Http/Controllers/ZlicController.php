<?php

namespace App\Http\Controllers;

use App\Services\Licensing\LicenseActivity;
use App\Services\Licensing\MycsLicenseRepository;
use App\Services\Licensing\ZlicLimiter;
use App\Support\CallerIp;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nixpal\Licensing\Zlic;
use Nixpal\Licensing\ZlicResponse;
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
     * The limits (#254; nixpal's 90 days on WHMCS, 2026-10-20: at most 11 wrong answers an hour from one address, 3
     * unknown keys in all, 21 checks a minute). Per caller (an IPv4 address, an IPv6 /64), an hour:
     * - a key search: unknown keys and missing parameters;
     * - one key's wrong answers (another IP, a wrong PSK), so a license failing never holds back the others there.
     * A key that got a signed answer from that caller (Active, Suspended, Expired) is never limited there: a neighbour's
     * search cannot lock it out, nor can its owner turn a suspension into «Remote Check Failed» (adversarial A2, A3).
     * Each check is counted before it is answered and given back when it was not what the counter counts (parallel
     * guesses, A5). Past a limit: «Remote Check Failed», what zlic.php said when it could not check (clients keep their
     * local key), the licenses never asked.
     */
    public const FAILURES_PER_HOUR = 60;

    public const KEY_FAILURES_PER_HOUR = 60;

    /** How long a caller is known for a key after a signed answer: longer than any client's checks are apart. */
    public const KNOWN_FOR = 30 * 86400;

    public function __invoke(Request $request, ZlicLimiter $limiter): Response
    {
        // Strings only (review ADV-L5): «license[]=…» is a missing parameter, as for zlic.php, not an exception.
        $params = array_filter($request->post() + $request->query(), 'is_string');
        $ip = CallerIp::of($request);
        $caller = CallerIp::bucket($ip);
        $key = $params['license'] ?? '';
        $keyId = sha1($key);

        $counted = [];
        try {
            if ($key === '' || !$limiter->marked("ok:{$caller}:{$keyId}")) {
                $counted['s'] = "s:{$caller}";
                if ($limiter->hit($counted['s'], 3600) > self::FAILURES_PER_HOUR) {
                    return self::remoteCheckFailed();
                }
                if ($key !== '') {
                    $counted['k'] = "k:{$caller}:{$keyId}";
                    if ($limiter->hit($counted['k'], 3600) > self::KEY_FAILURES_PER_HOUR) {
                        $this->noteLimited($limiter, $caller, $keyId, $key, $ip, $request);

                        return self::remoteCheckFailed();
                    }
                }
            }
        } catch (Throwable $e) {
            report($e); // the counters never take zlic.php down (adversarial A4): answered as before #254
            $counted = [];
        }

        try {
            $response = (new Zlic(new MycsLicenseRepository, null, null, null, self::DIRECTORY))->handle($params, $ip);
        } catch (Throwable $e) {
            report($e);

            return self::remoteCheckFailed();
        }

        try {
            $this->settle($limiter, $counted, $response, "ok:{$caller}:{$keyId}");
        } catch (Throwable $e) {
            report($e); // never changes the answer
        }

        if ($key !== '') {
            try {
                LicenseActivity::record($key, $response, $ip, $request->method() . ' ' . $request->userAgent());
            } catch (Throwable $e) {
                report($e); // never changes the answer
            }
        }

        return response($response->json(), $response->httpStatus, ['Content-Type' => 'application/json']);
    }

    /**
     * The hits given back that the answer says were not what they count: a known key is no search; a signed answer is
     * no wrong one, and makes the caller known for the key.
     *
     * @param  array{s?: string, k?: string}  $counted
     */
    private function settle(ZlicLimiter $limiter, array $counted, ZlicResponse $response, string $known): void
    {
        $status = $response->body['status'] ?? null;
        // zlic.php's answer to an unknown key is exactly this (no message, no description); a missing parameter is a 400.
        $search = $response->httpStatus === 400 || $response->body === ['status' => 'Invalid', 'remotecheck' => true];
        if (!$search && isset($counted['s'])) {
            $limiter->giveBack($counted['s']);
        }
        if ($status !== 'Invalid') {
            if (isset($counted['k'])) {
                $limiter->giveBack($counted['k']);
            }
            $limiter->mark($known, self::KNOWN_FOR);
        }
    }

    /** A license held back is said on it once a day (review F5): staff see why it does not activate. */
    private function noteLimited(ZlicLimiter $limiter, string $caller, string $keyId, string $key, string $ip, Request $request): void
    {
        try {
            if ($limiter->hit("noted:{$caller}:{$keyId}", 86400) === 1) {
                LicenseActivity::record($key, new ZlicResponse(200, ['status' => 'Invalid', 'description' => 'Rate limited: too many failed checks from this address']),
                    $ip, $request->method() . ' ' . $request->userAgent());
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** What zlic.php answered when verify.php was unreachable: clients fall back to their stored due date. */
    private static function remoteCheckFailed(): Response
    {
        return response(json_encode(['status' => 'Invalid', 'description' => 'Remote Check Failed']), 200, ['Content-Type' => 'application/json']);
    }
}
