<?php

namespace App\Support\Domains;

/**
 * A contact's phone as registrars take it: digits only (a leading +), split on real ITU country-code lengths (+1/+7
 * one digit; 35x, 37x, 38x but 384/388, 420/421/423 three; else two), a local number under +30. One rule for every
 * registrar (Openprovider's parts, the .gr registry's «+CC.NUMBER»).
 */
final class Phone
{
    /** @return array{0: string, 1: string}|null [«+CC», subscriber], null without digits */
    public static function split(?string $raw): ?array
    {
        $digits = preg_replace('/(?!^\+)[^0-9]/', '', trim((string) $raw)) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = '+' . substr($digits, 2);
        }
        if (ltrim($digits, '+') === '') {
            return null;
        }

        return self::splitCountryCode($digits);
    }

    /** @return array{0: string, 1: string} [«+CC», subscriber] */
    public static function splitCountryCode(string $digits): array
    {
        if (!str_starts_with($digits, '+')) {
            return ['+30', $digits];
        }
        if (preg_match('/^\+([17])(\d+)$/', $digits, $m) === 1) {
            return ['+' . $m[1], $m[2]];
        }
        $three = substr($digits, 1, 3);
        if (preg_match('/^(35\d|37\d|38[0-9])$/', $three) === 1 && !in_array($three, ['384', '388'], true)
            || preg_match('/^42[013]$/', $three) === 1) {
            return ['+' . $three, substr($digits, 4)];
        }

        return [substr($digits, 0, 3), substr($digits, 3)];
    }

    /** EPP's «+CC.NUMBER» (RFC 5733, the .gr registry), null without digits. */
    public static function epp(?string $raw): ?string
    {
        $parts = self::split($raw);

        return $parts === null ? null : $parts[0] . '.' . $parts[1];
    }
}
