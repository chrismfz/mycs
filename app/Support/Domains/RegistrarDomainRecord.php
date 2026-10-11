<?php

namespace App\Support\Domains;

use App\Enums\DomainStatus;

/**
 * One domain as the registrar's ACCOUNT LISTING reports it (Πυλώνας A / A2c
 * registrar-first import, README §9) — the same truth shape as DomainSyncResult
 * plus the identity (sld/tld as the REGISTRAR splits them — it knows co.uk is
 * one extension) and the reusable contact handles to resolve.
 *
 * Immutable.
 */
final class RegistrarDomainRecord
{
    public function __construct(
        /** Normalized, lowercased; the registrar's own split (handles multi-label TLDs). */
        public readonly string $sld,
        public readonly string $tld,
        /** 'Y-m-d' or null when the registrar didn't report it. */
        public readonly ?string $expiresAt = null,
        public readonly ?string $registeredAt = null,
        public readonly ?string $registrarDomainId = null,
        /** Confident mapping only — null = unknown, keep/default locally. */
        public readonly ?DomainStatus $status = null,
        public readonly ?string $rawStatus = null,
        /** @var list<string> lowercased hostnames; [] = not reported. */
        public readonly array $nameservers = [],
        /** Registrar-side autorenew when reported (null = unknown) — used ONLY on create. */
        public readonly ?bool $autoRenew = null,
        /** @var array<string, string> contact type (registrant|admin|tech|billing) → handle. */
        public readonly array $contactHandles = [],
        /** Registrar-reported transfer lock / WHOIS privacy (null = not reported). */
        public readonly ?bool $transferLock = null,
        public readonly ?bool $whoisPrivacy = null,
    ) {}

    public function fqdn(): string
    {
        return $this->sld.'.'.$this->tld;
    }
}
