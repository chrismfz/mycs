<?php

namespace App\Support\Domains;

use App\Models\DomainRegistrarConnection;

/**
 * Per-connection registrar credentials, read from the encrypted
 * domain_registrar_connections.config blob. Deliberately a generic key/value bag
 * (+ sandbox flag) rather than typed fields — registrars' auth differs wildly
 * (Openprovider username/password → bearer, grEPP EPP host/user/pass) — so a new
 * registrar never needs a schema change. Verbatim the ProviderCredentials idiom
 * (docs/domains/README.md §4.2).
 *
 * Immutable.
 */
final class DomainRegistrarCredentials
{
    /**
     * @param  array<string, mixed>  $config  Decrypted connection config (username,
     *                                        password, epp_host, …).
     * @param  bool  $sandbox  True for the sandbox/UAT environment.
     */
    public function __construct(
        public readonly array $config = [],
        public readonly bool $sandbox = true,
    ) {}

    /**
     * Build from a connection row: the encrypted config blob + the mode column.
     * Fail-safe direction: ONLY an explicit 'production' hits the live endpoint —
     * 'off' / 'sandbox' / any typo stays sandbox, so a bad mode value can never
     * accidentally register/renew against production.
     */
    public static function fromConnection(DomainRegistrarConnection $connection): self
    {
        $config = $connection->config;
        if (! is_array($config)) {
            $config = [];
        }

        return new self(
            config: $config,
            sandbox: ($connection->mode ?? 'off') !== 'production',
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->config) && $this->config[$key] !== null && $this->config[$key] !== '';
    }
}
