<?php

namespace Nixpal\Licensing;

/**
 * The signed license token of /licensing/v1/check (MyCS docs/licensing.md, «Signed token»): ed25519 (libsodium), the
 * private key only on the server, the public keys in the clients. What a client patched or a fake license server
 * cannot make, unlike the local key, whose hashes use a secret every client holds.
 *
 * Format: `<kid>.<payload>.<signature>`, payload and signature in base64url without padding. The signature covers the
 * bytes `<kid>.<payload>` exactly as sent, so no client has to rebuild a JSON byte for byte. The payload is a JSON object:
 * v (1), kid, key (the license key), ip (the caller), status, due (Y-m-d or null), iat and exp (unix seconds).
 */
final class Token
{
    const VERSION = 1;

    /** A kid is a short name of the key: letters, digits, «-» and «_». */
    const KID = '/^[A-Za-z0-9_-]{1,32}$/';

    /**
     * A new key pair, both in base64 (standard, padded): the secret key for the server's configuration, the public one
     * for the clients.
     *
     * @return array{secret: string, public: string}
     */
    public static function keypair()
    {
        $pair = sodium_crypto_sign_keypair();

        return ['secret' => base64_encode(sodium_crypto_sign_secretkey($pair)), 'public' => base64_encode(sodium_crypto_sign_publickey($pair))];
    }

    /**
     * The public key of a secret one (both base64), or null for anything that is not a secret key.
     *
     * @param  string  $secret
     * @return string|null
     */
    public static function publicKey(
        #[\SensitiveParameter]
        $secret
    )
    {
        $raw = base64_decode((string) $secret, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return null;
        }

        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($raw));
    }

    /**
     * @param  array<string, mixed>  $claims  key, ip, status, due, iat, exp (v and kid are set here)
     * @param  string  $secret  the secret key, base64
     * @param  string  $kid
     * @return string
     *
     * @throws \InvalidArgumentException a secret key or kid that is not one
     */
    public static function issue(
        array $claims,
        #[\SensitiveParameter]
        $secret,
        $kid
    ) {
        $raw = base64_decode((string) $secret, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \InvalidArgumentException('Not an ed25519 secret key');
        }
        if (!preg_match(self::KID, (string) $kid)) {
            throw new \InvalidArgumentException('Not a key id');
        }
        $payload = self::encode(json_encode(['v' => self::VERSION, 'kid' => $kid] + $claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $signed = $kid . '.' . $payload;

        return $signed . '.' . self::encode(sodium_crypto_sign_detached($signed, $raw));
    }

    /**
     * The claims of a token signed by one of these keys and not expired at $now, else null. What the clients do; here
     * for the tests and staff tools.
     *
     * @param  string  $token
     * @param  array<string, string>  $publicKeys  kid => public key (base64)
     * @param  int  $now
     * @return array<string, mixed>|null
     */
    public static function verify($token, array $publicKeys, $now)
    {
        $parts = explode('.', (string) $token);
        if (count($parts) !== 3 || !isset($publicKeys[$parts[0]])) {
            return null;
        }
        $public = base64_decode($publicKeys[$parts[0]], true);
        $signature = self::decode($parts[2]);
        if ($public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $signature === null
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($signature, $parts[0] . '.' . $parts[1], $public)) {
            return null;
        }
        $json = self::decode($parts[1]);
        $claims = $json === null ? null : json_decode($json, true);
        if (!is_array($claims) || ($claims['v'] ?? null) !== self::VERSION || ($claims['kid'] ?? null) !== $parts[0]
            || !is_int($claims['exp'] ?? null) || $claims['exp'] < $now) {
            return null;
        }

        return $claims;
    }

    /**
     * When a token issued at $now expires: the end of the due date (23:59:59 UTC; the due date is the instance's local
     * date, so a few hours later there) or $now + $maxDays, whichever comes first. No due date (or WHMCS's 0000-00-00):
     * $maxDays.
     *
     * @param  int  $now
     * @param  string|null  $due  Y-m-d
     * @param  int  $maxDays
     * @return int
     */
    public static function expiry($now, $due, $maxDays)
    {
        $max = $now + $maxDays * 86400;
        if (!is_string($due) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $due, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $max;
        }
        $endOfDue = gmmktime(23, 59, 59, (int) $m[2], (int) $m[3], (int) $m[1]);

        return min($max, $endOfDue);
    }

    private static function encode($bytes)
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode($text)
    {
        if (!preg_match('/^[A-Za-z0-9_-]*$/', $text)) {
            return null;
        }
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }
}
