<?php

namespace App\Support\Domains;

use App\Enums\DomainStatus;

/**
 * What a DomainRegistrar::syncDomain() pull found at the registry — the
 * REGISTRAR-truth side of the two clocks (docs/domains/ekdosi-design.md §3.4/§6.4).
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
        /** Y-m-d the domain was first registered (at any registrar), null when not reported. */
        public readonly ?string $registeredAt = null,
        /**
         * What else the registrar says about the domain, for staff to see without its panel (D4b): flat scalars
         * (lockable, privacy allowed, DNSSEC, auto-renew there, dates, nameserver glue…). Never a secret: the adapter
         * builds it from an allow-list, no auth code ever. [] = not reported.
         *
         * @var array<string, scalar|null>
         */
        public readonly array $details = [],
        /** Y-m-d the registrar's grace (soft quarantine) and redemption (hard quarantine) end, when it is in them (D5a). */
        public readonly ?string $graceEndsOn = null,
        public readonly ?string $redemptionEndsOn = null,
    ) {}

    /** Nothing was read (a write whose re-read failed): no truth to apply, the next sync brings it (review 7). */
    public function readNothing(): bool
    {
        return $this->rawStatus === null && $this->expiresAt === null && $this->nameservers === [] && $this->details === [];
    }
}
