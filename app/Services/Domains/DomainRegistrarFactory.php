<?php

namespace App\Services\Domains;

use App\Contracts\DomainRegistrar;
use App\Models\DomainRegistrarConnection;
use App\Support\Domains\DomainRegistrarCredentials;

/**
 * Turns a connection into the (adapter, credentials) pair a caller
 * needs: the adapter comes from the registry by the row's `registrar` key, the
 * credentials from its encrypted config + mode. Callers hold the connection, so
 * per-call passing of credentials stays explicit (no ambient credential
 * state). docs/domains/ekdosi-design.md §4.2.
 */
class DomainRegistrarFactory
{
    public function __construct(private readonly DomainRegistrarRegistry $registry) {}

    public function for(DomainRegistrarConnection $connection): DomainRegistrar
    {
        return $this->registry->for((string) $connection->registrar);
    }

    public function credentialsFor(DomainRegistrarConnection $connection): DomainRegistrarCredentials
    {
        return DomainRegistrarCredentials::fromConnection($connection);
    }
}
