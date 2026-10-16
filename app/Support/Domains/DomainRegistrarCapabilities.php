<?php

namespace App\Support\Domains;

/**
 * What a registrar adapter can actually do (Πυλώνας A) — the UI/import adapt on
 * these flags instead of `if ($registrar === 'openprovider')` chains, mirroring
 * App\Support\Billing\SourceCapabilities. Declared BY each adapter, so a new
 * registrar states its abilities in one place. docs/domains/README.md §2.
 *
 * Immutable.
 */
final class DomainRegistrarCapabilities
{
    public function __construct(
        /** Can getTldPricing() feed the cost-sync (Openprovider yes, grEPP no). */
        public readonly bool $supportsPricingSync = false,
        /** Can listDomains()/getContact() feed the registrar-first import (grEPP has no list command — .gr comes from the grweb CSV export). */
        public readonly bool $supportsPortfolioImport = false,
        /** WHOIS privacy / ID protection toggle (.gr registry has none). */
        public readonly bool $supportsPrivacy = false,
        /** DNSSEC (DS record) management at the registry. */
        public readonly bool $supportsDnssec = false,
        /** Registrar transfer lock (.gr registry has none — grRLS is a separate product). */
        public readonly bool $supportsTransferLock = false,
        /** Inbound/outbound transfers through the adapter's API. */
        public readonly bool $supportsTransfer = false,
        /** Child/glue host records registered at the registry. */
        public readonly bool $supportsGlueHosts = false,
    ) {}

    /** The API-less baseline: a manually-managed registrar can do none of this. */
    public static function none(): self
    {
        return new self;
    }
}
