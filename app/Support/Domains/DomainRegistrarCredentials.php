<?php

namespace App\Support\Domains;

use App\Models\DomainRegistrarConnection;

/**
 * A registrar connection's credentials (docs/domains.md D-21), read from its encrypted Settings: a key/value bag (+
 * sandbox), since registrars authenticate differently (Openprovider username/password, the .gr registry its EPP
 * account), so a new registrar needs no schema change. Passed per call, never held. A dump or a log of it shows the
 * keys, never the values.
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
        return new self(
            config: $connection->credentials(),
            sandbox: $connection->mode !== 'production',
        );
    }

    /** @return array<string, mixed> the keys only: a dump or an exception's context never carries a secret */
    public function __debugInfo(): array
    {
        return ['keys' => array_keys($this->config), 'sandbox' => $this->sandbox];
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
