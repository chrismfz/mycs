<?php

namespace App\Services\Mcp;

use App\Models\ApiKey;
use App\Models\McpOauthClient;
use App\Models\McpOauthCode;
use App\Models\McpOauthGrant;
use App\Models\Staff;
use App\Models\StaffSession;
use App\Support\StaffSessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * OAuth 2.1 for the MCP server (docs/mcp.md), as the claude.ai connector speaks it: metadata (RFC 9728, RFC 8414),
 * dynamic client registration (RFC 7591, public clients), the authorization code with PKCE S256 only, one-minute
 * single-use codes, an access token of an hour and a refresh token of 90 days, rotated on every use (a replayed one
 * revokes the grant, any used one: MCPO-4; a grant ends after a year). Staff approve on a consent page, logged in to the admin or by pasting an admin API key
 * (McpOauthController). Every token is checked again on use (McpAccess). Only hashes are stored.
 */
final class McpOauth
{
    public const CODE_SECONDS = 60;

    public const ACCESS_SECONDS = 3600;

    public const REFRESH_DAYS = 90;

    /**
     * However often it is refreshed, a grant ends this many days after staff approved it (review MCPO-4): the setting «MCP
     * grant lifetime», 365 by default; 0 keeps it until revoked (owner 2026-10-24: the grants are listed and revoked by
     * hand).
     */
    public const GRANT_DAYS = 365;

    /** The setting's longest: ten years (more would overflow into «already past»). */
    public const MAX_GRANT_DAYS = 3650;

    /** Whole days from 0 to MAX_GRANT_DAYS; anything else (empty, 0.5, a typo) is the default, never «until revoked». */
    public static function grantDays(): int
    {
        $value = config('settings.mcp_grant_days');
        $days = is_int($value) ? $value : (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1 ? (int) $value : null);

        return $days !== null && $days <= self::MAX_GRANT_DAYS ? $days : self::GRANT_DAYS;
    }

    public const ACCESS_PREFIX = 'mcpat_';

    public const REFRESH_PREFIX = 'mcprt_';

    public const SCOPE = 'mcp';

    /** A request value as a string: an array where a string belongs is no value (not a 500). */
    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    public static function resource(): string
    {
        return url('/mcp');
    }

    /** @return array<string, mixed> RFC 9728 */
    public static function protectedResourceMetadata(): array
    {
        return ['resource' => self::resource(), 'authorization_servers' => [url('/')], 'bearer_methods_supported' => ['header'],
            'scopes_supported' => [self::SCOPE], 'resource_name' => config('app.name') . ' MCP'];
    }

    /** @return array<string, mixed> RFC 8414 */
    public static function authorizationServerMetadata(): array
    {
        return [
            'issuer' => url('/'),
            'authorization_endpoint' => url('/mcp/oauth/authorize'),
            'token_endpoint' => url('/mcp/oauth/token'),
            'registration_endpoint' => url('/mcp/oauth/register'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [self::SCOPE],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function register(array $data, string $ip): array
    {
        $uris = $data['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || count($uris) > 5) {
            throw new OAuthError('invalid_redirect_uri', 'redirect_uris: one to five URIs.');
        }
        foreach ($uris as $uri) {
            if (!is_string($uri) || !self::acceptableRedirect($uri)) {
                throw new OAuthError('invalid_redirect_uri', 'A redirect URI must be https, or http on localhost, without a fragment.');
            }
            if (!self::allowedHost($uri)) {
                throw new OAuthError('invalid_redirect_uri', 'This site does not send authorizations to that host (Settings: MCP OAuth redirect hosts).');
            }
        }
        foreach (['grant_types', 'response_types'] as $list) {
            if (isset($data[$list]) && (!is_array($data[$list]) || array_diff($data[$list], ['authorization_code', 'refresh_token', 'code']) !== [])) {
                throw new OAuthError('invalid_client_metadata', "{$list}: only the authorization code (and refresh) flow.");
            }
        }
        if (($data['token_endpoint_auth_method'] ?? 'none') !== 'none') {
            throw new OAuthError('invalid_client_metadata', 'Public clients only (token_endpoint_auth_method none).');
        }
        $name = trim(strip_tags(self::str($data['client_name'] ?? null))) ?: 'MCP client';
        $client = McpOauthClient::query()->create(['client_id' => 'mcpc_' . Str::random(32), 'name' => mb_substr($name, 0, 100),
            'redirect_uris' => array_values($uris), 'registered_ip' => $ip]);

        return ['client_id' => $client->client_id, 'client_name' => $client->name, 'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'], 'token_endpoint_auth_method' => 'none',
            'client_id_issued_at' => $client->created_at?->getTimestamp()];
    }

    /** The hosts an MCP client may be sent back to when none is set: Claude's, and this computer's (a local client). */
    public const DEFAULT_REDIRECT_HOSTS = 'claude.ai, claude.com, localhost, 127.0.0.1, [::1]';

    /**
     * Anyone can register a client with any name (open dynamic registration), so a phished staff member's one click
     * would send a grant to the phisher's host: only the hosts staff allow, or their subdomains (security audit API-5).
     */
    public static function allowedHost(string $uri): bool
    {
        $host = strtolower((string) parse_url($uri, PHP_URL_HOST));
        $listed = config('settings.mcp_oauth_redirect_hosts');
        $allowed = array_filter(array_map(fn (string $h) => strtolower(trim($h)), (preg_split('/[\s,]+/', is_string($listed) && trim($listed) !== '' ? $listed : self::DEFAULT_REDIRECT_HOSTS) ?: [])));
        foreach ($allowed as $entry) {
            if ($host === $entry || str_ends_with($host, '.' . ltrim($entry, '.'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * https, or http on localhost; and a URI every parser reads alike (review MCPO-2): no backslash, space or control
     * character, no user or password, an ASCII host (an internationalised one only as punycode, as the consent shows it),
     * no fragment.
     */
    public static function acceptableRedirect(string $uri): bool
    {
        if (mb_strlen($uri) > 2000 || preg_match('/[\\\\\s\x00-\x1f\x7f#]/', $uri) === 1 || !mb_check_encoding($uri, 'ASCII')) {
            return false;
        }
        $parts = parse_url($uri);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if ($host === '[::1]') {
            return $scheme === 'http' || $scheme === 'https';
        }
        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host) !== 1) {
            return false;
        }

        return $scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true));
    }

    /**
     * An authorization request, checked. A bad client or redirect URI is never redirected to (an open redirector);
     * everything else is the client's to hear about at its redirect URI (the controller).
     *
     * @param  array<string, mixed>  $query
     */
    public static function authorizeRequest(array $query): AuthorizeRequest
    {
        $client = McpOauthClient::query()->where('client_id', self::str($query['client_id'] ?? null))->first();
        if ($client === null) {
            throw new OAuthError('invalid_client', 'Unknown client.');
        }
        $redirect = self::str($query['redirect_uri'] ?? null);
        if ($redirect === '' && count($client->redirect_uris) === 1) {
            $redirect = $client->redirect_uris[0];
        }
        if (!in_array($redirect, $client->redirect_uris, true)) {
            throw new OAuthError('invalid_request', 'The redirect URI is not one the client registered.');
        }
        // A client registered before its host was taken off the list: refused here too, never redirected to.
        if (!self::allowedHost($redirect)) {
            throw new OAuthError('invalid_request', 'This site does not send authorizations to that host (Settings: MCP OAuth redirect hosts).');
        }
        $state = is_string($query['state'] ?? null) ? mb_substr($query['state'], 0, 500) : null;
        $request = new AuthorizeRequest($client, $redirect, self::str($query['code_challenge'] ?? null), $state);
        if (($query['response_type'] ?? '') !== 'code') {
            throw new RedirectedOAuthError($request, 'unsupported_response_type', 'Only the authorization code flow.');
        }
        if (($query['code_challenge_method'] ?? '') !== 'S256' || preg_match('/^[A-Za-z0-9_-]{43,128}$/', $request->codeChallenge) !== 1) {
            throw new RedirectedOAuthError($request, 'invalid_request', 'PKCE with S256 is required.');
        }
        if (isset($query['resource']) && rtrim(self::str($query['resource']), '/') !== rtrim(self::resource(), '/')) {
            throw new RedirectedOAuthError($request, 'invalid_target', 'This server issues tokens for its own MCP resource only.');
        }

        return $request;
    }

    /** A consent: a one-minute single-use code for the client; none when its staff session was ended meanwhile. */
    public static function issueCode(AuthorizeRequest $request, Staff $staff, ?ApiKey $key): ?string
    {
        $code = 'mcpco_' . Str::random(48);
        $row = McpOauthCode::query()->create(['code_hash' => hash('sha256', $code), 'client_id' => $request->client->id, 'staff_id' => $staff->id,
            'api_key_id' => $key?->id, 'redirect_uri' => $request->redirectUri, 'code_challenge' => $request->codeChallenge,
            'expires_at' => now()->addSeconds(self::CODE_SECONDS), 'created_at' => now(),
            // Approved in a browser session: ending that session revokes it (StaffSessions::end).
            'staff_session' => $key === null && request()->hasSession() && is_string($ulid = request()->session()->get(StaffSessions::SESSION_KEY)) ? $ulid : null]);

        // Written first, then the session read again: an «End» committed meanwhile (it expires the codes it finds)
        // is seen either way (review of #375).
        if ($row->staff_session !== null && StaffSession::query()->where('ulid', $row->staff_session)->whereNotNull('ended_at')->exists()) {
            $row->delete();

            return null;
        }

        return $code;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> the token response
     */
    public static function token(array $data, string $ip): array
    {
        return match ($data['grant_type'] ?? null) {
            'authorization_code' => self::exchangeCode($data, $ip),
            'refresh_token' => self::refresh($data, $ip),
            default => throw new OAuthError('unsupported_grant_type', 'authorization_code or refresh_token.'),
        };
    }

    /** @param array<string, mixed> $data */
    private static function exchangeCode(array $data, string $ip): array
    {
        $client = McpOauthClient::query()->where('client_id', self::str($data['client_id'] ?? null))->first()
            ?? throw new OAuthError('invalid_client', 'Unknown client.', 401);
        $code = McpOauthCode::query()->where('code_hash', hash('sha256', self::str($data['code'] ?? null)))->first();
        // Claimed on its own, committed whatever follows: a code is good once, also when the exchange then fails.
        $claimed = $code !== null && $code->client_id === $client->id
            && McpOauthCode::query()->whereKey($code->id)->whereNull('used_at')->where('expires_at', '>', now())->update(['used_at' => now()]) === 1;
        if (!$claimed) {
            // A code that comes back leaked: the grant it bought goes too (review MCPO-5, RFC 9700 §4.2.4).
            if ($code !== null && $code->used_at !== null && $code->grant_id !== null) {
                McpOauthGrant::query()->find($code->grant_id)?->revoke('Authorization code replayed');
            }

            throw new OAuthError('invalid_grant', 'The code is unknown, used or expired.');
        }
        if (self::str($data['redirect_uri'] ?? $code->redirect_uri) !== $code->redirect_uri) {
            throw new OAuthError('invalid_grant', 'The redirect URI differs from the authorization request\'s.');
        }
        $verifier = self::str($data['code_verifier'] ?? null);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) !== 1 || !hash_equals($code->code_challenge, $challenge)) {
            throw new OAuthError('invalid_grant', 'PKCE verification failed.');
        }
        $grant = McpOauthGrant::query()->create(['client_id' => $client->id, 'staff_id' => $code->staff_id, 'api_key_id' => $code->api_key_id,
            'approved_by' => $code->api_key_id ? 'key' : 'session', 'staff_session' => $code->staff_session]);
        McpOauthCode::query()->whereKey($code->id)->update(['grant_id' => $grant->id]);
        if (self::grantAlive($grant) === null) {
            $grant->revoke('Not allowed any more');

            throw new OAuthError('invalid_grant', 'The member of staff, or the key, may not use the MCP server.');
        }

        return self::mint($grant, $ip);
    }

    /** @param array<string, mixed> $data */
    private static function refresh(array $data, string $ip): array
    {
        $presented = hash('sha256', self::str($data['refresh_token'] ?? null));
        // Decided under the grant's lock, a revocation committed before the error is thrown (not rolled back with it).
        $outcome = DB::transaction(function () use ($presented, $data, $ip) {
            $grant = McpOauthGrant::query()->where('refresh_hash', $presented)->lockForUpdate()->first();
            if ($grant === null) {
                // Any refresh token the grant already used, not only the last one: someone kept a copy, and whoever
                // refreshed with it since is not to be told apart from its client. The grant goes (RFC 9700 §4.14; MCPO-4).
                $used = DB::table('mcp_oauth_used_refresh_tokens')->where('token_hash', $presented)->value('grant_id');
                if ($used !== null) {
                    McpOauthGrant::query()->find($used)?->revoke('Refresh token replayed');
                }

                return new OAuthError('invalid_grant', 'The refresh token is unknown or was used.');
            }
            $clientId = self::str($data['client_id'] ?? null);
            if ($clientId !== '' && $grant->client->client_id !== $clientId) {
                return new OAuthError('invalid_grant', 'The refresh token is another client\'s.');
            }
            if ($grant->revoked_at !== null || $grant->refresh_expires_at === null || $grant->refresh_expires_at->isPast()
                || (self::grantDays() > 0 && $grant->created_at?->copy()->addDays(self::grantDays())->isPast()) || self::grantAlive($grant) === null) {
                $grant->revoke('Expired or not allowed any more');

                return new OAuthError('invalid_grant', 'The grant expired or was revoked.');
            }

            return self::mint($grant, $ip);
        });
        if ($outcome instanceof OAuthError) {
            throw $outcome;
        }

        return $outcome;
    }

    /** @return array<string, mixed> */
    private static function mint(McpOauthGrant $grant, string $ip): array
    {
        $access = self::ACCESS_PREFIX . Str::random(48);
        $refresh = self::REFRESH_PREFIX . Str::random(48);
        if ($grant->refresh_hash !== null) {
            DB::table('mcp_oauth_used_refresh_tokens')->insertOrIgnore(['grant_id' => $grant->id, 'token_hash' => $grant->refresh_hash, 'created_at' => now()]);
        }
        $grant->forceFill([
            'access_hash' => hash('sha256', $access), 'access_expires_at' => now()->addSeconds(self::ACCESS_SECONDS),
            'previous_refresh_hash' => $grant->refresh_hash, 'refresh_hash' => hash('sha256', $refresh),
            'refresh_expires_at' => now()->addDays(self::REFRESH_DAYS), 'last_ip' => $ip,
        ])->save();

        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => self::ACCESS_SECONDS, 'refresh_token' => $refresh, 'scope' => self::SCOPE];
    }

    /** The member of staff a live access token acts as, checked again on every call; null otherwise. */
    public static function staffForAccessToken(string $token): ?array
    {
        if (!str_starts_with($token, self::ACCESS_PREFIX)) {
            return null;
        }
        $grant = McpOauthGrant::query()->where('access_hash', hash('sha256', $token))->first();
        if ($grant === null || $grant->revoked_at !== null || $grant->access_expires_at === null || $grant->access_expires_at->isPast()) {
            return null;
        }
        $staff = self::grantAlive($grant);
        if ($staff === null) {
            $grant->revoke('Not allowed any more');

            return null;
        }

        return [$staff, $grant];
    }

    /** The grant's member of staff, when they (and the key it was approved with) may still use the MCP. */
    public static function grantAlive(McpOauthGrant $grant): ?Staff
    {
        if ($grant->api_key_id !== null) {
            $staff = McpAccess::keyStaff($grant->apiKey); // the key's allowlist: checked by address in McpAuth

            return $staff !== null && $staff->id === $grant->staff_id ? $staff : null;
        }

        // Approved in a browser session ended with «End» (a sign-out leaves it alone): dead, whenever it was made, so
        // an exchange or refresh racing «End» never outlives it (review of #375).
        if ($grant->staff_session !== null && StaffSession::query()->where('ulid', $grant->staff_session)->where('terminated', true)->exists()) {
            return null;
        }

        return McpAccess::staffMay($grant->staff) ? $grant->staff : null;
    }
}
