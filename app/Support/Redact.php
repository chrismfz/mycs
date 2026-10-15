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

    private const SECRET_KEY = '/pass|secret|token|key|authori[sz]ation|cookie|session|sig(nature)?$/i';

    /** @var list<string>|null */
    private static ?array $known = null;

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

    public static function url(string $url): string
    {
        $parts = explode('?', $url, 2);
        if (count($parts) === 2) {
            parse_str($parts[1], $query);
            $parts[1] = http_build_query(self::array($query));
        }

        return self::text(implode('?', $parts));
    }

    /** cPanel session tokens and every known secret, in any text (a body, a header, a URL). */
    public static function text(string $text): string
    {
        $text = (string) preg_replace('/cpsess\d+/', 'cpsess' . self::MASK, $text);
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
                $text = str_replace([$value, e($value)], self::MASK, $text);
            }
        }

        return $text;
    }

    /** @return list<string> the stored secrets (decrypted once per process; short ones would mask ordinary text) */
    private static function known(): array
    {
        if (self::$known !== null) {
            return self::$known;
        }
        try {
            self::$known = Setting::query()->where('encrypted', true)->get()->map(fn (Setting $setting) => $setting->value)
                ->filter(fn ($value) => is_string($value) && mb_strlen($value) >= 6)->unique()->values()->all();
        } catch (Throwable) {
            self::$known = []; // no database yet (install, a failing connection): the key rules still apply
        }

        return self::$known;
    }

    /** A setting changed: read them again. */
    public static function forget(): void
    {
        self::$known = null;
    }
}
