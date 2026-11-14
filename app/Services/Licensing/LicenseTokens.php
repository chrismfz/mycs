<?php

namespace App\Services\Licensing;

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
        try {
            $now = now()->getTimestamp();
            $due = is_string($response->body['nextduedate'] ?? null) ? $response->body['nextduedate'] : null;
            $due = $due !== null && $due !== '0000-00-00' ? $due : null;

            return Token::issue(['key' => $key, 'ip' => $ip, 'status' => 'Active', 'due' => $due, 'iat' => $now,
                'exp' => Token::expiry($now, $due, (int) config('services.license_token.max_days', 30))],
                $secret, (string) (config('services.license_token.kid') ?: 'k1'));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
