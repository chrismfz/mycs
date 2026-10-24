<?php

namespace MyCS\SslTools;

use OpenSSLAsymmetricKey;

/** PEM blocks found in pasted text, and the public key's fingerprint by which a key, a CSR and a certificate are matched. */
final class Pem
{
    /** Anything pasted larger than this is not a key, a CSR or a short chain. */
    public const MAX_LENGTH = 65536;

    /**
     * Every block of this label (`CERTIFICATE`, `CERTIFICATE REQUEST`…) in the text, each as a clean PEM (its base64
     * re-wrapped at 64, `\n` line ends), in the order found.
     *
     * @param  list<string>  $labels
     * @return list<string>
     */
    public static function blocks(string $text, array $labels): array
    {
        if (strlen($text) > self::MAX_LENGTH) {
            throw SslToolsException::because('The text is too long.');
        }
        $alternatives = implode('|', array_map(fn (string $l) => preg_quote($l, '/'), $labels));
        preg_match_all('/-----BEGIN (' . $alternatives . ')-----([A-Za-z0-9+\/=\s]+?)-----END \1-----/', $text, $matches, PREG_SET_ORDER);
        $blocks = [];
        foreach ($matches as $match) {
            $body = preg_replace('/\s+/', '', $match[2]);
            if ($body === '' || base64_decode($body, true) === false) {
                continue;
            }
            $blocks[] = "-----BEGIN {$match[1]}-----\n" . chunk_split($body, 64, "\n") . "-----END {$match[1]}-----\n";
        }

        return $blocks;
    }

    /** The DER inside a PEM block. */
    public static function der(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END) [A-Z0-9 ]+-----|\s+/', '', $pem);
        $der = base64_decode((string) $body, true);
        if ($der === false || $der === '') {
            throw SslToolsException::because('The PEM block is not valid base64.');
        }

        return $der;
    }

    /** SHA-256 of the public key's SubjectPublicKeyInfo (DER), lower-case hex: the same for a key, its CSR and its certificate. */
    public static function spkiSha256(OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || !is_string($details['key'] ?? null)) {
            throw SslToolsException::because('The public key could not be read.');
        }

        return hash('sha256', self::der($details['key']));
    }

    /**
     * The key's type as MyCS names it, or null for another (an RSA 4096, an EC P-384…), and its size in bits.
     *
     * @return array{0: ?KeyType, 1: int, 2: string}
     */
    public static function keyInfo(OpenSSLAsymmetricKey $key): array
    {
        $details = openssl_pkey_get_details($key);
        if (!is_array($details)) {
            throw SslToolsException::because('The key could not be read.');
        }
        $bits = (int) ($details['bits'] ?? 0);

        return match ($details['type'] ?? null) {
            OPENSSL_KEYTYPE_RSA => [$bits === 2048 ? KeyType::Rsa2048 : null, $bits, "RSA {$bits}"],
            OPENSSL_KEYTYPE_EC => [($details['ec']['curve_name'] ?? null) === 'prime256v1' ? KeyType::EcP256 : null, $bits,
                'EC ' . (string) ($details['ec']['curve_name'] ?? $bits)],
            default => [null, $bits, 'other'],
        };
    }
}
