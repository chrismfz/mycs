<?php

namespace MyCS\GrEpp\Data;

use DateTimeImmutable;

/**
 * A domain as the registry holds it (domain:info with extdomain-1.3 and extSecDNS-1.0, grepp.md §5.3, §6). The
 * DateTimes are UTC instants; the days to store are expiresOn and createdOn, as the registry wrote them (its expiry
 * 2013-03-20T23:59:59.9Z is the 20th: never converted to the app's zone, where it would be the 21st).
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
        /** The expiry and registration days as the registry wrote them (Y-m-d): the dates to keep (review 1 of #185). */
        public ?string $expiresOn = null,
        public ?string $createdOn = null,
        /** @var array<string, string> status => the registry's reason, when it gives one (EPP and regulator statuses) */
        public array $statusReasons = [],
        /** @var list<string> the registry's comments on it (extdomain:comment) */
        public array $comments = [],
    ) {}
}
