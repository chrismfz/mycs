<?php

namespace MyCS\Vies;

/**
 * The EU member states as VIES and ISO name them. One difference: Greece is «EL» in VAT numbers and VIES, «GR» in ISO
 * (and in every country field). Everything here compares ISO codes; EL is only the prefix of a number.
 */
final class VatCountry
{
    /** The 27 member states, ISO 3166-1 alpha-2. */
    public const EU = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV',
        'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    public static function isEu(?string $iso): bool
    {
        return $iso !== null && in_array(strtoupper($iso), self::EU, true);
    }

    /** The VAT prefix (VIES code) of an ISO country: GR → EL, the others as they are. */
    public static function prefixOf(string $iso): string
    {
        $iso = strtoupper($iso);

        return $iso === 'GR' ? 'EL' : $iso;
    }

    /** The ISO country of a VAT prefix: EL → GR, the others as they are; null when it is not a member state's. */
    public static function isoOf(string $prefix): ?string
    {
        $prefix = strtoupper($prefix);
        $iso = $prefix === 'EL' ? 'GR' : $prefix;

        // «GR» is not a VAT prefix: a Greek number written GR… is read as EL… (customers type it so).
        return in_array($iso, self::EU, true) ? $iso : null;
    }
}
