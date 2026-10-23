<?php

namespace App\Services\Import;

use Closure;
use Throwable;

/**
 * A WHMCS login's authenticator secret, carried over so that its owner's app keeps working (security audit AUTHN-11).
 *
 * WHMCS 8 keeps `tblusers.second_factor_config` as `<iv hex>.<ciphertext hex>`: AES-256-CBC with the first 32
 * characters of sha256(cc_encryption_hash), its JSON `{"secret": <base32>, "backupcode": <hash>}` (checked on a test
 * login, 2026-10-23). WHMCS's API cannot read it (DecryptPassword is its older scheme), so the import is given
 * WHMCS's `cc_encryption_hash` (WHMCS_CC_ENCRYPTION_HASH, for the import run only). Without it, or for one that cannot
 * be read, the login comes without 2FA and the import lists it.
 */
final class WhmcsSecondFactor
{
    /** @param  (Closure(string): ?string)|null  $decrypt  turns a stored config into its plain JSON, or none */
    public function __construct(private readonly ?Closure $decrypt) {}

    public static function fromEnv(): self
    {
        $hash = (string) config('services.whmcs.cc_encryption_hash');

        return $hash === '' ? new self(null) : new self(fn (string $stored): ?string => self::decryptWith($hash, $stored));
    }

    /** WHMCS 8's own scheme, for a stored `<iv>.<ciphertext>` (hex). */
    public static function decryptWith(#[\SensitiveParameter] string $ccEncryptionHash, string $stored): ?string
    {
        $parts = explode('.', trim($stored), 2);
        if (count($parts) !== 2 || !ctype_xdigit($parts[0]) || !ctype_xdigit($parts[1]) || strlen($parts[0]) !== 32 || strlen($parts[1]) % 2 !== 0) {
            return null;
        }
        $plain = openssl_decrypt((string) hex2bin($parts[1]), 'aes-256-cbc', substr(hash('sha256', $ccEncryptionHash), 0, 32), OPENSSL_RAW_DATA, (string) hex2bin($parts[0]));

        return is_string($plain) ? $plain : null;
    }

    public function available(): bool
    {
        return $this->decrypt !== null;
    }

    /** The TOTP secret of a `tblusers` row, or null (no 2FA, another kind, or not readable). */
    public function secret(?string $module, ?string $config): ?string
    {
        if ($this->decrypt === null || $module !== 'totp' || !is_string($config) || $config === '') {
            return null;
        }
        try {
            $plain = ($this->decrypt)($config);
        } catch (Throwable) {
            return null;
        }
        $settings = is_string($plain) ? json_decode($plain, true) : null;
        $secret = is_array($settings) && is_string($settings['secret'] ?? null) ? strtoupper(str_replace([' ', '='], '', trim($settings['secret']))) : '';

        // A base32 secret as the authenticator apps take it; anything else is not guessed (a wrong one locks out).
        return preg_match('/^[A-Z2-7]{16,128}$/', $secret) === 1 ? $secret : null;
    }
}
