<?php

namespace App\Support;

use App\Models\Setting;
use Throwable;

/**
 * Takes secrets out of what is logged (sweep §6 #10, #12): values under a key that names a secret (pass, secret, token,
 * key, authorization, cookie, session), at any depth and in a URL's query; cPanel session tokens (cpsess…); and any
 * secret stored here (the extensions' encrypted settings: API keys, root passwords), wherever it appears.
 */
final class Redact
{
    public const MASK = '********';

    /**
     * Names of secrets: *pass*, secret, token, authorization, cookie, a session id, a signature, and «key» as a word of
     * its own (api_key, x-api-key, apikey, key) — not key_length, keyboard or monkey (review R12).
     */
    // An EPP/auth code transfers a domain away: a secret like a password (docs/domains.md D-11).
    // s_pw: CentralNic Reseller's password field (#297).
    private const SECRET_KEY = '/pass|secret|token|authori[sz]ation|cookie|^sess(ion)?(_?id)?$|session$|sig(nature)?$|apikey|(^|[_\-.])key$|auth_?code|epp|^s_pw$/i';

    /** Re-read this often: another process (the web request saving a server) may have changed one (review R8). */
    private const KNOWN_TTL = 60;

    /** @var list<string>|null */
    private static ?array $known = null;

    private static int $knownAt = 0;

    public static function isSecretKey(int|string $key): bool
    {
        return is_string($key) && preg_match(self::SECRET_KEY, $key) === 1;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function array(array $data): array
    {
        foreach ($data as $key => $value) {
            if (self::isSecretKey($key) && $value !== null && $value !== '' && !is_array($value)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = self::array($value);
            } elseif (is_string($value)) {
                $data[$key] = self::text($value);
            }
        }

        return $data;
    }

    /** The URL as it was sent (review R12: not rebuilt), with the values of its secret query keys masked. */
    public static function url(string $url): string
    {
        $url = (string) preg_replace_callback('/([?&])([^=&#]+)=([^&#]*)/', fn (array $m) => self::isSecretKey(urldecode($m[2]))
            ? $m[1] . $m[2] . '=' . self::MASK : $m[0], $url);

        return self::text($url);
    }

    /** cPanel session tokens and every known secret, in any text (a body, a header, a URL). */
    public static function text(string $text): string
    {
        $text = (string) preg_replace('/cpsess\d+/', 'cpsess' . self::MASK, $text);
        // A webhook's token is in its path (Discord, Slack): review R3.
        $text = (string) preg_replace('#(/api/webhooks/\d+/)[A-Za-z0-9_\-]+#', '$1' . self::MASK, $text);
        $text = (string) preg_replace('#(hooks\.slack\.com/services/)[A-Za-z0-9/]+#', '$1' . self::MASK, $text);
        // CentralNic Reseller (#297): its auth codes in an answer (property[AUTH][0] = …), its password in a request
        // (s_pw=…), an AUTH line of a command (plain or form-encoded).
        $text = (string) preg_replace('/(property\[(?:AUTH|[A-Z-]*AUTHCODE|X-SG-TRANSFER-OUT-CODE)\]\[\d+\]\s*=\s*)[^\r\n"\\\\]*/i', '$1' . self::MASK, $text);
        $text = (string) preg_replace('/(\bs_pw=)[^&\s"]*/', '$1' . self::MASK, $text);
        $text = (string) preg_replace('/((?:^|\n|\\\\n|%0A)AUTH(?:=|%3D))(?:(?!%0A|\\\\n|&|")[^\n])*/i', '$1' . self::MASK, $text);
        foreach (self::known() as $secret) {
            $text = str_replace($secret, self::MASK, $text);
        }

        return $text;
    }

    /**
     * @param  iterable<mixed>  $values  secrets to take out of $text as well (e.g. a mail's password)
     */
    public static function values(string $text, iterable $values): string
    {
        foreach ($values as $value) {
            if (is_string($value) && mb_strlen($value) >= 4) {
                // As written, HTML-escaped, JSON-escaped (an HTTP body's «a\/b») and URL-encoded (ADV-5 of #318).
                $text = str_replace(array_unique([$value, e($value), substr((string) json_encode($value), 1, -1), urlencode($value), rawurlencode($value)]), self::MASK, $text);
            }
        }

        return $text;
    }

    /** @return list<string> the stored secrets (decrypted once per process; short ones would mask ordinary text) */
    private static function known(): array
    {
        if (self::$known !== null && now()->getTimestamp() - self::$knownAt < self::KNOWN_TTL) {
            return self::$known;
        }
        try {
            self::$known = Setting::query()->where('encrypted', true)->get()->map(fn (Setting $setting) => $setting->value)
                ->filter(fn ($value) => is_string($value) && mb_strlen($value) >= 6)->unique()->values()->all();
            self::$knownAt = now()->getTimestamp();
        } catch (Throwable) {
            // No database yet (install, a failing connection): the key rules still apply, and the next call tries again.
            return self::$known ?? [];
        }

        return self::$known;
    }

    /** A setting changed: read them again. */
    public static function forget(): void
    {
        self::$known = null;
    }
}
