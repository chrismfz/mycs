<?php

namespace MyCS\SslTools;

use DateTimeImmutable;
use DateTimeZone;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

/** An X.509 certificate read from pasted text: its names, issuer, dates, serial and public key. */
final class Certificate
{
    /** @param list<string> $names */
    private function __construct(
        public readonly string $pem,
        public readonly string $commonName,
        public readonly array $names,
        public readonly string $issuer,
        public readonly string $serial,
        public readonly DateTimeImmutable $notBefore,
        public readonly DateTimeImmutable $notAfter,
        public readonly string $spkiSha256,
        public readonly ?KeyType $keyType,
        public readonly string $keyDescription,
    ) {}

    /** The first certificate in the text. */
    public static function parse(string $text): self
    {
        $blocks = Pem::blocks($text, ['CERTIFICATE']);
        if ($blocks === []) {
            throw SslToolsException::because('No certificate was found.');
        }

        return self::fromPem($blocks[0]);
    }

    /**
     * Every certificate in the text (a certificate and its chain, a CA bundle), in order.
     *
     * @return list<self>
     */
    public static function parseAll(string $text): array
    {
        return array_map(fn (string $pem) => self::fromPem($pem), Pem::blocks($text, ['CERTIFICATE']));
    }

    private static function fromPem(string $pem): self
    {
        $x509 = @openssl_x509_read($pem);
        $data = $x509 instanceof OpenSSLCertificate ? @openssl_x509_parse($x509, false) : false;
        $publicKey = $x509 instanceof OpenSSLCertificate ? @openssl_pkey_get_public($x509) : false;
        if (!is_array($data) || !$publicKey instanceof OpenSSLAsymmetricKey) {
            throw SslToolsException::because('The certificate could not be read.');
        }
        $names = [];
        foreach (explode(',', (string) ($data['extensions']['subjectAltName'] ?? '')) as $entry) {
            if (str_starts_with($entry = trim($entry), 'DNS:')) {
                $names[] = strtolower(substr($entry, 4));
            }
        }
        $text = fn (mixed $v) => strtolower(is_array($v) ? (string) end($v) : (string) $v);
        $issuer = $data['issuer']['commonName'] ?? $data['issuer']['organizationName'] ?? '';
        [$keyType, , $description] = Pem::keyInfo($publicKey);
        SslToolsException::clearOpensslErrors();

        return new self(
            $pem,
            $text($data['subject']['commonName'] ?? ''),
            array_values(array_unique($names)),
            is_array($issuer) ? (string) end($issuer) : (string) $issuer,
            strtoupper((string) ($data['serialNumberHex'] ?? '')),
            (new DateTimeImmutable('@' . (int) ($data['validFrom_time_t'] ?? 0)))->setTimezone(new DateTimeZone('UTC')),
            (new DateTimeImmutable('@' . (int) ($data['validTo_time_t'] ?? 0)))->setTimezone(new DateTimeZone('UTC')),
            Pem::spkiSha256($publicKey),
            $keyType,
            $description,
        );
    }

    /** Its public key is the one in this CSR. */
    public function matches(Csr $csr): bool
    {
        return hash_equals($this->spkiSha256, $csr->spkiSha256);
    }
}
