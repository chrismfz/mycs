<?php

namespace App\Services\Import;

use Closure;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A WHMCS login's authenticator secret, carried over so that its owner's app keeps working (security audit AUTHN-11).
 *
 * WHMCS keeps `tblusers.second_factor_config` encrypted with its own key (`cc_encryption_hash`, not in its database):
 * only its API can read it (`DecryptPassword`, a read). Set WHMCS_API_URL, WHMCS_API_IDENTIFIER and WHMCS_API_SECRET for
 * the import; without them, or for one that cannot be read, the login comes without 2FA and the import lists it.
 */
final class WhmcsSecondFactor
{
    /** @param  (Closure(string): ?string)|null  $decrypt  WHMCS's DecryptPassword, or none */
    public function __construct(private readonly ?Closure $decrypt) {}

    public static function fromEnv(): self
    {
        $url = rtrim((string) config('services.whmcs.api_url'), '/');
        $identifier = (string) config('services.whmcs.api_identifier');
        $secret = (string) config('services.whmcs.api_secret');
        if ($url === '' || $identifier === '' || $secret === '' || !str_starts_with($url, 'https://')) {
            return new self(null);
        }

        return new self(function (string $encrypted) use ($url, $identifier, $secret): ?string {
            $answer = Http::asForm()->timeout(20)->withOptions(['allow_redirects' => false])->post($url . '/includes/api.php', [
                'action' => 'DecryptPassword', 'identifier' => $identifier, 'secret' => $secret,
                'password2' => $encrypted, 'responsetype' => 'json',
            ]);
            $password = $answer->successful() ? $answer->json('password') : null;

            return ($answer->json('result') === 'success' && is_string($password)) ? $password : null;
        });
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
        if (!is_string($plain) || $plain === '') {
            return null;
        }
        $settings = self::settings($plain);
        // WHMCS's totp module (ga4php): the key as hex in a serialized token, base64 in `tokendata`.
        if (is_string($settings['tokendata'] ?? null)) {
            $token = self::settings((string) base64_decode($settings['tokendata'], true));
            $hex = $token['tokenkey'] ?? null;
            $standard = strtoupper((string) ($token['tokentype'] ?? 'TOTP')) === 'TOTP' && (int) ($token['tokentimer'] ?? 30) === 30
                && strtoupper((string) ($token['tokenalgorithm'] ?? 'SHA1')) === 'SHA1';
            $secret = $standard && is_string($hex) && $hex !== '' && strlen($hex) % 2 === 0 && ctype_xdigit($hex) ? self::base32((string) hex2bin($hex)) : '';
        } else {
            $secret = $settings['secret'] ?? $settings['tokensecret'] ?? null;
            $secret = is_string($secret) ? strtoupper(str_replace([' ', '='], '', trim($secret))) : '';
        }

        // A base32 secret as the authenticator apps take it; anything else is not guessed (a wrong one locks out).
        return preg_match('/^[A-Z2-7]{16,128}$/', $secret) === 1 ? $secret : null;
    }

    /** @return array<mixed> JSON or serialized settings (never an object made from them), or none */
    private static function settings(string $text): array
    {
        $settings = json_decode($text, true);
        if (!is_array($settings)) {
            $settings = @unserialize($text, ['allowed_classes' => false]);
        }

        return is_array($settings) ? $settings : [];
    }

    private static function base32(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }
}
