<?php

namespace App\Support\Domains;

/**
 * A contact's phone as registrars take it: digits only (a leading +), split on real ITU country-code lengths (+1/+7
 * one digit; 35x, 37x, 38x but 384/388, 420/421/423 three; else two), a local number under its contact's country's
 * code (G5b-3 of grepp: a Cypriot's 22… is +357), +30 when that country is not known. One rule for every registrar
 * (Openprovider's parts, the .gr registry's «+CC.NUMBER»).
 */
final class Phone
{
    /** Calling codes by ISO country (the EU, its neighbours, the usual others); another country: +30, as before. */
    private const CODES = [
        'GR' => '30', 'CY' => '357', 'AL' => '355', 'BG' => '359', 'MK' => '389', 'TR' => '90', 'RO' => '40', 'RS' => '381', 'ME' => '382',
        'BA' => '387', 'HR' => '385', 'SI' => '386', 'IT' => '39', 'MT' => '356', 'ES' => '34', 'PT' => '351', 'FR' => '33', 'MC' => '377',
        'BE' => '32', 'NL' => '31', 'LU' => '352', 'DE' => '49', 'AT' => '43', 'CH' => '41', 'LI' => '423', 'CZ' => '420', 'SK' => '421',
        'PL' => '48', 'HU' => '36', 'DK' => '45', 'SE' => '46', 'NO' => '47', 'FI' => '358', 'IS' => '354', 'IE' => '353', 'GB' => '44',
        'EE' => '372', 'LV' => '371', 'LT' => '370', 'UA' => '380', 'MD' => '373', 'RU' => '7', 'GE' => '995', 'AM' => '374', 'IL' => '972',
        'EG' => '20', 'LB' => '961', 'AE' => '971', 'SA' => '966', 'US' => '1', 'CA' => '1', 'AU' => '61', 'NZ' => '64', 'ZA' => '27',
        'BR' => '55', 'AR' => '54', 'MX' => '52', 'IN' => '91', 'CN' => '86', 'JP' => '81', 'KZ' => '7', 'BY' => '375', 'XK' => '383',
        'SG' => '65', 'SM' => '378', 'VA' => '39',
    ];

    /**
     * National trunk prefixes other than a single 0, by calling code (code review 5 of #205): Russia's and Kazakhstan's
     * «8 916…», Belarus's «80 17…», Hungary's «06 1…», «1 212…» in North America; with the length a whole national
     * number has then, so a number that only starts so keeps its digits.
     */
    private const TRUNKS = ['7' => ['8', 11], '375' => ['80', 11], '36' => ['06', 10], '1' => ['1', 11]];

    /** @return array{0: string, 1: string}|null [«+CC», subscriber], null without digits; a local number under $country's code */
    public static function split(?string $raw, ?string $country = null): ?array
    {
        // A + first, even bracketed («(+30) 210…»: ADV-4 of #192), then its digits only.
        $raw = trim((string) $raw);
        $digits = (preg_match('/^[\s(]*\+/', $raw) === 1 ? '+' : '') . (preg_replace('/\D/', '', $raw) ?? '');
        if (str_starts_with($digits, '00')) {
            $digits = '+' . substr($digits, 2);
        }
        if (ltrim($digits, '+') === '') {
            return null;
        }
        $code = self::CODES[strtoupper(trim((string) $country))] ?? null;
        if (!str_starts_with($digits, '+') && $code !== null) {
            // A national trunk 0 dropped (020 … in London is +44 20 …), except where it is part of the number (Italy, San
            // Marino) or there is none (Greece).
            [$trunk, $length] = self::TRUNKS[$code] ?? ['0', 0];
            if ($length > 0) {
                return ['+' . $code, strlen($digits) === $length && str_starts_with($digits, $trunk) ? substr($digits, strlen($trunk)) : $digits];
            }
            $keep = in_array($code, ['30', '39', '378'], true) || ltrim($digits, '0') === '';

            return ['+' . $code, $keep ? $digits : ltrim($digits, '0')];
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

    /** EPP's «+CC.NUMBER» (RFC 5733, the .gr registry), null without digits; a local number under $country's code. */
    public static function epp(?string $raw, ?string $country = null): ?string
    {
        $parts = self::split($raw, $country);

        return $parts === null ? null : $parts[0] . '.' . $parts[1];
    }
}
