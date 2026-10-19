<?php

namespace App\Support\Domains;

use MyCS\GrEpp\Command\Rules;

/**
 * The choices a DNSSEC form offers (G8-4b admin, P3 portal), by the kind its registrar takes: DS at the .gr registry (its
 * own algorithms and digest types), DNSKEY at Openprovider; whatever a record read from the registrar has is kept.
 */
final class DnssecOptions
{
    /** DNSSEC algorithms (IANA): those registrars take today. */
    public const ALGORITHMS = [13 => '13 ECDSA P-256/SHA-256', 8 => '8 RSA/SHA-256', 14 => '14 ECDSA P-384/SHA-384',
        10 => '10 RSA/SHA-512', 15 => '15 Ed25519', 16 => '16 Ed448', 7 => '7 RSASHA1-NSEC3-SHA1', 5 => '5 RSA/SHA-1',
        6 => '6 DSA-NSEC3-SHA1', 3 => '3 DSA/SHA-1', 12 => '12 GOST R 34.10-2001'];

    public const DIGEST_TYPES = [1 => '1 SHA-1', 2 => '2 SHA-256', 3 => '3 GOST R 34.11-94', 4 => '4 SHA-384'];

    public const FLAGS = [257 => '257 KSK', 256 => '256 ZSK'];

    /** @return array<int, string> */
    public static function algorithms(string $kind, mixed $current = null): array
    {
        $options = $kind === DnssecRecord::DS
            ? array_intersect_key(self::ALGORITHMS, array_flip(Rules::DS_ALGORITHMS))
            : array_diff_key(self::ALGORITHMS, [3 => 1, 6 => 1, 12 => 1]);

        return self::withCurrent($options, $current);
    }

    /** @return array<int, string> */
    public static function digestTypes(mixed $current = null): array
    {
        return self::withCurrent(self::DIGEST_TYPES, $current);
    }

    /** @return array<int, string> */
    public static function flags(mixed $current = null): array
    {
        return self::withCurrent(self::FLAGS, $current);
    }

    /**
     * A list's options with the value already there, whatever it is: a record read from the registrar is kept as it is.
     *
     * @param  array<int, string>  $options
     * @return array<int, string>
     */
    public static function withCurrent(array $options, mixed $current): array
    {
        return is_numeric($current) && !isset($options[(int) $current]) ? $options + [(int) $current => (string) $current] : $options;
    }
}
