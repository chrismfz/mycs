<?php

namespace App\Http\Controllers;

use App\Services\Licensing\LicenseActivity;
use App\Services\Licensing\MycsLicenseRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    public function __invoke(Request $request): Response
    {
        $params = $request->post() + $request->query();
        try {
            $response = (new Zlic(new MycsLicenseRepository, null, null, null, self::DIRECTORY))->handle($params, (string) $request->ip());
        } catch (Throwable $e) {
            report($e);

            // What zlic.php answered when verify.php was unreachable: clients fall back to their stored due date.
            return response(json_encode(['status' => 'Invalid', 'description' => 'Remote Check Failed']), 200, ['Content-Type' => 'application/json']);
        }

        if (isset($params['license']) && is_string($params['license']) && $params['license'] !== '') {
            try {
                LicenseActivity::record($params['license'], $response, (string) $request->ip(), $request->method() . ' ' . $request->userAgent());
            } catch (Throwable $e) {
                report($e); // never changes the answer
            }
        }

        return response($response->json(), $response->httpStatus, ['Content-Type' => 'application/json']);
    }
}
