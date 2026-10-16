<?php

namespace App\Support\Domains;

use App\Models\DomainRegistrarConnection;
use JsonSerializable;
use LogicException;
use SensitiveParameterValue;

/**
 * A registrar connection's credentials (docs/domains.md D-21), read from its encrypted Settings: a key/value bag (+
 * sandbox), since registrars authenticate differently (Openprovider username/password, the .gr registry its EPP
 * account), so a new registrar needs no schema change. Passed per call, never held. A dump or a log of it shows the
 * keys, never the values.
 *
 * Immutable.
 */
final class DomainRegistrarCredentials implements JsonSerializable
{
    /** The values, wrapped: var_export, print_r, var_dump and a stack trace show nothing (review F6). */
    private readonly SensitiveParameterValue $config;

    /**
     * @param  array<string, mixed>  $config  the connection's credentials (username, password, …)
     * @param  bool  $sandbox  true for the registrar's test system
     */
    public function __construct(array $config = [], public readonly bool $sandbox = true)
    {
        $this->config = new SensitiveParameterValue($config);
    }

    /**
     * From a connection: its encrypted Settings and its mode. Only an explicit «production» reaches the live
     * registrar: off, sandbox or anything else stays on the test system.
     */
    public static function fromConnection(DomainRegistrarConnection $connection): self
    {
        return new self(
            config: $connection->credentials(),
            sandbox: $connection->mode !== 'production',
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config->getValue()[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        $value = $this->config->getValue()[$key] ?? null;

        return $value !== null && $value !== '';
    }

    /** @return array<string, mixed> the keys only: a dump never carries a secret */
    public function __debugInfo(): array
    {
        return ['keys' => array_keys($this->config->getValue()), 'sandbox' => $this->sandbox];
    }

    /** @return array<string, mixed> the keys only: JSON (a log's context) never carries a secret */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** Never queued or cached: credentials are read from the connection when needed. */
    public function __serialize(): array
    {
        throw new LogicException('Registrar credentials are never serialised: pass the connection instead.');
    }
}
