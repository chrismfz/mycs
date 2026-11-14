<?php

namespace App\Services\Licensing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Nixpal\Licensing\Token;
use Nixpal\Licensing\ZlicResponse;
use Throwable;

/**
 * The signed token of /licensing/v1/check (docs/licensing.md, «Signed token»): only with «Active», only when the key is
 * set (services.license_token). For the caller's key and address, until the service's due date and at most max_days:
 * a new one at every check, so a yearly license never sees the limit; it is how long a client may run without reaching
 * us, once the token decides (in shadow until then: the clients only say whether it verified).
 */
final class LicenseTokens
{
    /** The token for this answer, or null: not Active, no key set, or anything failing (the answer goes without it). */
    public static function forAnswer(string $key, string $ip, ZlicResponse $response): ?string
    {
        if (($response->body['status'] ?? null) !== 'Active') {
            return null;
        }
        $secret = (string) config('services.license_token.secret');
        if ($secret === '') {
            return null;
        }
        try { // never throws to the answer: a cache down, a bad key, anything
            $kid = (string) (config('services.license_token.kid') ?: 'k1');
            // A key or kid that is not one fails every Active check alike: said once a day, without the key (review of the
            // token PR: a report() per check, with the secret in its trace, flooded the log).
            if (Token::publicKey($secret) === null || preg_match(Token::KID, $kid) !== 1) {
                if (Cache::add('license_token:bad_config:' . now()->format('Ymd'), 1, now()->addDay())) {
                    Log::error('License tokens: MYCS_LICENSE_TOKEN_KEY is not an ed25519 secret key, or MYCS_LICENSE_TOKEN_KID not a key id; no tokens are issued (php artisan mycs:license-token-key --show).');
                }

                return null;
            }
            $now = now()->getTimestamp();
            $due = is_string($response->body['nextduedate'] ?? null) ? $response->body['nextduedate'] : null;
            $due = $due !== null && $due !== '0000-00-00' ? $due : null;

            return Token::issue(['key' => $key, 'ip' => $ip, 'status' => 'Active', 'due' => $due, 'iat' => $now,
                'exp' => Token::expiry($now, $due, (int) config('services.license_token.max_days', 30))],
                $secret, $kid);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
