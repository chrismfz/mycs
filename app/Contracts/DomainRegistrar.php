<?php

namespace App\Contracts;

use App\Models\Domain;
use App\Support\Domains\AvailabilityResult;
use App\Support\Domains\DomainChanges;
use App\Support\Domains\DomainRegistrarCapabilities;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use App\Support\Domains\RegistrarContact;
use App\Support\Domains\RegistrarDomainPage;
use App\Support\Domains\TldPricing;

/**
 * One registrar adapter per provider (Πυλώνας A) — the domains sibling of
 * EInvoiceProviderTransport: it knows ONLY how to talk to one registrar's API
 * (Openprovider REST, grEPP XML-over-HTTPS, …). Lifecycle, billing and
 * persistence live elsewhere. Adding a registrar = one class implementing this
 * + one line in config/ekdosi.php → domains.registrars.
 *
 * A0 ships the seam with the methods the foundation actually exercises; the
 * state-changing surface (register/renew/transfer/NS/contacts/DNSSEC/sync)
 * lands with A2/A3 per the full blueprint in docs/domains/README.md §4.1 —
 * extended here as each slice arrives, so the Null adapter never carries dead
 * unimplementable signatures.
 */
interface DomainRegistrar
{
    /** Stable registrar key (e.g. 'openprovider', 'grepp', 'manual'); matches the registry key. */
    public function key(): string;

    /** What this adapter can do — the UI/import adapt on these flags (README §2). */
    public function capabilities(): DomainRegistrarCapabilities;

    /**
     * Smoke-test credentials + reachability (the connection «Έλεγχος σύνδεσης»
     * action). CONTRACT: return false on unreachable/bad-creds/transport failure
     * — never throw. Diagnostics must stay safe to click.
     */
    public function ping(DomainRegistrarCredentials $credentials): bool;

    /**
     * Is the name registrable right now? Throws DomainRegistrarNotConfigured on
     * an API-less/unwired registrar — availability feeds a register decision, so
     * it must fail loudly, never guess.
     */
    public function checkAvailability(string $fqdn, DomainRegistrarCredentials $credentials): AvailabilityResult;

    /**
     * Pull the registrar truth for one domain (expiry/status/NS/registrar id) —
     * the A2 read clock. Throws DomainRegistrarNotConfigured on an API-less
     * registrar; transport/API failures throw RuntimeException (the caller
     * records them in domains.sync_error, never swallows them).
     */
    public function syncDomain(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult;

    /**
     * What the registrar charges US for one TLD (the A2c cost-sync feed) —
     * meaningful only when capabilities()->supportsPricingSync. Throws
     * DomainRegistrarNotConfigured on an API-less registrar; transport/API
     * failures throw RuntimeException (the command counts + reports them).
     */
    public function getTldPricing(string $tld, DomainRegistrarCredentials $credentials): TldPricing;

    /**
     * One page of the account's OWN domain portfolio (the A2c registrar-first
     * import feed, README §9) — meaningful only when
     * capabilities()->supportsPortfolioImport. Throws DomainRegistrarNotConfigured
     * on an API-less registrar; transport/API failures throw RuntimeException.
     */
    public function listDomains(DomainRegistrarCredentials $credentials, int $offset, int $limit): RegistrarDomainPage;

    /**
     * Resolve one reusable contact handle to its details (import assign-aid).
     * Returns null when the registrar doesn't know the handle; transport/API
     * failures throw RuntimeException (the import warns + continues — a broken
     * contact must never kill a domain import).
     */
    public function getContact(string $handle, DomainRegistrarCredentials $credentials): ?RegistrarContact;

    /**
     * WRITE (A3): renew the domain for N years at the registrar — REAL MONEY
     * at the registrar side. Callers go through DomainRenewalService ONLY
     * (it owns the §6.6 adopt-on-already-renewed guard + the audit log);
     * never call this directly from UI/observer code. Returns the FRESH
     * registrar truth after the renewal (same shape as syncDomain, so the
     * caller applies it through the one truth-apply path). Throws
     * DomainRegistrarNotConfigured on an API-less registrar; RuntimeException
     * on transport/API failure.
     */
    public function renew(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult;

    /**
     * WRITE (A3b): register the domain for N years — REAL MONEY at the
     * registrar. Callers go through DomainRegistrationService ONLY (it owns
     * the adopt-on-retry guard, the availability pre-check and the audit
     * log); never call this directly. The adapter reads the domain's loaded
     * contacts (ensuring reusable registrar handles as needed — returned in
     * the result's contactHandles so the caller can persist them) and its
     * nameservers. Returns the fresh registrar truth (id/expiry/status).
     * Throws DomainRegistrarNotConfigured on an API-less registrar;
     * RuntimeException on transport/API failure.
     */
    public function register(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult;

    /**
     * WRITE (A3c): start an INBOUND transfer (auth code from the losing
     * registrar) — REAL MONEY (gTLD transfers charge one renewal year).
     * Callers go through DomainTransferService ONLY. Async by nature: the
     * result usually carries a pending status; the nightly sync drives the
     * §6.3 state machine to completion. Throws DomainRegistrarNotConfigured
     * on an API-less registrar; RuntimeException on transport/API failure.
     */
    public function transferIn(Domain $domain, string $authCode, DomainRegistrarCredentials $credentials): DomainSyncResult;

    /**
     * READ (A3c): the domain's EPP/auth code — the transfer-OUT aid (§6.3:
     * outgoing is operator-gated v1). Sensitive: callers must never persist
     * it; the audit row records only THAT it was retrieved. Null when the
     * registrar has none for this domain.
     */
    public function getEppCode(Domain $domain, DomainRegistrarCredentials $credentials): ?string;

    /**
     * WRITE (A3d): change registrar-side settings — nameservers / transfer
     * lock / WHOIS privacy / contact handles, whatever `$changes` carries
     * (null fields untouched). NOT a charge, but still registrar truth:
     * callers go through DomainManagementService ONLY (per-operation audit
     * log + the shared write guards). Returns the fresh registrar truth so
     * the caller applies it through the one truth-apply path. Throws
     * DomainRegistrarNotConfigured on an API-less registrar; RuntimeException
     * on transport/API failure.
     */
    public function updateDomain(Domain $domain, DomainChanges $changes, DomainRegistrarCredentials $credentials): DomainSyncResult;

    /**
     * WRITE (A3d): restore a name from redemption — REAL (usually LARGE)
     * MONEY at the registrar. Callers go through DomainManagementService ONLY
     * (it owns the sync-first adopt guard — a name that came back some other
     * way must never be re-charged — and the audit log). Returns the fresh
     * registrar truth after the restore. Throws DomainRegistrarNotConfigured
     * on an API-less registrar; RuntimeException on transport/API failure.
     */
    public function restore(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult;
}
