<?php

namespace App\Services\Domains\Registrars;

use App\Contracts\DomainRegistrar;
use App\Contracts\ListsTldPricing;
use App\Contracts\ManagesDnssec;
use App\Contracts\ReadsAccountBalance;
use App\Contracts\UpdatesContacts;
use App\Enums\DomainStatus;
use App\Models\Contact;
use App\Models\Domain;
use App\Services\Domains\DnssecChangedSinceRead;
use App\Services\Domains\DomainNotFoundAtRegistrar;
use App\Services\Domains\DomainRegistrarNotConfigured;
use App\Services\Domains\Pricing\TldPriceMath;
use App\Services\Domains\RegistrarNotAsked;
use App\Services\Domains\RegistrarOutcomeUnknown;
use App\Services\Domains\RegistrarRefused;
use App\Services\Domains\TldNotOffered;
use App\Support\Domains\AvailabilityResult;
use App\Support\Domains\DnssecRecord;
use App\Support\Domains\DomainChanges;
use App\Support\Domains\DomainRegistrarCapabilities;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use App\Support\Domains\Phone;
use App\Support\Domains\RegistrarBalance;
use App\Support\Domains\RegistrarContact;
use App\Support\Domains\RegistrarDomainPage;
use App\Support\Domains\RegistrarDomainRecord;
use App\Support\Domains\TldPricing;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Openprovider REST adapter (docs/domains.md D2), from Ekdosi through the spike, with the three fixes of the live
 * nixpal.com transfer (area code, glue IPs for in-domain nameservers, no expiry adopted while pending).
 *
 * Writes that cost money (register, renew, transfer, restore) and change the domain (update) are called ONLY by the
 * domain services, which hold the per-domain lock, log every attempt and adopt what the registrar already did (D-2,
 * D-17); never from the UI. Autorenew is always off: the billing clock is ours (D-12).
 *
 * Auth: POST /v1/auth/login (plaintext password over HTTPS — the MD5/token
 * variant is legacy XML-RPC) → bearer, ~24h TTL. We cache it for 6h keyed by
 * (endpoint × username) and re-login ONCE on a 401 mid-flight.
 */
class OpenproviderRegistrar implements DomainRegistrar, ListsTldPricing, ManagesDnssec, ReadsAccountBalance, UpdatesContacts
{
    private const PRODUCTION_URL = 'https://api.openprovider.eu';

    // HTTP on :8480 and a .nl host — the registrar's documented sandbox; the
    // old *.cte.openprovider.eu was retired (docs/domains/ekdosi-design.md §4.3).
    private const SANDBOX_URL = 'http://api.sandbox.openprovider.nl:8480';

    private const TOKEN_TTL_SECONDS = 6 * 3600;

    /** Pending: requested, not done. Our status stays what the caller set (pending register or transfer). */
    private const PENDING_STATUSES = ['PEN', 'REQ'];

    /** Tombstone statuses — a record in these represents NO ownership claim. */
    private const DEAD_STATUSES = ['DEL', 'FAI'];

    /** The registrar's record fields staff see (D4b). An allow-list: the auth codes are never on it. */
    private const DETAIL_KEYS = ['status', 'type', 'creation_date', 'registry_expiration_date', 'renewal_date', 'last_changed',
        'is_lockable', 'is_locked', 'is_private_whois_allowed', 'is_private_whois_enabled', 'is_dnssec_enabled', 'dnssec',
        'autorenew', 'can_renew', 'is_premium', 'is_abusive', 'trade_allowed', 'modify_owner_allowed',
        'transfer_cancel_supported', 'is_hosted_whois', 'owner_company_name',
        // Ours: a live registration of the name for another owner, next to this one's tombstone (ADV-3 of #308).
        'other_live_record'];

    /** Its envelope's code for a TLD it does not sell («invalid extension»), answered with an HTTP 500 (live, 2026-10-24). */
    private const INVALID_EXTENSION = 307;

    /** Requests in flight at once when reading many TLDs (a TLD read takes it ~2s, a page of 500 ~6s: live, 2026-10-24). */
    private const POOL_SIZE = 6;

    /** TLDs per page of its list (2,040 TLDs on 2026-10-24), and at most this many pages: never an endless loop. */
    private const TLD_PAGE = 500;

    private const MAX_TLD_PAGES = 40;

    /** Openprovider price block → our DomainTldPrice operation (D-6, D-8: a restore is the redemption fee). */
    private const PRICE_KEYS = [
        'create_price' => 'register',
        'renew_price' => 'renew',
        'transfer_price' => 'transfer',
        'restore_price' => 'redemption',
    ];

    public function key(): string
    {
        return 'openprovider';
    }

    public function capabilities(): DomainRegistrarCapabilities
    {
        return new DomainRegistrarCapabilities(
            supportsPricingSync: true,
            supportsPortfolioImport: true,
            supportsPrivacy: true,
            supportsDnssec: true,
            supportsTransferLock: true,
            supportsTransfer: true,
            supportsGlueHosts: true,
        );
    }

    /** Contract: never throws — false on missing creds / bad auth / transport failure. */
    public function ping(DomainRegistrarCredentials $credentials): bool
    {
        try {
            return $this->freshToken($credentials) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /** Seconds a request may take: 30, shorter for the shop's search (checkAvailabilityMany). */
    private int $timeout = 30;

    /**
     * Several names in one request (D8a review R1: one call per search, not one per name), within $timeout seconds.
     * Names Openprovider does not answer for are left out.
     *
     * @param  list<string>  $fqdns
     * @return array<string, AvailabilityResult> by name
     */
    public function checkAvailabilityMany(array $fqdns, DomainRegistrarCredentials $credentials, int $timeout = 5): array
    {
        $domains = [];
        foreach ($fqdns as $fqdn) {
            [$name, $extension] = $this->splitFqdn($fqdn);
            $domains[$name . '.' . $extension] = ['name' => $name, 'extension' => $extension];
        }
        $previous = $this->timeout;
        $this->timeout = $timeout;
        try {
            $response = $this->request($credentials, 'POST', '/v1/domains/check', ['domains' => array_values($domains)]);
        } finally {
            $this->timeout = $previous;
        }
        $results = [];
        foreach ((array) $response->json('data.results') as $i => $result) {
            if (!is_array($result)) {
                continue;
            }
            // By its own name, else by position (the order asked).
            $fqdn = is_string($result['domain'] ?? null) ? mb_strtolower($result['domain']) : (array_keys($domains)[$i] ?? null);
            if ($fqdn === null || !isset($domains[$fqdn])) {
                continue;
            }
            $status = (string) ($result['status'] ?? '');
            $results[$fqdn] = new AvailabilityResult(fqdn: $fqdn, available: $status === 'free',
                reason: $status === 'free' ? null : (is_scalar($result['reason'] ?? null) ? (string) $result['reason'] : ($status !== '' ? $status : null)),
                premium: !empty($result['premium']) || !empty($result['is_premium']));
        }

        return $results;
    }

    public function checkAvailability(string $fqdn, DomainRegistrarCredentials $credentials): AvailabilityResult
    {
        [$name, $extension] = $this->splitFqdn($fqdn);
        $fqdn = $name . '.' . $extension; // normalized (lowercased/trimmed)

        $response = $this->request($credentials, 'POST', '/v1/domains/check', [
            'domains' => [['name' => $name, 'extension' => $extension]],
        ]);

        $result = $response->json('data.results.0');
        if (!is_array($result)) {
            throw new RuntimeException('Το Openprovider δεν επέστρεψε αποτέλεσμα διαθεσιμότητας για το ' . $fqdn . '.');
        }

        $status = (string) ($result['status'] ?? '');
        // The API may return a structured (array) reason for premium/claims
        // names — only a scalar is presentable; fall back to the raw status.
        $reason = is_scalar($result['reason'] ?? null) ? (string) $result['reason'] : null;

        return new AvailabilityResult(
            fqdn: $fqdn,
            available: $status === 'free',
            reason: $status === 'free' ? null : ($reason ?? ($status !== '' ? $status : null)),
            // Any premium payload = a NON-standard price — the register path
            // must refuse rather than charge an unknown amount (§6.1).
            premium: !empty($result['premium']) || !empty($result['is_premium']),
        );
    }

    /**
     * Registrar truth for one domain. Uses the stored numeric OP id when we
     * have it; otherwise resolves it via ?full_name= (and the result carries it
     * so the caller can persist it — docs/domains/ekdosi-design.md §4.3 gap note).
     */
    public function syncDomain(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        return $this->syncResultFrom($this->fetchDomainData($domain, $credentials));
    }

    /**
     * WRITE (A3): renew at Openprovider — POST /v1/domains/{id}/renew.
     * ONLY DomainRenewalService calls this (it owns the §6.6 adopt guard +
     * the audit log). Uses the stored OP id, else resolves it by name,
     * fires the renewal, then RE-FETCHES the domain so the caller applies the
     * post-renewal truth through the one apply path.
     */
    public function renew(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        if ($years < 1) {
            throw new RuntimeException('Μη έγκυρη διάρκεια ανανέωσης: ' . $years);
        }

        // The service's mandatory pre-check sync just adopted/refreshed the
        // stored id — use it and skip a redundant GET; resolve only when the
        // row genuinely lacks one.
        $id = $this->resolveDomainId($domain, $credentials, 'αδύνατη η ανανέωση');

        $this->request($credentials, 'POST', '/v1/domains/' . rawurlencode($id) . '/renew', [
            'id' => (int) $id,
            'period' => $years,
        ], charge: true);

        // The renew envelope doesn't reliably carry the new expiry — re-fetch
        // so the caller gets (and applies) the registrar's OWN post-renew
        // truth. From here on the registrar HAS charged: a re-fetch hiccup
        // must never surface as a failed renewal (the caller would log
        // 'failed' + alert for a renewal that actually succeeded).
        return $this->refetchAfterWrite($id, $credentials);
    }

    /**
     * WRITE (A3b): register at Openprovider — ensure reusable contact handles
     * (POST /v1/customers for contacts without one), then POST
     * /v1/domains. ONLY DomainRegistrationService calls this (it owns the
     * availability pre-check, the adopt-on-retry guard and the audit log).
     * autorenew is ALWAYS 'off' — the billing clock is ours, never the
     * registrar's (D-12). The ensured handles are saved on the domain.
     */
    public function register(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        if ($years < 1) {
            throw new RegistrarNotAsked('Μη έγκυρη διάρκεια καταχώρησης: ' . $years);
        }

        return $this->createDomainObject($domain, $credentials, '/v1/domains', ['period' => $years]);
    }

    /**
     * WRITE (A3c): start an INBOUND transfer — POST /v1/domains/transfer
     * with the losing registrar's auth code. Same money discipline as
     * register (gTLD transfers charge a renewal year): ONLY
     * DomainTransferService calls this. Async: the response usually carries a
     * pending status — the nightly sync drives §6.3 to completion.
     */
    public function transferIn(Domain $domain, string $authCode, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        if (trim($authCode) === '') {
            throw new RegistrarNotAsked('Ο κωδικός EPP/auth είναι κενός — απαιτείται για τη μεταφορά.');
        }

        return $this->createDomainObject($domain, $credentials, '/v1/domains/transfer', [
            'auth_code' => trim($authCode),
            'period' => 1, // gTLD transfers extend one year — never more from this path
        ]);
    }

    /**
     * READ (A3c): the EPP/auth code — the transfer-out aid. NEVER persisted
     * by callers; the audit row records only the retrieval.
     */
    public function getEppCode(Domain $domain, DomainRegistrarCredentials $credentials): ?string
    {
        $id = $this->resolveDomainId($domain, $credentials, 'αδύνατη η ανάκτηση κωδικού EPP');

        $code = $this->request($credentials, 'GET', '/v1/domains/' . rawurlencode($id) . '/authcode')->json('data.auth_code');

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * WRITE (A3d): registrar-side settings — one PUT /v1/domains/{id}
     * carrying exactly what `$changes` asks (name_servers / is_locked /
     * is_private_whois_enabled / contact handles). ONLY
     * DomainManagementService calls this. Contacts reuse ensureHandles (same
     * «persist the handle immediately» discipline as register). Re-fetches
     * the domain afterwards so the caller applies the registrar's own truth;
     * a re-fetch hiccup falls back to a minimal result (the PUT DID land —
     * the nightly sync catches the rest up).
     */
    public function updateDomain(Domain $domain, DomainChanges $changes, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        if ($changes->isEmpty()) {
            throw new RuntimeException('Καμία αλλαγή προς αποστολή στον registrar.');
        }

        $id = $this->resolveDomainId($domain, $credentials, 'αδύνατη η ενημέρωση');

        $payload = [];
        if ($changes->nameservers !== null) {
            // With its glue for a nameserver inside the domain, as register/transfer (review 3: OP answers 500 «IP is
            // not specified» for ns1.<domain> without one).
            $payload['name_servers'] = array_map(
                fn (string $h) => $this->nameserverEntry(mb_strtolower(trim($h)), $domain->fqdn),
                $changes->nameservers,
            );
        }
        if ($changes->transferLock !== null) {
            $payload['is_locked'] = $changes->transferLock;
        }
        if ($changes->whoisPrivacy !== null) {
            $payload['is_private_whois_enabled'] = $changes->whoisPrivacy;
        }
        $handles = [];
        if ($changes->applyContacts) {
            $handles = $this->ensureHandles($domain, $credentials);
            if (!isset($handles['registrant'])) {
                throw new RuntimeException("Το {$domain->fqdn} δεν έχει επαφή registrant — απαιτείται για την ενημέρωση επαφών.");
            }
            // Only the roles we hold a handle for: a role left out keeps what the registrar has (adversarial D4c A1b: the
            // registrant as a fallback rewrote an admin set at the registrar). Register/transfer still fall back.
            $payload['owner_handle'] = $handles['registrant'];
            foreach (['admin' => 'admin_handle', 'tech' => 'tech_handle', 'billing' => 'billing_handle'] as $role => $key) {
                if (isset($handles[$role])) {
                    $payload[$key] = $handles[$role];
                }
            }
        }

        $this->request($credentials, 'PUT', '/v1/domains/' . rawurlencode($id), $payload);

        $base = $this->refetchAfterWrite($id, $credentials);

        return new DomainSyncResult(
            expiresAt: $base->expiresAt,
            nameservers: $base->nameservers,
            registrarDomainId: $base->registrarDomainId,
            status: $base->status,
            rawStatus: $base->rawStatus,
            contactHandles: $handles + $base->contactHandles,
            deadRecord: $base->deadRecord,
            transferLock: $base->transferLock,
            whoisPrivacy: $base->whoisPrivacy,
            registeredAt: $base->registeredAt,
            details: $base->details,
        );
    }

    /**
     * WRITE (A3d): restore from redemption — POST /v1/domains/{id}/restore.
     * REAL (usually large) MONEY: ONLY DomainManagementService calls this (it
     * owns the sync-first adopt guard + the audit log). From the POST on the
     * registrar HAS charged — a re-fetch hiccup must never surface as a failed
     * restore; fall back to a minimal result (the nightly sync lands the rest).
     */
    public function restore(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        $id = $this->resolveDomainId($domain, $credentials, 'αδύνατη η επαναφορά');

        $this->request($credentials, 'POST', '/v1/domains/' . rawurlencode($id) . '/restore', [
            'id' => (int) $id,
        ], charge: true);

        return $this->refetchAfterWrite($id, $credentials);
    }

    /**
     * The stored OP id, else the by-name resolve (renew/EPP/update/restore all
     * share the need). Throws when neither yields one.
     */
    private function resolveDomainId(Domain $domain, DomainRegistrarCredentials $credentials, string $impossible): string
    {
        $id = $domain->registrar_domain_id !== null && trim($domain->registrar_domain_id) !== ''
            ? $domain->registrar_domain_id
            : null;
        if ($id === null) {
            $data = $this->fetchDomainData($domain, $credentials);
            $id = isset($data['id']) && (string) $data['id'] !== '' ? (string) $data['id'] : null;
        }
        if ($id === null) {
            throw new RuntimeException('Το Openprovider δεν επέστρεψε id για το ' . $domain->fqdn . ' — ' . $impossible . '.');
        }

        return $id;
    }

    /**
     * Post-write truth pull with the «the write DID land» fallback — a failed
     * GET after a successful write returns a minimal result instead of
     * throwing (renew/update/restore share it; the nightly sync catches up).
     */
    private function refetchAfterWrite(string $id, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        try {
            $fresh = $this->request($credentials, 'GET', '/v1/domains/' . rawurlencode($id))->json('data');
        } catch (Throwable) {
            return new DomainSyncResult(registrarDomainId: $id);
        }
        if (!is_array($fresh)) {
            return new DomainSyncResult(registrarDomainId: $id);
        }

        return $this->syncResultFrom($fresh);
    }

    /**
     * Shared create-shaped write (register + transfer-in): ensure handles,
     * collect valid nameservers, POST, shape the post-charge truth. From the
     * POST on the registrar HAS charged — a malformed body must never surface
     * as a failure (the nightly sync lands the rest).
     *
     * @param  array<string, mixed>  $extra  operation-specific payload fields
     */
    private function createDomainObject(Domain $domain, DomainRegistrarCredentials $credentials, string $path, array $extra): DomainSyncResult
    {
        // All before the request itself: a failure here asked nothing of the registrar (adversarial ADV-6 of #180).
        try {
            [$name, $extension] = $this->splitFqdn($domain->fqdn);

            // Nameservers are always sent, and checked before anything is created at the registrar: without them
            // Openprovider puts its own and the sites go down (live finding; WHMCS hit it too).
            $nameservers = $domain->nameservers->pluck('host')
                ->map(fn ($h) => mb_strtolower(trim((string) $h)))
                ->filter(fn ($h) => $h !== '')
                ->map(fn ($h) => $this->nameserverEntry($h, $domain->fqdn))
                ->values()
                ->all();
            if ($nameservers === []) {
                throw new RuntimeException("{$domain->fqdn} has no nameservers: they are always sent explicitly, else Openprovider sets its own.");
            }

            $handles = $this->ensureHandles($domain, $credentials);
            if (!isset($handles['registrant'])) {
                throw new RuntimeException("{$domain->fqdn} has no registrant contact: the registrar needs one.");
            }

            $payload = array_merge([
                'domain' => ['name' => $name, 'extension' => $extension],
                'owner_handle' => $handles['registrant'],
                'admin_handle' => $handles['admin'] ?? $handles['registrant'],
                'tech_handle' => $handles['tech'] ?? $handles['registrant'],
                'billing_handle' => $handles['billing'] ?? $handles['registrant'],
                'autorenew' => 'off',
                'name_servers' => $nameservers,
            ], $extra);
        } catch (RegistrarRefused|RegistrarNotAsked $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RegistrarNotAsked($e->getMessage(), 0, $e);
        }

        $data = $this->request($credentials, 'POST', $path, $payload, charge: true)->json('data');
        $base = is_array($data) ? $this->syncResultFrom($data) : new DomainSyncResult;

        return new DomainSyncResult(
            expiresAt: $base->expiresAt,
            nameservers: $base->nameservers,
            registrarDomainId: $base->registrarDomainId,
            status: $base->status,
            rawStatus: $base->rawStatus,
            contactHandles: $handles + $base->contactHandles,
            deadRecord: $base->deadRecord,
            transferLock: $base->transferLock,
            whoisPrivacy: $base->whoisPrivacy,
        );
    }

    /**
     * The domain's handle per role (D-3): a role's stored handle as is; a role whose contact (an account contact) has
     * none becomes an Openprovider «customer» (POST /v1/customers), its handle saved on the domain at once, so a
     * later failure never creates a duplicate customer at the registrar. A contact holding two roles is created once.
     * A role without a contact falls back to the registrant at the call site, never invented here.
     *
     * @return array<string, string> role → handle
     */
    private function ensureHandles(Domain $domain, DomainRegistrarCredentials $credentials): array
    {
        // Before anything is created: a role's contact is the account's own (review F2: another account's contact
        // would go to WHOIS), and has what the registrar requires.
        foreach (Domain::ROLES as $role) {
            $contact = $domain->{"{$role}Contact"};
            if ($contact !== null && trim((string) $domain->{"{$role}_handle"}) === '') {
                if ((int) $contact->client_id !== (int) $domain->client_id) {
                    throw new RuntimeException("The {$role} contact of {$domain->fqdn} belongs to another account.");
                }
                if (trim((string) $contact->country) === '') {
                    throw new RuntimeException("The {$role} contact of {$domain->fqdn} has no country.");
                }
            }
        }

        $handles = [];
        $byContact = [];
        foreach (Domain::ROLES as $role) {
            $existing = trim((string) $domain->{"{$role}_handle"});
            $contact = $domain->{"{$role}Contact"};
            if ($existing !== '') {
                // The handle of this role's current contact: Domain clears it when the contact changes (review F3).
                $handles[$role] = $existing;
                // A handle staff linked by their word (D4c) is this role's only: never lent to another role of the contact.
                if ($contact !== null && ($domain->meta['linked_handles'][$role] ?? null) !== $existing) {
                    $byContact[$contact->id] ??= $existing;
                }

                continue;
            }
            if ($contact === null) {
                continue;
            }
            if (isset($byContact[$contact->id])) {
                $handles[$role] = $byContact[$contact->id];
                $this->keepHandle($domain, $role, $handles[$role]);

                continue;
            }

            // A customer this domain already made for this contact (a write the registrar then refused): reused, not made
            // again (adversarial F5).
            $made = $this->madeHandle($domain, $contact->id);
            if ($made !== null) {
                $this->keepHandle($domain, $role, $made);
                $handles[$role] = $byContact[$contact->id] = $made;

                continue;
            }

            $payload = array_filter([
                'name' => [
                    'first_name' => (string) $contact->first_name,
                    'last_name' => (string) ($contact->last_name ?: $contact->first_name),
                ],
                'company_name' => trim((string) $contact->company_name) !== '' ? trim((string) $contact->company_name) : null,
            ], fn ($v) => $v !== null) + self::customerDetails($contact);

            $handle = $this->request($credentials, 'POST', '/v1/customers', $payload)->json('data.handle');
            if (!is_string($handle) || $handle === '') {
                throw new RuntimeException("Openprovider returned no handle for the {$role} contact of {$domain->fqdn}.");
            }
            $this->keepHandle($domain, $role, $handle);
            $this->rememberHandle($domain, $contact->id, $handle);
            $handles[$role] = $byContact[$contact->id] = $handle;
        }

        return $handles;
    }

    /**
     * A customer's details as OP takes them (its email, phone, address), for one made and for one updated (P1b-2).
     *
     * @return array<string, mixed>
     */
    private static function customerDetails(Contact $contact): array
    {
        // Best-effort split of «Οδός 12» into the street and number OP requires; OP's own validation errors (400 +
        // desc) surface verbatim.
        $street = trim((string) $contact->address1);
        $number = '';
        if (preg_match('/^(.*?)\s+(\S*\d\S*)$/u', $street, $m) === 1) {
            [, $street, $number] = $m;
        } elseif (preg_match('/^(\d\S*)\s+(.+)$/u', $street, $m) === 1) {
            [, $number, $street] = $m; // «12 Main St»
        }
        // Registrant phone is WHOIS data: digits only (and a leading +), split on real ITU country-code lengths; a
        // local number under its contact's country's code (code review 6 of #205), +30 when not known.
        $phonePayload = null;
        if (($parts = Phone::split($contact->phone, $contact->country)) !== null) {
            [$cc, $subscriber] = $parts;
            // OP rejects an empty area_code («Invalid telephone region code», live finding) and only re-joins the
            // parts: a head of up to 3 digits, never all of them.
            $len = strlen($subscriber);
            $area = substr($subscriber, 0, $len > 6 ? 3 : max(1, min(2, $len - 1)));
            $phonePayload = [
                'country_code' => $cc,
                'area_code' => $area,
                'subscriber_number' => substr($subscriber, strlen($area)),
            ];
        }

        return array_filter([
            'email' => $contact->email,
            'phone' => $phonePayload,
            'address' => array_filter([
                'street' => $street,
                'number' => $number,
                'suffix' => trim((string) $contact->address2) !== '' ? trim((string) $contact->address2) : null,
                'zipcode' => (string) $contact->postcode,
                'city' => (string) $contact->city,
                'state' => trim((string) $contact->state) !== '' ? trim((string) $contact->state) : null,
                'country' => strtoupper((string) $contact->country),
            ], fn ($v) => $v !== null),
        ], fn ($v) => $v !== null);
    }

    /**
     * A customer made at Openprovider from a contact (its name, company, email, phone and address, as a domain's), for
     * another of its products (SSL's organization handle). The caller keeps the handle.
     */
    public function customerHandle(Contact $contact, DomainRegistrarCredentials $credentials): string
    {
        if (trim((string) $contact->country) === '') {
            throw new RegistrarNotAsked('The contact has no country: Openprovider needs one.');
        }
        $payload = array_filter([
            'name' => ['first_name' => (string) $contact->first_name, 'last_name' => (string) ($contact->last_name ?: $contact->first_name)],
            'company_name' => trim((string) $contact->company_name) !== '' ? trim((string) $contact->company_name) : null,
        ], fn ($v) => $v !== null) + self::customerDetails($contact);
        $handle = $this->request($credentials, 'POST', '/v1/customers', $payload)->json('data.handle');
        if (!is_string($handle) || $handle === '') {
            throw new RuntimeException('Openprovider returned no handle for the contact.');
        }

        return $handle;
    }

    /**
     * A call to Openprovider's SSL API (`/v1/ssl/…` only), through request(): its token, a 4xx as RegistrarRefused
     * (nothing done), a 5xx or an error code as not known; a charge (an order, a renewal) answered without its envelope
     * is not known either.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> its `data`
     */
    public function sslCall(DomainRegistrarCredentials $credentials, string $method, string $path, array $payload = [], bool $charge = false): array
    {
        // Its SSL API only, and no way out of it (ADV-7 of #345: «/v1/ssl/../customers»).
        if (preg_match('#^/v1/ssl/[a-z0-9_-]+(?:/[a-z0-9_-]+)*(?:\?[^/]*)?$#i', $path) !== 1 || str_contains($path, '..')) {
            throw new RegistrarNotAsked('Only Openprovider\'s SSL API is called this way.');
        }
        $data = $this->request($credentials, $method, $path, $payload, null, $charge)->json('data');

        return is_array($data) ? $data : [];
    }

    /** P1b-2: the customer's email, phone and address as the contact's (its name never: OP keeps a handle's owner). */
    public function updateContact(string $handle, Contact $contact, DomainRegistrarCredentials $credentials): void
    {
        $this->request($credentials, 'PUT', '/v1/customers/' . rawurlencode($handle), self::customerDetails($contact));
    }

    /** The customer this domain made at OP for a contact, if any (`meta.contact_handles`, contact id => handle). */
    private function madeHandle(Domain $domain, int $contactId): ?string
    {
        $meta = json_decode((string) Domain::query()->toBase()->where('id', $domain->getKey())->value('meta'), true);
        $handle = is_array($meta) ? ($meta['contact_handles'][$contactId] ?? null) : null;

        return is_string($handle) && $handle !== '' ? $handle : null;
    }

    /** Kept at once, outside the caller's work and its undo, as keepHandle. */
    private function rememberHandle(Domain $domain, int $contactId, string $handle): void
    {
        $meta = json_decode((string) Domain::query()->toBase()->where('id', $domain->getKey())->value('meta'), true);
        $meta = is_array($meta) ? $meta : [];
        $meta['contact_handles'][$contactId] = $handle;
        Domain::query()->whereKey($domain->getKey())->update(['meta' => json_encode($meta)]);
        $domain->forceFill(['meta' => $meta])->syncOriginalAttribute('meta');
    }

    /**
     * Save one role's handle at once and only it (review 4): not the caller's other unsaved changes, no model events;
     * kept even if the caller later rolls back nothing, since it is a single statement outside the caller's work.
     */
    private function keepHandle(Domain $domain, string $role, string $handle): void
    {
        Domain::query()->whereKey($domain->getKey())->update(["{$role}_handle" => $handle]);
        $domain->forceFill(["{$role}_handle" => $handle])->syncOriginalAttribute("{$role}_handle");
    }

    /** One OP domain payload → our truth DTO (sync + post-renew share it). */
    private function syncResultFrom(array $data): DomainSyncResult
    {
        $rawStatus = isset($data['status']) ? (string) $data['status'] : null;

        $nameservers = [];
        foreach ((array) ($data['name_servers'] ?? []) as $ns) {
            $host = is_array($ns) ? ($ns['name'] ?? null) : (is_string($ns) ? $ns : null);
            if (is_string($host) && $host !== '') {
                $nameservers[] = mb_strtolower($host);
            }
        }

        // An expired domain is DEL at OP while still in its soft or hard quarantine (D5a): ours to renew or restore, never
        // «deleted» while one of them runs (Amsterdam's day, as OP keeps them).
        // Only on a DEL record (review 5: never an old pair kept on a restored, active one).
        $graceEnds = $rawStatus === 'DEL' ? $this->datePart($data['soft_quarantine_expiry_date'] ?? null) : null;
        $redemptionEnds = $rawStatus === 'DEL' ? $this->datePart($data['hard_quarantine_expiry_date'] ?? null) : null;
        $today = Carbon::now('Europe/Amsterdam')->toDateString();
        $status = $this->mapStatus($rawStatus);
        $quarantined = false;
        if ($rawStatus === 'DEL' && $graceEnds !== null && $graceEnds >= $today) {
            [$status, $quarantined] = [DomainStatus::Grace, true];
        } elseif ($rawStatus === 'DEL' && $redemptionEnds !== null && $redemptionEnds >= $today) {
            [$status, $quarantined] = [DomainStatus::Redemption, true];
        }

        return new DomainSyncResult(
            // A pending order carries a placeholder expiry (today + 1 year): never ours until it is active (live
            // finding, nixpal.com).
            expiresAt: in_array($rawStatus, self::PENDING_STATUSES, true) ? null : $this->datePart($data['expiration_date'] ?? null),
            nameservers: $nameservers,
            registrarDomainId: isset($data['id']) ? (string) $data['id'] : null,
            status: $status,
            rawStatus: $rawStatus,
            contactHandles: $this->extractHandles($data),
            // DEL = deleted, FAI = failed request — tombstones, not ownership; a DEL in quarantine is still ours (D5a).
            deadRecord: in_array($rawStatus, self::DEAD_STATUSES, true) && !$quarantined,
            // Only an EXPLICIT boolean counts — an absent key must never
            // overwrite the local mirror with a guessed false.
            transferLock: is_bool($data['is_locked'] ?? null) ? $data['is_locked'] : null,
            whoisPrivacy: is_bool($data['is_private_whois_enabled'] ?? null) ? $data['is_private_whois_enabled'] : null,
            registeredAt: $this->datePart($data['creation_date'] ?? null),
            details: $this->detailsFrom($data),
            graceEndsOn: $graceEnds,
            redemptionEndsOn: $redemptionEnds,
        );
    }

    /**
     * What staff see of the registrar's record (D4b), from an allow-list: never the auth codes or anything else that
     * is not on it. Scalars only; the nameservers' glue as «host ip».
     *
     * @param  array<string, mixed>  $data
     * @return array<string, scalar|null>
     */
    private function detailsFrom(array $data): array
    {
        $details = [];
        foreach (self::DETAIL_KEYS as $key) {
            if (array_key_exists($key, $data) && (is_scalar($data[$key]) || $data[$key] === null)) {
                $value = $data[$key];
                // OP's «no date» is a zero date.
                $details[$key] = is_string($value) && str_starts_with($value, '0000-00-00') ? null : $value;
            }
        }
        $glue = [];
        foreach ((array) ($data['name_servers'] ?? []) as $ns) {
            if (is_array($ns) && is_string($ns['name'] ?? null) && is_string($ns['ip'] ?? null) && $ns['ip'] !== '') {
                $glue[] = mb_strtolower($ns['name']) . ' ' . $ns['ip'];
            }
        }
        if ($glue !== []) {
            $details['glue'] = implode(', ', $glue);
        }

        return $details;
    }

    /**
     * Registrar COST per operation for one TLD (A2c cost-sync) — a plain GET,
     * still read-only by construction. We read ONLY the `reseller` price block
     * (what OUR account is charged, in its own currency) — the registry-facing
     * `product` block is a DIFFERENT kind of price and must never be recorded
     * as our cost. An operation without a reseller quote is absent from the
     * result — never guessed as 0.00, never substituted.
     */
    public function getTldPricing(string $tld, DomainRegistrarCredentials $credentials): TldPricing
    {
        $tld = mb_strtolower(ltrim(trim($tld), '.'));
        if ($tld === '') {
            throw new RuntimeException('Κενό TLD για άντληση τιμών.');
        }

        // Its «invalid extension» comes as an HTTP 500: read it before the 5xx becomes a failure.
        return $this->pricingFrom($tld, $this->tldData($tld, $this->request($credentials, 'GET', self::tldPath($tld), tolerateStatus: 500)));
    }

    public function getTldPricingMany(array $tlds, DomainRegistrarCredentials $credentials): array
    {
        $paths = [];
        foreach ($tlds as $tld) {
            $tld = mb_strtolower(ltrim(trim($tld), '.'));
            if ($tld !== '') {
                $paths[$tld] = self::tldPath($tld);
            }
        }
        $responses = $this->pooled($credentials, $paths);
        $out = [];
        foreach (array_keys($paths) as $tld) { // as asked, not as answered
            $response = $responses[$tld] ?? new RuntimeException('Το Openprovider δεν απάντησε για το .' . $tld . '.');
            try {
                if (!$response instanceof Response) {
                    throw $response;
                }
                $out[$tld] = $this->pricingFrom($tld, $this->tldData($tld, $response));
            } catch (TldNotOffered) {
                // not sold there: left out, as the interface says
            } catch (Throwable $e) {
                $out[$tld] = $e->getMessage();
            }
        }

        return $out;
    }

    public function listTldPricing(DomainRegistrarCredentials $credentials): array
    {
        $first = $this->request($credentials, 'GET', self::listPath(0));
        $total = $first->json('data.total');
        if (!is_int($total) || $total < 0 || !is_array($first->json('data.results'))) {
            throw new RuntimeException('Το Openprovider δεν επέστρεψε τη λίστα των TLDs.');
        }
        if ($total > self::TLD_PAGE * self::MAX_TLD_PAGES) {
            throw new RuntimeException('Το Openprovider δίνει ' . $total . ' TLDs: περισσότερα απ\' όσα διαβάζουμε.');
        }
        $pages = [$first];
        $offsets = range(self::TLD_PAGE, max(self::TLD_PAGE, min($total, self::TLD_PAGE * self::MAX_TLD_PAGES) - 1), self::TLD_PAGE);
        $rest = [];
        foreach ($total > self::TLD_PAGE ? $offsets : [] as $offset) {
            $rest['page' . $offset] = self::listPath($offset); // a string key: a numeric one would become an int
        }
        $responses = $this->pooled($credentials, $rest);
        foreach (array_keys($rest) as $offset) {
            $response = $responses[$offset] ?? new RuntimeException('Το Openprovider δεν απάντησε για μια σελίδα των TLDs.');
            if (!$response instanceof Response) {
                throw $response;
            }
            $pages[] = $this->checked($response);
        }

        $out = [];
        $seen = 0;
        foreach ($pages as $page) {
            $results = $page->json('data.results');
            foreach (is_array($results) ? $results : [] as $result) {
                $seen++;
                try { // in one form with the TLDs typed (an IDN as xn--…: review of #422)
                    $name = is_array($result) && is_string($result['name'] ?? null) ? Domain::normalise(ltrim(trim($result['name']), '.')) : '';
                } catch (InvalidArgumentException) {
                    $name = '';
                }
                // Only what it sells now; a garbled entry is skipped, never guessed.
                if ($name === '' || ($result['status'] ?? 'ACT') !== 'ACT' || !is_array($result['prices'] ?? null)) {
                    continue;
                }
                $out[$name] = $this->pricingFrom($name, $result);
            }
        }
        // All or nothing: a page shorter than asked (a lower cap on its side) would hide TLDs without a word.
        if ($seen !== $total) {
            throw new RuntimeException('Το Openprovider έδωσε ' . $seen . ' από τα ' . $total . ' TLDs της λίστας του.');
        }
        if (count($out) === 0 && $total > 0) {
            throw new RuntimeException('Το Openprovider δεν επέστρεψε τιμές στη λίστα των TLDs.');
        }
        ksort($out);

        return $out;
    }

    private static function tldPath(string $tld): string
    {
        return '/v1/tlds/' . rawurlencode($tld) . '?with_price=true';
    }

    private static function listPath(int $offset): string
    {
        return '/v1/tlds?with_price=true&limit=' . self::TLD_PAGE . '&offset=' . $offset;
    }

    /**
     * One TLD's answer: its data, or TldNotOffered where it does not sell the TLD (code 307 in an HTTP 500), or the failure.
     *
     * @return array<string, mixed>
     */
    private function tldData(string $tld, Response $response): array
    {
        $code = $response->json('code');
        if (is_numeric($code) && (int) $code === self::INVALID_EXTENSION) {
            throw new TldNotOffered(__('Openprovider does not offer .:tld.', ['tld' => $tld]));
        }
        $data = $this->checked($response)->json('data');
        if (!is_array($data) || !is_array($data['prices'] ?? null)) {
            throw new RuntimeException('Το Openprovider δεν επέστρεψε τιμές για το .' . $tld . '.');
        }

        return $data;
    }

    /**
     * One TLD's costs from its data (the single answer and the list's entries have the same shape).
     *
     * @param  array<string, mixed>  $data
     */
    private function pricingFrom(string $tld, array $data): TldPricing
    {
        $prices = (array) $data['prices'];

        $costs = [];
        foreach (self::PRICE_KEYS as $opKey => $operation) {
            $entry = $this->costEntry($prices[$opKey] ?? null);
            if ($entry !== null) {
                $costs[$operation] = $entry;
            }
        }

        // The stages after expiry (D5a, Openprovider's TLD pages): soft quarantine (our grace) and hard quarantine (our
        // redemption), in days. The restore fee belongs to the stage that has one: the hard quarantine if the TLD has it
        // (.eu, .io, .ch); else the soft one (.com: 40 days, «Restore + Renew charges», no hard quarantine). The soft
        // quarantine's own restore price applies to the grace of a TLD with both.
        $graceDays = $this->days($data['soft_quarantine_period'] ?? null);
        $redemptionDays = $this->days($data['quarantine_period'] ?? null);
        $restore = $costs['redemption'] ?? null;
        if ($graceDays !== null && $redemptionDays !== null) {
            unset($costs['redemption']);
            $softRestore = $this->costEntry($prices['soft_restore_price'] ?? null);
            if ($redemptionDays > 0) {
                if ($restore !== null) {
                    $costs['redemption'] = $restore;
                }
                if ($graceDays > 0 && $softRestore !== null) {
                    $costs['grace'] = $softRestore;
                }
            } elseif ($graceDays > 0 && $restore !== null) {
                $costs['grace'] = $restore;
            }
        }

        return new TldPricing(tld: $tld, costs: $costs, graceDays: $graceDays, redemptionDays: $redemptionDays);
    }

    /** A whole number of days from OP (0 included), or null: a negative or garbled one is not reported (adversarial F4). */
    private function days(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);
    }

    /**
     * One reseller price block as a cost, or null: a non-negative amount (a negative would become a negative price: review
     * F1) with its currency (never guessed), to two decimals in decimal arithmetic, half up as the registrar bills.
     *
     * @return array{cost: string, currency: string}|null
     */
    private function costEntry(mixed $block): ?array
    {
        $entry = is_array($block) ? ($block['reseller'] ?? null) : null;
        // A number or a numeric string: a boolean is no price (true would read as «1»: adversarial F3 of #422).
        $price = is_array($entry) ? ($entry['price'] ?? null) : null;
        if (!is_array($entry) || !(is_int($price) || is_float($price) || is_string($price)) || !TldPriceMath::isAmount((string) $price)
            || !is_string($entry['currency'] ?? null) || trim($entry['currency']) === '') {
            return null;
        }

        return ['cost' => bcadd((string) $entry['price'], '0.005', 2), 'currency' => strtoupper(trim($entry['currency']))];
    }

    /**
     * One page of the account's own portfolio (A2c registrar-first import) —
     * a plain GET, read-only by construction. Records the API can't shape into
     * (sld, tld) are skipped, never guessed.
     */
    public function listDomains(DomainRegistrarCredentials $credentials, int $offset, int $limit): RegistrarDomainPage
    {
        $data = $this->request(
            $credentials,
            'GET',
            '/v1/domains?limit=' . max(1, $limit) . '&offset=' . max(0, $offset)
        )->json('data');

        $results = is_array($data) && is_array($data['results'] ?? null) ? $data['results'] : [];
        $records = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            $record = $this->mapDomainRecord($result);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        $total = isset($data['total']) && is_numeric($data['total']) ? (int) $data['total'] : null;

        return new RegistrarDomainPage(records: $records, total: $total, rawCount: count($results));
    }

    /**
     * Resolve a reusable contact handle (Openprovider «customer», e.g.
     * AB123456-XX). Null on a 404/unknown handle; other failures throw.
     */
    public function getContact(string $handle, DomainRegistrarCredentials $credentials): ?RegistrarContact
    {
        $response = $this->request($credentials, 'GET', '/v1/customers/' . rawurlencode($handle), tolerateStatus: 404);
        if ($response->status() === 404) {
            return null; // unknown/foreign handle — not an error, just no assign-aid
        }

        $data = $response->json('data');
        if (!is_array($data)) {
            return null;
        }

        $first = is_array($data['name'] ?? null) ? trim((string) ($data['name']['first_name'] ?? '')) : '';
        $last = is_array($data['name'] ?? null) ? trim((string) ($data['name']['last_name'] ?? '')) : '';
        $person = trim($first . ' ' . $last);
        $org = trim((string) ($data['company_name'] ?? ''));
        $name = $person !== '' ? $person : $org;
        if ($name === '') {
            $name = $handle; // never an empty NOT-NULL column — the handle still identifies it
        }

        $phone = null;
        if (is_array($data['phone'] ?? null)) {
            $phone = trim(implode('', [
                (string) ($data['phone']['country_code'] ?? ''),
                (string) ($data['phone']['area_code'] ?? ''),
                (string) ($data['phone']['subscriber_number'] ?? ''),
            ]));
            $phone = $phone !== '' ? $phone : null;
        }

        $address = is_array($data['address'] ?? null) ? $data['address'] : [];
        $street = trim(((string) ($address['street'] ?? '')) . ' ' . ((string) ($address['number'] ?? '')));
        $country = strtoupper(trim((string) ($address['country'] ?? '')));

        return new RegistrarContact(
            handle: $handle,
            name: $name,
            org: $org !== '' ? $org : null,
            email: is_string($data['email'] ?? null) && $data['email'] !== '' ? $data['email'] : null,
            phone: $phone,
            address1: $street !== '' ? $street : null,
            address2: null,
            city: is_string($address['city'] ?? null) && $address['city'] !== '' ? $address['city'] : null,
            postcode: is_string($address['zipcode'] ?? null) && $address['zipcode'] !== '' ? $address['zipcode'] : null,
            country: strlen($country) === 2 ? $country : null,
        );
    }

    /** Openprovider list/detail result → import record; null = unusable row. */
    private function mapDomainRecord(array $result): ?RegistrarDomainRecord
    {
        $domain = is_array($result['domain'] ?? null) ? $result['domain'] : [];
        $sld = mb_strtolower(trim((string) ($domain['name'] ?? '')));
        $tld = mb_strtolower(ltrim(trim((string) ($domain['extension'] ?? '')), '.'));
        if ($sld === '' || $tld === '') {
            return null;
        }

        $rawStatus = isset($result['status']) ? (string) $result['status'] : null;

        $nameservers = [];
        foreach ((array) ($result['name_servers'] ?? []) as $ns) {
            $host = is_array($ns) ? ($ns['name'] ?? null) : (is_string($ns) ? $ns : null);
            if (is_string($host) && $host !== '') {
                $nameservers[] = mb_strtolower($host);
            }
        }

        $handles = $this->extractHandles($result);

        // OP autorenew is 'on'/'off'/'default' — only the explicit values map.
        $autoRenew = match ($result['autorenew'] ?? null) {
            'on' => true,
            'off' => false,
            default => null,
        };

        return new RegistrarDomainRecord(
            sld: $sld,
            tld: $tld,
            expiresAt: $this->datePart($result['expiration_date'] ?? null),
            registeredAt: $this->datePart($result['creation_date'] ?? null),
            registrarDomainId: isset($result['id']) ? (string) $result['id'] : null,
            status: $this->mapStatus($rawStatus),
            rawStatus: $rawStatus,
            nameservers: $nameservers,
            autoRenew: $autoRenew,
            contactHandles: $handles,
            transferLock: is_bool($result['is_locked'] ?? null) ? $result['is_locked'] : null,
            whoisPrivacy: is_bool($result['is_private_whois_enabled'] ?? null) ? $result['is_private_whois_enabled'] : null,
        );
    }

    /**
     * The reusable contact handles a domain record carries — shared by the
     * list mapper AND syncDomain (same payload shape both ways).
     *
     * @return array<string, string> contact type → handle
     */
    private function extractHandles(array $result): array
    {
        $handles = [];
        foreach (['owner_handle' => 'registrant', 'admin_handle' => 'admin', 'tech_handle' => 'tech', 'billing_handle' => 'billing'] as $key => $type) {
            if (is_string($result[$key] ?? null) && trim($result[$key]) !== '') {
                $handles[$type] = trim($result[$key]);
            }
        }

        return $handles;
    }

    /**
     * Resolves a host to its IPv4 (null when it does not resolve). Replaceable in tests:
     * no live DNS in CI.
     *
     * @var (\Closure(string): ?string)|null
     */
    public static ?\Closure $glueResolver = null;

    /**
     * One name_servers entry. A host inside the domain itself (ns1.example.com for
     * example.com) needs its glue IP, else OP answers 500 «IP is not specified» (live
     * finding, nixpal.com). The IP comes from current DNS, so the delegation stays as is.
     *
     * @return array{name: string, ip?: string}
     */
    private function nameserverEntry(string $host, string $fqdn): array
    {
        if (!str_ends_with($host, '.' . mb_strtolower($fqdn))) {
            return ['name' => $host];
        }
        $resolver = self::$glueResolver ?? function (string $h): ?string {
            $ip = gethostbyname($h);

            return $ip !== $h && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
        };
        $ip = $resolver($host);
        if ($ip === null) {
            throw new RuntimeException("Ο nameserver {$host} είναι μέσα στο {$fqdn} και χρειάζεται IP (glue), αλλά δεν επιλύεται.");
        }

        return ['name' => $host, 'ip' => $ip];
    }

    /** OP returns "YYYY-MM-DD HH:MM:SS" — the date part is our clock. */
    private function datePart(mixed $value): ?string
    {
        // OP's «no date» is a zero date (adversarial F3: it became year -1 and broke the sync).
        // A real date only (adversarial D5a F5: a garbled one kept a gone domain «in quarantine»).
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return substr($value, 0, 10);
    }

    /** @return array<string, mixed> */
    private function fetchDomainData(Domain $domain, DomainRegistrarCredentials $credentials): array
    {
        // Authenticate ONCE up front: a login rejection must throw here (bad
        // creds = one failed POST per run, not one per fallback branch — OP
        // rate-limits repeated auth failures) and never be eaten by the
        // stale-id catch below, which is for domain-fetch errors only.
        $this->token($credentials);

        $tombstone = null;
        if ($domain->registrar_domain_id !== null && $domain->registrar_domain_id !== '') {
            try {
                $data = $this->request($credentials, 'GET', '/v1/domains/' . rawurlencode($domain->registrar_domain_id))->json('data');
                // Only this domain's record: an id now pointing at another name is not ours (review F1).
                // A deleted one (DEL past its quarantine) is looked for again by name: lapsed and bought again for the same
                // owner, the stored id is its tombstone and the new registration would be ended here (sweep G1). Anything
                // else is its record: live, a DEL still in its quarantine (ours to restore: ADV-4 of #308), a failed
                // transfer (its outcome: ADV-5 of #308).
                if (is_array($data) && $this->isRecordOf($data, $domain->fqdn)) {
                    if (!$this->syncResultFrom($data)->deadRecord || ($data['status'] ?? null) !== 'DEL') {
                        return $data;
                    }
                    $tombstone = $data;
                }
            } catch (RuntimeException) {
                // Stale/wrong stored id (object re-created under a new id, or a
                // typo) — fall through to the by-name resolve instead of failing
                // the domain's sync forever. The by-name result carries the new
                // id and DomainSyncService adopts it (no repeat next run).
            }
        }

        [$name, $extension] = $this->splitFqdn($domain->fqdn);
        try {
            $results = $this->request($credentials, 'GET', '/v1/domains?full_name=' . rawurlencode($name . '.' . $extension))
                ->json('data.results');
        } catch (RuntimeException $e) {
            if ($tombstone !== null) {
                return $tombstone; // read by its id; the search not answering is no reason to read less
            }
            throw $e;
        }
        // Prefer a LIVE record: after a lapse-and-rebuy the account can hold
        // BOTH the lingering DEL tombstone AND the fresh ACT record for the
        // same fqdn (id-ascending puts the tombstone first) — picking the
        // tombstone would disown a registration that was just paid for.
        // Only records of this very name: a search may answer with others (review F1: another domain's id, expiry and
        // nameservers were written onto ours, and a renewal would have charged for it).
        if (!is_array($results) && $tombstone !== null) {
            return $tombstone;
        }
        if (!is_array($results)) {
            // Not its search's answer (a maintenance page, a proxy, a changed API): could not read, never «not ours»
            // (ADV-4 of #203).
            throw new RuntimeException("Openprovider's search for {$domain->fqdn} did not answer with results: could not read.");
        }
        $candidates = array_values(array_filter($results, fn ($r) => is_array($r) && $this->isRecordOf($r, $domain->fqdn)));
        $first = null;
        foreach ($candidates as $candidate) {
            if (!in_array((string) ($candidate['status'] ?? ''), self::DEAD_STATUSES, true)) {
                $first = $candidate;
                break;
            }
        }
        if ($tombstone !== null) {
            // Only a live record of the same owner (its owner handle the domain's registrant here) replaces the tombstone:
            // one registered for anyone else is never ours (code review 1, ADV-3 of #308), shown to staff instead.
            $owner = (string) $domain->registrant_handle;
            $same = $first !== null && $owner !== '' && (string) ($first['owner_handle'] ?? '') === $owner;
            if (!$same && $first !== null) {
                $tombstone['other_live_record'] = (string) ($first['id'] ?? '?');
            }
            $first = $same ? $first : $tombstone;
        }
        $first ??= $candidates[0] ?? null;
        if (!is_array($first)) {
            // TYPED: «not in the account» must be distinguishable from a
            // transport failure — the register adopt-guard acts on the
            // difference (third party vs could-not-read).
            throw new DomainNotFoundAtRegistrar("{$domain->fqdn} is not in the Openprovider account.");
        }

        return $first;
    }

    /** The record is of this name (its domain.name + extension, lowercase). */
    private function isRecordOf(array $record, string $fqdn): bool
    {
        $name = is_array($record['domain'] ?? null) ? $record['domain'] : [];
        $full = mb_strtolower(trim((string) ($name['name'] ?? '')) . '.' . ltrim(trim((string) ($name['extension'] ?? '')), '.'));

        return $full === mb_strtolower($fqdn);
    }

    /**
     * Openprovider status → our lifecycle, CONFIDENT mappings only — anything
     * else returns null (keep the local status, surface the raw value).
     */
    private function mapStatus(?string $raw): ?DomainStatus
    {
        return match ($raw) {
            'ACT' => DomainStatus::Active,
            'DEL' => DomainStatus::Deleted,
            default => null,
        };
    }

    // ── plumbing ───────────────────────────────────────────────────────────

    /**
     * Authenticated request with ONE re-login retry on 401 (the cached bearer
     * expired server-side). Non-2xx → RuntimeException carrying Openprovider's
     * own error description (their envelope: code/desc). `tolerateStatus`
     * returns that one failing status to the caller instead of throwing
     * (e.g. 404 = unknown contact handle, a legitimate answer).
     */
    private function request(DomainRegistrarCredentials $credentials, string $method, string $path, array $payload = [], ?int $tolerateStatus = null, bool $charge = false): Response
    {
        $response = $this->send($credentials, $this->token($credentials), $method, $path, $payload);

        if ($response->status() === 401) {
            Cache::forget($this->tokenCacheKey($credentials));
            $response = $this->send($credentials, $this->token($credentials), $method, $path, $payload);
        }

        if ($tolerateStatus !== null && $response->status() === $tolerateStatus) {
            return $response;
        }

        return $this->checked($response, $charge);
    }

    /** A response as request() judges it: a failure or an error in its envelope throws (below). */
    private function checked(Response $response, bool $charge = false): Response
    {
        if ($response->failed()) {
            $desc = $response->json('desc');

            // Its own description only: a raw body may echo contact data (review 11).
            $message = 'Openprovider error ' . $response->status() . ': ' . (is_scalar($desc) && $desc !== '' ? mb_substr((string) $desc, 0, 300) : 'no description');

            // A 4xx is a refusal: nothing done. A 5xx may have been done (D5b-2 code review 1).
            throw $response->clientError() ? new RegistrarRefused($message, $response->status()) : new RuntimeException($message);
        }

        // Its envelope: a code other than 0 is an error, whatever the HTTP status (its API docs, 2026-10-19). Answered with
        // an error: not known to be undone, so maybe done, never «refused»: RegistrarOutcomeUnknown (review 1 of #211), so
        // a management write re-reads instead of undoing; the money flows take it as pending, for staff (D-17).
        $code = $response->json('code');
        // A charge (register, transfer, renew, restore) answered 2xx without its envelope (a maintenance page, an empty
        // body: ADV-1 of #211) is no proof it was done: maybe done, as above. A read is checked by its callers.
        if ($charge && !is_numeric($code)) {
            throw new RegistrarOutcomeUnknown('Openprovider answered ' . $response->status() . ' without its envelope: whether it was done is not known.');
        }
        if (is_numeric($code) && (int) $code !== 0) {
            $desc = $response->json('desc');

            throw new RegistrarOutcomeUnknown('Openprovider error code ' . (int) $code . ': ' . (is_scalar($desc) && $desc !== '' ? mb_substr((string) $desc, 0, 300) : 'no description'));
        }

        return $response;
    }

    /** DNSSEC (G8-4b): DNSKEYs (its API's dnssec_keys: flags, alg, protocol, pub_key); it makes the DS itself. */
    public function dnssecKind(): string
    {
        return DnssecRecord::DNSKEY;
    }

    /** Its DNSKEYs as Openprovider has them (GET domain: dnssec_keys, when signed). A read. */
    public function dnssecRecords(Domain $domain, DomainRegistrarCredentials $credentials): array
    {
        $keys = $this->fetchDomainData($domain, $credentials)['dnssec_keys'] ?? [];

        return array_values(array_map(fn (array $key) => DnssecRecord::dnskey((int) ($key['flags'] ?? 0), (int) ($key['alg'] ?? 0),
            (string) ($key['pub_key'] ?? ''), (int) ($key['protocol'] ?? 3)), array_filter(is_array($keys) ? $keys : [], 'is_array')));
    }

    /**
     * WRITE (DNSSEC): its DNSKEYs set to these, the full set (PUT dnssec_keys; none: unsigned), then read back: what
     * Openprovider kept is what counts (an empty set's removal was not tried live). Its keys checked before anything is
     * sent (protocol 3, RFC 4034; flags 256/257; a base64 key: ADV-4 of #210), and compared with what staff saw (ADV-1).
     * Answered and then not read back, or read back otherwise: maybe done, never «refused» (review 3).
     */
    public function setDnssec(Domain $domain, array $records, DomainRegistrarCredentials $credentials, ?array $expected = null): ?string
    {
        foreach ($records as $record) {
            if ($record->kind !== DnssecRecord::DNSKEY) {
                throw new RegistrarNotAsked('Openprovider takes DNSKEYs (flags, algorithm, a public key), not DS records.');
            }
            if ($record->protocol !== 3 || !in_array($record->flags, [256, 257], true) || $record->algorithm < 1 || $record->algorithm > 255) {
                throw new RegistrarNotAsked('A DNSKEY is protocol 3, flags 256 or 257, an algorithm 1 to 255.');
            }
            if ($record->publicKey === null || preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/', $record->publicKey) !== 1) {
                throw new RegistrarNotAsked('A DNSKEY\'s public key is base64.');
            }
        }
        // Before the PUT: whatever fails asked nothing (ADV-3 of #223), never «maybe done».
        try {
            $now = $expected !== null ? $this->dnssecRecords($domain, $credentials) : null;
            $id = $this->resolveDomainId($domain, $credentials, 'αδύνατη η ενημέρωση');
        } catch (RegistrarRefused|RegistrarNotAsked|DomainRegistrarNotConfigured $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RegistrarNotAsked('Openprovider could not be read before the change: nothing sent (' . $e->getMessage() . ').', 0, $e);
        }
        if ($now !== null && !DnssecRecord::sameSet($expected, $now)) {
            throw new DnssecChangedSinceRead("{$domain->fqdn}'s DNSKEYs changed at Openprovider since they were read: read them again.");
        }
        // The PUT: a 5xx or a lost connection may have landed (ADV-2 of #223): maybe done; a 4xx refused, nothing done.
        try {
            $this->request($credentials, 'PUT', '/v1/domains/' . rawurlencode($id), ['dnssec_keys' => array_map(fn (DnssecRecord $record) => [
                'flags' => $record->flags, 'alg' => $record->algorithm, 'protocol' => $record->protocol, 'pub_key' => $record->publicKey,
            ], $records)]);
        } catch (RegistrarRefused|RegistrarOutcomeUnknown $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RegistrarOutcomeUnknown('Openprovider did not answer the change clearly: it may have been done (' . $e->getMessage() . ').', 0, $e);
        }

        try {
            $kept = $this->dnssecRecords($domain, $credentials);
        } catch (Throwable $e) {
            throw new RegistrarOutcomeUnknown('Openprovider answered, but its keys could not be read back: read DNSSEC again (' . $e->getMessage() . ').', 0, $e);
        }
        if (!DnssecRecord::sameSet($records, $kept)) {
            throw new RegistrarOutcomeUnknown('Openprovider answered, but now has ' . count($kept) . ' DNSKEY(s), not the ' . count($records)
                . ' sent: check the domain in its panel.');
        }

        return null;
    }

    /**
     * The reseller account's balance (G8-1): GET /v1/resellers answers our own reseller (verified live 2026-10-19;
     * /resellers/me is 501), with what it holds back for orders under way. No currency in it: the account's (EUR).
     */
    public function accountBalance(DomainRegistrarCredentials $credentials): RegistrarBalance
    {
        $data = $this->request($credentials, 'GET', '/v1/resellers')->json('data');
        $balance = is_array($data) ? $data['balance'] ?? null : null;
        if (!is_int($balance) && !is_float($balance) && !(is_string($balance) && is_numeric($balance))) {
            throw new RuntimeException('Openprovider gave no balance for the reseller account.');
        }
        $reserved = $data['reserved_balance'] ?? null;

        return new RegistrarBalance(amount: self::cents($balance), currency: 'EUR',
            reserved: is_int($reserved) || is_float($reserved) || (is_string($reserved) && is_numeric($reserved)) ? self::cents($reserved) : null);
    }

    /** JSON's number (cents at most) as a decimal string: rounded to the cent, never carried on as a float. */
    private static function cents(int|float|string $amount): string
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }

    /**
     * Several GETs in parallel, POOL_SIZE at a time, with one re-login on a 401 as request(): key => its Response, or the
     * Throwable that stopped it (no answer). Reads only: never a charge.
     *
     * @param  array<string, string>  $paths  key => path
     * @return array<string, Response|Throwable>
     */
    private function pooled(DomainRegistrarCredentials $credentials, array $paths): array
    {
        $send = function (array $paths) use ($credentials): array {
            $token = $this->token($credentials);
            $base = $this->baseUrl($credentials);

            return Http::pool(fn (Pool $pool) => array_map(
                fn (string $key) => $pool->as($key)->withToken($token)->acceptJson()
                    ->timeout($this->timeout)->connectTimeout(min(10, $this->timeout))->get($base . $paths[$key]),
                array_keys($paths),
            ), self::POOL_SIZE);
        };
        $out = $paths === [] ? [] : $send($paths);
        $expired = array_filter($out, fn ($r) => $r instanceof Response && $r->status() === 401);
        if ($expired !== []) {
            Cache::forget($this->tokenCacheKey($credentials));
            $out = array_replace($out, $send(array_intersect_key($paths, $expired)));
        }

        return $out;
    }

    private function send(DomainRegistrarCredentials $credentials, string $token, string $method, string $path, array $payload): Response
    {
        return Http::withToken($token)
            ->acceptJson()
            ->timeout($this->timeout)->connectTimeout(min(10, $this->timeout))
            ->send($method, $this->baseUrl($credentials) . $path, $payload === [] ? [] : ['json' => $payload]);
    }

    /**
     * Cached bearer (6h) — Openprovider tokens live ~24h; 401 mid-flight
     * re-logins. Cached ENCRYPTED: with the DB cache driver a plaintext entry
     * would put a live full-access bearer into the `cache` table (and every DB
     * dump), while the credentials themselves are encrypted at rest.
     */
    private function token(DomainRegistrarCredentials $credentials): string
    {
        // Credentials first: a cached token is never a substitute for them (review F1b).
        $this->requireCredentials($credentials);
        $key = $this->tokenCacheKey($credentials);
        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            try {
                return Crypt::decryptString($cached);
            } catch (DecryptException) {
                Cache::forget($key); // stale/foreign ciphertext → re-login
            }
        }

        $token = $this->freshToken($credentials); // throws with OP's own message
        if ($token === null) {
            throw new RuntimeException('Αποτυχία σύνδεσης στο Openprovider (login δεν επέστρεψε token).');
        }
        Cache::put($key, Crypt::encryptString($token), self::TOKEN_TTL_SECONDS);

        return $token;
    }

    /**
     * Null only on a 2xx login without a token; a REJECTED login throws with
     * Openprovider's own description, so «λάθος credentials» is distinguishable
     * from a registrar outage in sync_error / the operator's notification.
     */
    private function freshToken(DomainRegistrarCredentials $credentials): ?string
    {
        $this->requireCredentials($credentials);

        $response = Http::acceptJson()
            ->timeout(30)
            ->post($this->baseUrl($credentials) . '/v1/auth/login', [
                'username' => (string) $credentials->get('username'),
                'password' => (string) $credentials->get('password'),
            ]);

        if ($response->failed()) {
            $desc = $response->json('desc');

            throw new RuntimeException(
                'Openprovider login ' . $response->status() . ': ' . (is_scalar($desc) ? (string) $desc : 'αποτυχία σύνδεσης')
            );
        }

        $token = $response->json('data.token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function baseUrl(DomainRegistrarCredentials $credentials): string
    {
        return $credentials->sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }

    /** @throws DomainRegistrarNotConfigured without a username and a password */
    private function requireCredentials(DomainRegistrarCredentials $credentials): void
    {
        if (!$credentials->has('username') || !$credentials->has('password')) {
            throw new DomainRegistrarNotConfigured('The Openprovider connection has no username or password: fill them in under Domain Registrars.');
        }
    }

    /**
     * A token belongs to the exact credentials that logged in: endpoint, username AND password (review F1: keyed by
     * username alone, another connection with that username and any password reused it). The key is a keyed hash, so
     * it carries no secret.
     */
    private function tokenCacheKey(DomainRegistrarCredentials $credentials): string
    {
        return 'domains:op:token:' . hash_hmac('sha256', $this->baseUrl($credentials) . '|' . (string) $credentials->get('username')
            . '|' . (string) $credentials->get('password'), (string) config('app.key'));
    }

    /** @return array{0: string, 1: string} name + extension for the OP payload shape. */
    private function splitFqdn(string $fqdn): array
    {
        $fqdn = mb_strtolower(trim($fqdn));
        $dot = mb_strpos($fqdn, '.');
        if ($dot === false || $dot === 0) {
            throw new RuntimeException('Μη έγκυρο όνομα domain: ' . $fqdn);
        }

        return [mb_substr($fqdn, 0, $dot), mb_substr($fqdn, $dot + 1)];
    }
}
