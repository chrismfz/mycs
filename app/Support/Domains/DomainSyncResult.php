<?php

namespace App\Support\Domains;

use App\Enums\DomainStatus;

/**
 * What a DomainRegistrar::syncDomain() pull found at the registry — the
 * REGISTRAR-truth side of the two clocks (docs/domains/README.md §3.4/§6.4).
 * `status` is the adapter's confident mapping to our lifecycle; null = the
 * registrar state has no safe mapping (we then keep the local status and
 * stash `rawStatus` in module_meta for the operator).
 *
 * Immutable.
 */
final class DomainSyncResult
{
    /**
     * @param  ?string  $expiresAt  Y-m-d, null when the registrar did not report one.
     * @param  list<string>  $nameservers
     * @param  array<string, string>  $contactHandles  contact type → reusable registrar
     *                                                 handle, when the same pull carried them (Openprovider does) — lets the
     *                                                 per-domain «Συγχρονισμός» also refresh contacts on unassigned rows
     *                                                 without a second domain fetch. [] = not reported.
     */
    public function __construct(
        public readonly ?string $expiresAt = null,
        public readonly array $nameservers = [],
        public readonly ?string $registrarDomainId = null,
        public readonly ?DomainStatus $status = null,
        public readonly ?string $rawStatus = null,
        public readonly array $contactHandles = [],
        /**
         * The ADAPTER's verdict that this record is a tombstone (deleted /
         * failed-request — e.g. Openprovider DEL/FAI): it represents no
         * ownership claim. The register adopt-guard treats it as not-ours;
         * the nightly sync ignores it (its own status rules apply).
         */
        public readonly bool $deadRecord = false,
        /**
         * Registrar-reported transfer lock / WHOIS privacy (A3d) — null =
         * not reported. Lets the sync CORRECT the local mirrors (a toggle at
         * the registrar panel must not leave the flags wrong forever).
         */
        public readonly ?bool $transferLock = null,
        public readonly ?bool $whoisPrivacy = null,
    ) {}
}
