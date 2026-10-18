<?php

namespace App\Contracts;

use App\Models\Domain;
use App\Support\Domains\ChildHost;
use App\Support\Domains\DomainRegistrarCredentials;

/**
 * A registrar whose nameservers are objects of their own (EPP hosts: the .gr registry): a domain's child nameservers
 * (ns1.example.gr under example.gr) with their glue addresses, listed, made, changed and deleted. Openprovider instead
 * takes a nameserver's IP with the domain's nameservers (D-16): no such objects.
 */
interface ManagesChildHosts
{
    /** @return list<ChildHost> the hosts the registrar has under the domain, with their addresses */
    public function childHosts(Domain $domain, DomainRegistrarCredentials $credentials): array;

    /** @param  list<string>  $addresses  IPv4 and IPv6 */
    public function createChildHost(Domain $domain, string $host, array $addresses, DomainRegistrarCredentials $credentials): void;

    /** @param  list<string>  $addresses  the full set: what is missing added, what is extra removed */
    public function updateChildHost(Domain $domain, string $host, array $addresses, DomainRegistrarCredentials $credentials): void;

    /** Only one no domain uses (the registry refuses a linked host). */
    public function deleteChildHost(Domain $domain, string $host, DomainRegistrarCredentials $credentials): void;
}
