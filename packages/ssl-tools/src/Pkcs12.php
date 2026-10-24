<?php

namespace MyCS\SslTools;

use SensitiveParameter;

/** A certificate, its key and its chain in one password-protected PKCS#12 (.pfx) file, as Windows and IIS take it. */
final class Pkcs12
{
    public const MIN_PASSWORD = 8;

    /** @param list<Certificate> $chain */
    public static function export(Certificate $certificate, #[SensitiveParameter] PrivateKey $key, array $chain, #[SensitiveParameter] string $password): string
    {
        if (strlen($password) < self::MIN_PASSWORD) {
            throw SslToolsException::because('The password is too short.');
        }
        if (!$key->matches($certificate)) {
            throw SslToolsException::because('The key does not belong to the certificate.');
        }
        $ok = openssl_pkcs12_export($certificate->pem, $out, $key->key(), $password, [
            'extracerts' => array_map(fn (Certificate $c) => $c->pem, $chain),
            'friendly_name' => $certificate->commonName,
        ]);
        if (!$ok) {
            throw SslToolsException::because('The .pfx file could not be made.');
        }
        SslToolsException::clearOpensslErrors();

        return $out;
    }
}
