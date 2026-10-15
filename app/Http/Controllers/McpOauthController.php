<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Services\Mcp\McpAccess;
use App\Services\Mcp\McpOauth;
use App\Services\Mcp\OAuthError;
use App\Services\Mcp\RedirectedOAuthError;
use App\Support\CallerIp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * OAuth for the MCP server (docs/mcp.md, McpOauth): the metadata, client registration, the consent page and the token
 * endpoint. The consent approves as the member of staff logged in to the admin, or with a pasted admin API key (as
 * gsc-mcp-server's consent: token or console login). Registration, key pastes and the token endpoint are throttled
 * by address.
 */
class McpOauthController extends Controller
{
    public const REGISTER_PER_HOUR = 10;

    public const KEY_TRIES = 10; // a quarter of an hour, by address

    public const TOKEN_PER_MINUTE = 60;

    public function protectedResource(): JsonResponse
    {
        return response()->json(McpOauth::protectedResourceMetadata());
    }

    public function authorizationServer(): JsonResponse
    {
        return response()->json(McpOauth::authorizationServerMetadata());
    }

    public function register(Request $request): JsonResponse
    {
        $ip = CallerIp::of($request);
        if (RateLimiter::tooManyAttempts($limit = 'mcp-register:' . $ip, self::REGISTER_PER_HOUR)) {
            return response()->json(['error' => 'slow_down', 'error_description' => 'Too many registrations.'], 429);
        }
        RateLimiter::hit($limit, 3600);
        try {
            return response()->json(McpOauth::register($request->json()->all(), $ip), 201);
        } catch (OAuthError $e) {
            return response()->json($e->body(), $e->status);
        }
    }

    public function show(Request $request): Response
    {
        try {
            $authorize = McpOauth::authorizeRequest($request->query());
        } catch (RedirectedOAuthError $e) {
            // Never a redirect nobody clicked (review MCPO-1: registration is open, so an automatic error redirect would
            // be an open redirector on our domain); the client's error is a link on a page.
            return self::page('mcp.oauth-error', ['message' => $e->getMessage(), 'returnUrl' => $e->redirect(),
                'returnHost' => (string) parse_url($e->request->redirectUri, PHP_URL_HOST)], 400);
        } catch (OAuthError $e) {
            return self::page('mcp.oauth-error', ['message' => $e->getMessage()], 400);
        }
        $staff = Auth::guard('staff')->user();
        if (!$staff instanceof Staff) {
            // Back here after logging in to the admin.
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return self::page('mcp.authorize', [
            'authorize' => $authorize,
            'staff' => $staff instanceof Staff ? $staff : null,
            'staffMay' => $staff instanceof Staff && McpAccess::staffMay($staff),
            'returnHost' => (string) parse_url($authorize->redirectUri, PHP_URL_HOST),
            'loginUrl' => route('filament.admin.auth.login'),
            'keyError' => $request->session()->pull('mcp_key_error'),
        ]);
    }

    public function approve(Request $request): Response
    {
        try {
            $authorize = McpOauth::authorizeRequest($request->only(['response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'resource']));
        } catch (RedirectedOAuthError $e) {
            return self::page('mcp.oauth-error', ['message' => $e->getMessage(), 'returnUrl' => $e->redirect(),
                'returnHost' => (string) parse_url($e->request->redirectUri, PHP_URL_HOST)], 400);
        } catch (OAuthError $e) {
            return self::page('mcp.oauth-error', ['message' => $e->getMessage()], 400);
        }
        if ($request->input('decision') === 'deny') {
            return redirect()->away($authorize->redirect(['error' => 'access_denied', 'error_description' => 'Staff denied the request.']));
        }

        $key = null;
        if ($request->filled('api_key')) {
            $ip = CallerIp::of($request);
            if (RateLimiter::tooManyAttempts($limit = 'mcp-consent:' . $ip, self::KEY_TRIES)) {
                return self::back($request, 'Too many tries: wait a quarter of an hour.');
            }
            $key = McpAccess::keyFromBearer((string) $request->input('api_key'));
            $staff = McpAccess::keyStaff($key, $request);
            if ($staff === null) {
                RateLimiter::hit($limit, 900);

                return self::back($request, 'That key may not open the MCP server (from here).');
            }
        } else {
            $staff = Auth::guard('staff')->user();
            if (!$staff instanceof Staff || !McpAccess::staffMay($staff)) {
                return self::back($request, 'Log in to the admin as a member of staff with «Use the MCP Server», or paste a key.');
            }
        }

        return redirect()->away($authorize->redirect(['code' => McpOauth::issueCode($authorize, $staff, $key)]));
    }

    public function token(Request $request): JsonResponse
    {
        $ip = CallerIp::of($request);
        if (RateLimiter::tooManyAttempts($limit = 'mcp-token:' . $ip, self::TOKEN_PER_MINUTE)) {
            return response()->json(['error' => 'slow_down', 'error_description' => 'Too many requests.'], 429);
        }
        RateLimiter::hit($limit, 60);
        try {
            return response()->json(McpOauth::token($request->all(), $ip))->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        } catch (OAuthError $e) {
            return response()->json($e->body(), $e->status)->withHeaders(['Cache-Control' => 'no-store']);
        }
    }

    private static function back(Request $request, string $error): RedirectResponse
    {
        $request->session()->put('mcp_key_error', $error);

        return redirect()->to(url('/mcp/oauth/authorize') . '?' . http_build_query($request->only(['response_type', 'client_id', 'redirect_uri',
            'code_challenge', 'code_challenge_method', 'state', 'resource'])));
    }

    /** The consent is never framed (clickjacking) nor cached. */
    private static function page(string $view, array $data, int $status = 200): Response
    {
        return response()->view($view, $data, $status)->withHeaders([
            'X-Frame-Options' => 'DENY', 'Content-Security-Policy' => "frame-ancestors 'none'", 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
