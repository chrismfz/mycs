<?php

namespace MyCS\SslTools;

/** The keys MyCS makes: RSA 2048 (works everywhere) and EC P-256 (smaller, faster). */
enum KeyType: string
{
    case Rsa2048 = 'rsa2048';
    case EcP256 = 'ec-p256';

    /** @return array<string, mixed> openssl_pkey_new's options */
    public function options(): array
    {
        return match ($this) {
            self::Rsa2048 => ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048],
            self::EcP256 => ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Rsa2048 => 'RSA 2048',
            self::EcP256 => 'EC P-256',
        };
    }
}
