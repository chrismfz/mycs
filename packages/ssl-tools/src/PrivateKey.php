<?php

namespace MyCS\SslTools;

use OpenSSLAsymmetricKey;
use SensitiveParameter;

/**
 * A private key, unencrypted, as PKCS#8 PEM. Its PEM is never in a dump, a var_export or a serialized copy: only
 * pem() gives it, to be encrypted for storage or handed to its owner.
 */
final class PrivateKey
{
    private function __construct(
        #[SensitiveParameter] private readonly string $pem,
        public readonly ?KeyType $type,
        public readonly int $bits,
        public readonly string $description,
        public readonly string $spkiSha256,
    ) {}

    public static function generate(KeyType $type): self
    {
        $key = openssl_pkey_new($type->options());
        if (!$key instanceof OpenSSLAsymmetricKey || !openssl_pkey_export($key, $pem)) {
            throw SslToolsException::because('The key could not be generated.');
        }
        SslToolsException::clearOpensslErrors();

        return self::fromKey($key, $pem);
    }

    /**
     * A pasted key (PKCS#8, or the older RSA or EC forms), re-exported as PKCS#8. An encrypted key (one with a
     * passphrase) is refused: it would be kept unreadable.
     */
    public static function parse(#[SensitiveParameter] string $text): self
    {
        if (Pem::blocks($text, ['ENCRYPTED PRIVATE KEY']) !== [] || str_contains($text, 'Proc-Type: 4,ENCRYPTED')) {
            throw SslToolsException::because('The key is encrypted with a passphrase: remove the passphrase first.');
        }
        $blocks = Pem::blocks($text, ['PRIVATE KEY', 'RSA PRIVATE KEY', 'EC PRIVATE KEY']);
        if (count($blocks) !== 1) {
            throw SslToolsException::because('Paste exactly one private key in PEM form.');
        }
        $key = openssl_pkey_get_private($blocks[0]);
        if (!$key instanceof OpenSSLAsymmetricKey || !openssl_pkey_export($key, $pem)) {
            throw SslToolsException::because('The private key could not be read.');
        }
        SslToolsException::clearOpensslErrors();

        return self::fromKey($key, $pem);
    }

    private static function fromKey(OpenSSLAsymmetricKey $key, #[SensitiveParameter] string $pem): self
    {
        [$type, $bits, $description] = Pem::keyInfo($key);

        return new self($pem, $type, $bits, $description, Pem::spkiSha256($key));
    }

    public function pem(): string
    {
        return $this->pem;
    }

    public function key(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private($this->pem);
        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw SslToolsException::because('The private key could not be read.');
        }

        return $key;
    }

    /** Its public key is the one in this CSR or certificate. */
    public function matches(Csr|Certificate $other): bool
    {
        return hash_equals($this->spkiSha256, $other->spkiSha256);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['type' => $this->type, 'bits' => $this->bits, 'spkiSha256' => $this->spkiSha256, 'pem' => '[hidden]'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new SslToolsException('A private key is not serialized.');
    }
}
