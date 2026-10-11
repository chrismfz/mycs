<?php

namespace App\Support\Domains;

/**
 * What a management write (A3d) asks the registrar to change — null = «don't
 * touch». One DTO for the whole PUT-shaped surface so an adapter can batch
 * everything one API call carries (Openprovider's PUT /v1beta/domains/{id})
 * while the service keeps per-operation actions in the audit log.
 *
 * DNSSEC key management is DELIBERATELY absent v1 (docs/BACKLOG.md) — the
 * enabled/disabled mirror is read-only until the key-material UX is designed.
 *
 * Immutable.
 */
final class DomainChanges
{
    /**
     * @param  ?list<string>  $nameservers  full replacement set, null = keep
     * @param  bool  $applyContacts  push the domain's local contacts (the
     *                               adapter ensures reusable handles and reassigns them on the domain)
     */
    public function __construct(
        public readonly ?array $nameservers = null,
        public readonly ?bool $transferLock = null,
        public readonly ?bool $whoisPrivacy = null,
        public readonly bool $applyContacts = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->nameservers === null
            && $this->transferLock === null
            && $this->whoisPrivacy === null
            && ! $this->applyContacts;
    }
}
