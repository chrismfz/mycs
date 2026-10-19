<?php

namespace App\Contracts;

use App\Models\Domain;
use App\Support\Domains\DnssecRecord;
use App\Support\Domains\DomainRegistrarCredentials;

/**
 * A registrar that keeps a domain's DNSSEC records (G8-4b): DS records (the .gr registry, which checks them against the
 * domain's nameservers before publishing) or DNSKEYs (Openprovider, which makes the DS). The zone is signed first, at its
 * nameservers; a record that does not match it breaks the domain for validating resolvers.
 */
interface ManagesDnssec
{
    /** Which records it takes: DnssecRecord::DS or DnssecRecord::DNSKEY. */
    public function dnssecKind(): string;

    /** @return list<DnssecRecord> the domain's records as the registrar has them now */
    public function dnssecRecords(Domain $domain, DomainRegistrarCredentials $credentials): array;

    /**
     * The domain's records set to these (the full set: what is missing added, what is extra removed; none: unsigned).
     *
     * @param  list<DnssecRecord>  $records
     * @param  list<DnssecRecord>|null  $expected  what staff saw when they changed it: the registrar having another set now
     *                                             (one added since, elsewhere) refuses it, nothing sent (ADV-1 of #210)
     * @return string|null what the registrar said of it, when it is not done at once (the .gr registry's check)
     */
    public function setDnssec(Domain $domain, array $records, DomainRegistrarCredentials $credentials, ?array $expected = null): ?string;
}
