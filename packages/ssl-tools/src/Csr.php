<?php

namespace MyCS\SslTools;

use OpenSSLAsymmetricKey;
use OpenSSLCertificateSigningRequest;
use SensitiveParameter;

/** A certificate signing request: made for a key and its names, or read from pasted text. */
final class Csr
{
    private const EXTENSION_REQUEST = '1.2.840.113549.1.9.14';

    private const SUBJECT_ALT_NAME = '2.5.29.17';

    /** X.520's upper bound for a commonName: OpenSSL refuses a longer one. */
    public const MAX_COMMON_NAME = 64;

    /** @param list<string> $names */
    private function __construct(
        public readonly string $pem,
        public readonly string $commonName,
        public readonly array $names,
        public readonly string $spkiSha256,
        public readonly ?KeyType $keyType,
        public readonly string $keyDescription,
    ) {}

    /**
     * A CSR for these names (the first is its common name; every one is also a subjectAltName, as CAs ask), signed
     * with SHA-256. Only host names: lower-case ASCII (IDN as punycode), a wildcard only as the first label.
     *
     * @param  list<string>  $names
     */
    public static function create(#[SensitiveParameter] PrivateKey $key, array $names): self
    {
        $names = array_values(array_unique(array_map(fn (string $n) => strtolower(trim($n)), $names)));
        if ($names === [] || count($names) > 100) {
            throw SslToolsException::because('A request needs one to 100 names.');
        }
        foreach ($names as $name) {
            if (!self::validName($name)) {
                throw SslToolsException::because('A name is not a valid host name.');
            }
        }
        if (strlen($names[0]) > self::MAX_COMMON_NAME) {
            throw SslToolsException::because('The first name is longer than a certificate\'s common name allows (64 characters).');
        }
        // openssl_csr_new takes its extensions from a config file only: written for this call, then removed. The names
        // were checked above (letters, digits, «-», «.», a leading «*.»), so nothing in them can change the file.
        $config = tempnam(sys_get_temp_dir(), 'csr');
        if ($config === false) {
            throw SslToolsException::because('The request could not be made.');
        }
        try {
            $sans = implode(',', array_map(fn (string $n) => "DNS:{$n}", $names));
            file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[ext]\nsubjectAltName = {$sans}\n");
            $privateKey = $key->key();
            $csr = @openssl_csr_new(['commonName' => $names[0]], $privateKey, ['config' => $config, 'req_extensions' => 'ext', 'digest_alg' => 'sha256']);
            if (!$csr instanceof OpenSSLCertificateSigningRequest || !openssl_csr_export($csr, $pem)) {
                throw SslToolsException::because('The request could not be made.');
            }
        } finally {
            @unlink($config);
        }
        SslToolsException::clearOpensslErrors();

        return self::parse($pem);
    }

    /** The first CSR in the text: its common name, its requested names, its key. Its signature must hold. */
    public static function parse(string $text): self
    {
        $blocks = Pem::blocks($text, ['CERTIFICATE REQUEST', 'NEW CERTIFICATE REQUEST']);
        if ($blocks === []) {
            throw SslToolsException::because('No certificate request (CSR) was found.');
        }
        $pem = str_replace('NEW CERTIFICATE REQUEST', 'CERTIFICATE REQUEST', $blocks[0]);
        $publicKey = @openssl_csr_get_public_key($pem);
        $subject = @openssl_csr_get_subject($pem, false);
        if (!$publicKey instanceof OpenSSLAsymmetricKey || !is_array($subject) || !self::signatureHolds($pem)) {
            throw SslToolsException::because('The certificate request (CSR) could not be read.');
        }
        $commonName = $subject['commonName'] ?? '';
        $commonName = strtolower(is_array($commonName) ? (string) end($commonName) : (string) $commonName);
        [$keyType, , $description] = Pem::keyInfo($publicKey);
        SslToolsException::clearOpensslErrors();

        return new self($pem, $commonName, self::requestedNames(Pem::der($pem)), Pem::spkiSha256($publicKey), $keyType, $description);
    }

    /**
     * Lower-case ASCII host name, at least two labels, a wildcard only as the whole first label, a top-level label with
     * a letter (never an IP address).
     */
    public static function validName(string $name): bool
    {
        $label = '(?!-)[a-z0-9-]{1,63}(?<!-)';

        return strlen($name) <= 253 && preg_match('/^(\*\.)?' . $label . '(\.' . $label . ')+$/', $name) === 1
            && preg_match('/[a-z]/', (string) substr((string) strrchr($name, '.'), 1)) === 1;
    }

    /** OpenSSL checks the request's self-signature (made by the key it carries). */
    private static function signatureHolds(string $pem): bool
    {
        // openssl_csr_* has no verify; a request signed by another key is refused by openssl_csr_sign's own check,
        // which a throwaway self-signed certificate runs without keeping anything.
        $probe = openssl_pkey_new(KeyType::EcP256->options());
        if (!$probe instanceof OpenSSLAsymmetricKey) {
            return false;
        }
        $signed = @openssl_csr_sign($pem, null, $probe, 1, ['digest_alg' => 'sha256']);

        return $signed !== false;
    }

    /**
     * The DNS names in the request's subjectAltName extension (extensionRequest attribute).
     *
     * @return list<string>
     */
    private static function requestedNames(string $der): array
    {
        $root = Der::read($der, 0);
        $info = Der::children($der, $root)[0] ?? throw SslToolsException::because('The request is malformed.');
        $names = [];
        foreach (Der::children($der, $info) as $part) {
            if ($part['tag'] !== 0xA0) { // [0] attributes
                continue;
            }
            foreach (Der::children($der, $part) as $attribute) {
                $fields = Der::children($der, $attribute);
                if (count($fields) < 2 || $fields[0]['tag'] !== Der::OID || Der::oid(Der::content($der, $fields[0])) !== self::EXTENSION_REQUEST) {
                    continue;
                }
                foreach (Der::children($der, $fields[1]) as $extensions) {
                    foreach (Der::children($der, $extensions) as $extension) {
                        $items = Der::children($der, $extension);
                        if ($items === [] || Der::oid(Der::content($der, $items[0])) !== self::SUBJECT_ALT_NAME) {
                            continue;
                        }
                        $value = end($items);
                        if ($value['tag'] !== Der::OCTET_STRING) {
                            continue;
                        }
                        $inner = Der::content($der, $value);
                        foreach (Der::children($inner, Der::read($inner, 0)) as $general) {
                            if ($general['tag'] === 0x82) { // [2] dNSName
                                $names[] = strtolower(Der::content($inner, $general));
                            }
                        }
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }
}
