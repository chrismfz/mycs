<?php

namespace MyCS\GrEpp\Data;

use DateTimeImmutable;

/**
 * A domain as the registry holds it (domain:info with extdomain-1.3 and extSecDNS-1.0, grepp.md §5.3, §6). Dates are UTC
 * (the expiry is 23:59:59.9Z of its day).
 */
final readonly class DomainInfo
{
    /**
     * @param  list<string>  $statuses  EPP statuses (ok, inactive, pendingCreate, serverUpdateProhibited, …)
     * @param  list<string>  $regulatorStatuses  the regulator's (EETT), above the server's
     * @param  array<string, list<string>>  $contacts  role (admin, tech, billing) => contact ids
     * @param  list<string>  $nameservers  its delegation (hostObj)
     * @param  list<string>  $hosts  the hosts under it (subordinate)
     * @param  list<BundleName>  $bundle
     */
    public function __construct(
        public string $name,
        public string $roid,
        public array $statuses,
        public array $regulatorStatuses,
        public ?string $registrant,
        public array $contacts,
        public array $nameservers,
        public array $hosts,
        public ?string $clientId,
        public ?DateTimeImmutable $created,
        public ?DateTimeImmutable $updated,
        public ?DateTimeImmutable $expires,
        public ?DateTimeImmutable $transferred,
        public ?string $protocol,
        public ?string $punycode,
        public array $bundle,
        public ?DsValidation $dsValidation,
    ) {}
}
