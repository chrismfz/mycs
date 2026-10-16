<?php

namespace App\Services\Domains\Registrars;

use App\Contracts\DomainRegistrar;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Services\Domains\DomainNotFoundAtRegistrar;
use App\Services\Domains\DomainRegistrarNotConfigured;
use App\Services\Domains\Pricing\TldPriceMath;
use App\Support\Domains\AvailabilityResult;
use App\Support\Domains\DomainChanges;
use App\Support\Domains\DomainRegistrarCapabilities;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use App\Support\Domains\RegistrarContact;
use App\Support\Domains\RegistrarDomainPage;
use App\Support\Domains\RegistrarDomainRecord;
use App\Support\Domains\TldPricing;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Openprovider REST adapter (docs/domains.md D2), from Ekdosi through the spike, with the three fixes of the live
 * nixpal.com transfer (area code, glue IPs for in-domain nameservers, no expiry adopted while pending).
 *
 * Writes that cost money (register, renew, transfer, restore) and change the domain (update) are called ONLY by the
 * domain services, which hold the per-domain lock, log every attempt and adopt what the registrar already did (D-2,
 * D-17); never from the UI. Autorenew is always off: the billing clock is ours (D-12).
 *
 * Auth: POST /v1beta/auth/login (plaintext password over HTTPS — the MD5/token
 * variant is legacy XML-RPC) → bearer, ~24h TTL. We cache it for 6h keyed by
 * (endpoint × username) and re-login ONCE on a 401 mid-flight.
 */
class OpenproviderRegistrar implements DomainRegistrar
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
        } catch (\Throwable) {
            return false;
        }
    }

    public function checkAvailability(string $fqdn, DomainRegistrarCredentials $credentials): AvailabilityResult
    {
        [$name, $extension] = $this->splitFqdn($fqdn);
        $fqdn = $name . '.' . $extension; // normalized (lowercased/trimmed)

        $response = $this->request($credentials, 'POST', '/v1beta/domains/check', [
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
     * WRITE (A3): renew at Openprovider — POST /v1beta/domains/{id}/renew.
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

        $this->request($credentials, 'POST', '/v1beta/domains/' . rawurlencode($id) . '/renew', [
            'id' => (int) $id,
            'period' => $years,
        ]);

        // The renew envelope doesn't reliably carry the new expiry — re-fetch
        // so the caller gets (and applies) the registrar's OWN post-renew
        // truth. From here on the registrar HAS charged: a re-fetch hiccup
        // must never surface as a failed renewal (the caller would log
        // 'failed' + alert for a renewal that actually succeeded).
        return $this->refetchAfterWrite($id, $credentials);
    }

    /**
     * WRITE (A3b): register at Openprovider — ensure reusable contact handles
     * (POST /v1beta/customers for contacts without one), then POST
     * /v1beta/domains. ONLY DomainRegistrationService calls this (it owns the
     * availability pre-check, the adopt-on-retry guard and the audit log).
     * autorenew is ALWAYS 'off' — the billing clock is ours, never the
     * registrar's (D-12). The ensured handles are saved on the domain.
     */
    public function register(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        if ($years < 1) {
            throw new RuntimeException('Μη έγκυρη διάρκεια καταχώρησης: ' . $years);
        }

        return $this->createDomainObject($domain, $credentials, '/v1beta/domains', ['period' => $years]);
    }

    /**
     * WRITE (A3c): start an INBOUND transfer — POST /v1beta/domains/transfer
     * with the losing registrar's auth code. Same money discipline as
     * register (gTLD transfers charge a renewal year): ONLY
     * DomainTransferService calls this. Async: the response usually carries a
     * pending status — the nightly sync drives §6.3 to completion.
     */
    public function transferIn(Domain $domain, string $authCode, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        if (trim($authCode) === '') {
            throw new RuntimeException('Ο κωδικός EPP/auth είναι κενός — απαιτείται για τη μεταφορά.');
        }

        return $this->createDomainObject($domain, $credentials, '/v1beta/domains/transfer', [
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

        $code = $this->request($credentials, 'GET', '/v1beta/domains/' . rawurlencode($id) . '/authcode')->json('data.auth_code');

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * WRITE (A3d): registrar-side settings — one PUT /v1beta/domains/{id}
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
            $payload['name_servers'] = array_map(
                fn (string $h) => ['name' => mb_strtolower(trim($h))],
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
            $payload['owner_handle'] = $handles['registrant'];
            $payload['admin_handle'] = $handles['admin'] ?? $handles['registrant'];
            $payload['tech_handle'] = $handles['tech'] ?? $handles['registrant'];
            $payload['billing_handle'] = $handles['billing'] ?? $handles['registrant'];
        }

        $this->request($credentials, 'PUT', '/v1beta/domains/' . rawurlencode($id), $payload);

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
        );
    }

    /**
     * WRITE (A3d): restore from redemption — POST /v1beta/domains/{id}/restore.
     * REAL (usually large) MONEY: ONLY DomainManagementService calls this (it
     * owns the sync-first adopt guard + the audit log). From the POST on the
     * registrar HAS charged — a re-fetch hiccup must never surface as a failed
     * restore; fall back to a minimal result (the nightly sync lands the rest).
     */
    public function restore(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        $id = $this->resolveDomainId($domain, $credentials, 'αδύνατη η επαναφορά');

        $this->request($credentials, 'POST', '/v1beta/domains/' . rawurlencode($id) . '/restore', [
            'id' => (int) $id,
        ]);

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
            $fresh = $this->request($credentials, 'GET', '/v1beta/domains/' . rawurlencode($id))->json('data');
        } catch (\Throwable) {
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

        $data = $this->request($credentials, 'POST', $path, $payload)->json('data');
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
     * none becomes an Openprovider «customer» (POST /v1beta/customers), its handle saved on the domain at once, so a
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
                if ($contact !== null) {
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

            // Best-effort split of «Οδός 12» into the street and number OP requires; OP's own validation errors (400 +
            // desc) surface verbatim.
            $street = trim((string) $contact->address1);
            $number = '';
            if (preg_match('/^(.*?)\s+(\S*\d\S*)$/u', $street, $m) === 1) {
                [, $street, $number] = $m;
            } elseif (preg_match('/^(\d\S*)\s+(.+)$/u', $street, $m) === 1) {
                [, $number, $street] = $m; // «12 Main St»
            }
            // Registrant phone is WHOIS data: digits only (and a leading +); split on real ITU country-code lengths; a
            // local number keeps all its digits under the +30 default.
            $digits = preg_replace('/(?!^\+)[^0-9]/', '', trim((string) $contact->phone)) ?? '';
            if (str_starts_with($digits, '00')) {
                $digits = '+' . substr($digits, 2);
            }
            $phonePayload = null;
            if (ltrim($digits, '+') !== '') {
                [$cc, $subscriber] = $this->splitCountryCode($digits);
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

            $payload = array_filter([
                'name' => [
                    'first_name' => (string) $contact->first_name,
                    'last_name' => (string) ($contact->last_name ?: $contact->first_name),
                ],
                'company_name' => trim((string) $contact->company_name) !== '' ? trim((string) $contact->company_name) : null,
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

            $handle = $this->request($credentials, 'POST', '/v1beta/customers', $payload)->json('data.handle');
            if (!is_string($handle) || $handle === '') {
                throw new RuntimeException("Openprovider returned no handle for the {$role} contact of {$domain->fqdn}.");
            }
            $this->keepHandle($domain, $role, $handle);
            $handles[$role] = $byContact[$contact->id] = $handle;
        }

        return $handles;
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

        return new DomainSyncResult(
            // A pending order carries a placeholder expiry (today + 1 year): never ours until it is active (live
            // finding, nixpal.com).
            expiresAt: in_array($rawStatus, self::PENDING_STATUSES, true) ? null : $this->datePart($data['expiration_date'] ?? null),
            nameservers: $nameservers,
            registrarDomainId: isset($data['id']) ? (string) $data['id'] : null,
            status: $this->mapStatus($rawStatus),
            rawStatus: $rawStatus,
            contactHandles: $this->extractHandles($data),
            // DEL = deleted, FAI = failed request — tombstones, not ownership.
            deadRecord: in_array($rawStatus, self::DEAD_STATUSES, true),
            // Only an EXPLICIT boolean counts — an absent key must never
            // overwrite the local mirror with a guessed false.
            transferLock: is_bool($data['is_locked'] ?? null) ? $data['is_locked'] : null,
            whoisPrivacy: is_bool($data['is_private_whois_enabled'] ?? null) ? $data['is_private_whois_enabled'] : null,
        );
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

        $data = $this->request($credentials, 'GET', '/v1beta/tlds/' . rawurlencode($tld) . '?with_price=true')->json('data');
        $prices = is_array($data) && is_array($data['prices'] ?? null) ? $data['prices'] : null;
        if ($prices === null) {
            throw new RuntimeException('Το Openprovider δεν επέστρεψε τιμές για το .' . $tld . '.');
        }

        $costs = [];
        foreach (self::PRICE_KEYS as $opKey => $operation) {
            $block = $prices[$opKey] ?? null;
            $entry = is_array($block) ? ($block['reseller'] ?? null) : null;
            // A price we can use: a number, never negative (a negative would become a negative price: review F1).
            if (!is_array($entry) || !is_scalar($entry['price'] ?? null) || !TldPriceMath::isAmount((string) $entry['price'])) {
                continue;
            }
            // Its currency is never guessed: a price without one is left out.
            if (!is_string($entry['currency'] ?? null) || trim($entry['currency']) === '') {
                continue;
            }
            // Two decimals in decimal arithmetic, never through a float (CLAUDE.md); half up as the registrar bills.
            $costs[$operation] = [
                'cost' => bcadd((string) $entry['price'], '0.005', 2),
                'currency' => strtoupper(trim($entry['currency'])),
            ];
        }

        return new TldPricing(tld: $tld, costs: $costs);
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
            '/v1beta/domains?limit=' . max(1, $limit) . '&offset=' . max(0, $offset)
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
        $response = $this->request($credentials, 'GET', '/v1beta/customers/' . rawurlencode($handle), tolerateStatus: 404);
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

    /**
     * '+CC' + subscriber per ITU zone rules: +1/+7 are one-digit; zones 3/4
     * (Europe) are two-digit EXCEPT the 35x/37x/38x/42x three-digit ranges
     * (+357 Κύπρος, +353, +371, +380, +420, …); everything else defaults to
     * two digits — a local (no-prefix) number keeps all its digits under +30.
     *
     * @return array{0: string, 1: string} [country_code, subscriber]
     */
    private function splitCountryCode(string $digits): array
    {
        if (!str_starts_with($digits, '+')) {
            return ['+30', $digits];
        }
        if (preg_match('/^\+([17])(\d+)$/', $digits, $m) === 1) {
            return ['+' . $m[1], $m[2]];
        }
        $three = substr($digits, 1, 3);
        if (preg_match('/^(35\d|37\d|38[0-9])$/', $three) === 1 && !in_array($three, ['384', '388'], true)
            || preg_match('/^42[013]$/', $three) === 1) {
            return ['+' . $three, substr($digits, 4)];
        }

        return [substr($digits, 0, 3), substr($digits, 3)];
    }

    /** OP returns "YYYY-MM-DD HH:MM:SS" — the date part is our clock. */
    private function datePart(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    /** @return array<string, mixed> */
    private function fetchDomainData(Domain $domain, DomainRegistrarCredentials $credentials): array
    {
        // Authenticate ONCE up front: a login rejection must throw here (bad
        // creds = one failed POST per run, not one per fallback branch — OP
        // rate-limits repeated auth failures) and never be eaten by the
        // stale-id catch below, which is for domain-fetch errors only.
        $this->token($credentials);

        if ($domain->registrar_domain_id !== null && $domain->registrar_domain_id !== '') {
            try {
                $data = $this->request($credentials, 'GET', '/v1beta/domains/' . rawurlencode($domain->registrar_domain_id))->json('data');
                if (is_array($data)) {
                    return $data;
                }
            } catch (RuntimeException) {
                // Stale/wrong stored id (object re-created under a new id, or a
                // typo) — fall through to the by-name resolve instead of failing
                // the domain's sync forever. The by-name result carries the new
                // id and DomainSyncService adopts it (no repeat next run).
            }
        }

        [$name, $extension] = $this->splitFqdn($domain->fqdn);
        $results = $this->request($credentials, 'GET', '/v1beta/domains?full_name=' . rawurlencode($name . '.' . $extension))
            ->json('data.results');
        // Prefer a LIVE record: after a lapse-and-rebuy the account can hold
        // BOTH the lingering DEL tombstone AND the fresh ACT record for the
        // same fqdn (id-ascending puts the tombstone first) — picking the
        // tombstone would disown a registration that was just paid for.
        $candidates = is_array($results) ? array_values(array_filter($results, 'is_array')) : [];
        $first = null;
        foreach ($candidates as $candidate) {
            if (!in_array((string) ($candidate['status'] ?? ''), self::DEAD_STATUSES, true)) {
                $first = $candidate;
                break;
            }
        }
        $first ??= $candidates[0] ?? null;
        if (!is_array($first)) {
            // TYPED: «not in the account» must be distinguishable from a
            // transport failure — the register adopt-guard acts on the
            // difference (third party vs could-not-read).
            throw new DomainNotFoundAtRegistrar('Το ' . $domain->fqdn . ' δεν βρέθηκε στον λογαριασμό Openprovider.');
        }

        return $first;
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
    private function request(DomainRegistrarCredentials $credentials, string $method, string $path, array $payload = [], ?int $tolerateStatus = null): Response
    {
        $response = $this->send($credentials, $this->token($credentials), $method, $path, $payload);

        if ($response->status() === 401) {
            Cache::forget($this->tokenCacheKey($credentials));
            $response = $this->send($credentials, $this->token($credentials), $method, $path, $payload);
        }

        if ($tolerateStatus !== null && $response->status() === $tolerateStatus) {
            return $response;
        }

        if ($response->failed()) {
            $desc = $response->json('desc');

            throw new RuntimeException(
                // Its own description only: a raw body may echo contact data (review 11).
                'Openprovider error ' . $response->status() . ': ' . (is_scalar($desc) && $desc !== '' ? mb_substr((string) $desc, 0, 300) : 'no description')
            );
        }

        return $response;
    }

    private function send(DomainRegistrarCredentials $credentials, string $token, string $method, string $path, array $payload): Response
    {
        return Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
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
            ->post($this->baseUrl($credentials) . '/v1beta/auth/login', [
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
