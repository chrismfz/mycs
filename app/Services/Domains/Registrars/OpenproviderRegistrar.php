<?php

namespace App\Services\Domains\Registrars;

use App\Contracts\DomainRegistrar;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Services\Domains\DomainNotFoundAtRegistrar;
use App\Services\Domains\DomainRegistrarNotConfigured;
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
 * Openprovider REST adapter (Πυλώνας A / A2) — docs/domains/README.md §4.3.
 *
 * DELIBERATELY READ-ONLY: this class contains NO mutating endpoint (no
 * register/renew/transfer/update/delete) until the A3 write slice — so a
 * connection configured with PRODUCTION credentials cannot cause any charge
 * or state change at the registrar, by construction (owner runs real creds
 * from day one). The write surface arrives with A3's idempotency/reconciler.
 *
 * Auth: POST /v1beta/auth/login (plaintext password over HTTPS — the MD5/token
 * variant is legacy XML-RPC) → bearer, ~24h TTL. We cache it for 6h keyed by
 * (endpoint × username) and re-login ONCE on a 401 mid-flight.
 */
class OpenproviderRegistrar implements DomainRegistrar
{
    private const PRODUCTION_URL = 'https://api.openprovider.eu';

    // HTTP on :8480 and a .nl host — the registrar's documented sandbox; the
    // old *.cte.openprovider.eu was retired (docs/domains/README.md §4.3).
    private const SANDBOX_URL = 'http://api.sandbox.openprovider.nl:8480';

    private const TOKEN_TTL_SECONDS = 6 * 3600;

    /** Tombstone statuses — a record in these represents NO ownership claim. */
    private const DEAD_STATUSES = ['DEL', 'FAI'];

    /** Openprovider price block → OUR DomainTldPrice operation. */
    private const PRICE_KEYS = [
        'create_price' => 'register',
        'renew_price' => 'renewal',
        'transfer_price' => 'transfer',
        'restore_price' => 'restore',
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
        $fqdn = $name.'.'.$extension; // normalized (lowercased/trimmed)

        $response = $this->request($credentials, 'POST', '/v1beta/domains/check', [
            'domains' => [['name' => $name, 'extension' => $extension]],
        ]);

        $result = $response->json('data.results.0');
        if (! is_array($result)) {
            throw new RuntimeException('Το Openprovider δεν επέστρεψε αποτέλεσμα διαθεσιμότητας για το '.$fqdn.'.');
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
            premium: ! empty($result['premium']) || ! empty($result['is_premium']),
        );
    }

    /**
     * Registrar truth for one domain. Uses the stored numeric OP id when we
     * have it; otherwise resolves it via ?full_name= (and the result carries it
     * so the caller can persist it — docs/domains/README.md §4.3 gap note).
     */
    public function syncDomain(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        return $this->syncResultFrom($this->fetchDomainData($domain, $credentials));
    }

    /**
     * WRITE (A3): renew at Openprovider — POST /v1beta/domains/{id}/renew.
     * ONLY DomainRenewalService calls this (it owns the §6.6 adopt guard +
     * the audit log). Resolves the OP id first (stale-id fallback included),
     * fires the renewal, then RE-FETCHES the domain so the caller applies the
     * post-renewal truth through the one apply path.
     */
    public function renew(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        if ($years < 1) {
            throw new RuntimeException('Μη έγκυρη διάρκεια ανανέωσης: '.$years);
        }

        // The service's mandatory pre-check sync just adopted/refreshed the
        // stored id — use it and skip a redundant GET; resolve only when the
        // row genuinely lacks one.
        $id = $this->resolveDomainId($domain, $credentials, 'αδύνατη η ανανέωση');

        $this->request($credentials, 'POST', '/v1beta/domains/'.rawurlencode($id).'/renew', [
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
     * autorenew is ALWAYS 'off' — the billing clock is ekdosi's, never the
     * registrar's. The ensured handles ride back in contactHandles so the
     * caller persists them onto the domain's contacts.
     */
    public function register(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        if ($years < 1) {
            throw new RuntimeException('Μη έγκυρη διάρκεια καταχώρησης: '.$years);
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

        $code = $this->request($credentials, 'GET', '/v1beta/domains/'.rawurlencode($id).'/authcode')->json('data.auth_code');

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
            if (! isset($handles['registrant'])) {
                throw new RuntimeException("Το {$domain->fqdn} δεν έχει επαφή registrant — απαιτείται για την ενημέρωση επαφών.");
            }
            $payload['owner_handle'] = $handles['registrant'];
            $payload['admin_handle'] = $handles['admin'] ?? $handles['registrant'];
            $payload['tech_handle'] = $handles['tech'] ?? $handles['registrant'];
            $payload['billing_handle'] = $handles['billing'] ?? $handles['registrant'];
        }

        $this->request($credentials, 'PUT', '/v1beta/domains/'.rawurlencode($id), $payload);

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

        $this->request($credentials, 'POST', '/v1beta/domains/'.rawurlencode($id).'/restore', [
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
            throw new RuntimeException('Το Openprovider δεν επέστρεψε id για το '.$domain->fqdn.' — '.$impossible.'.');
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
            $fresh = $this->request($credentials, 'GET', '/v1beta/domains/'.rawurlencode($id))->json('data');
        } catch (\Throwable) {
            return new DomainSyncResult(registrarDomainId: $id);
        }
        if (! is_array($fresh)) {
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

        $handles = $this->ensureHandles($domain, $credentials);
        if (! isset($handles['registrant'])) {
            throw new RuntimeException("Το {$domain->fqdn} δεν έχει επαφή registrant — απαιτείται για την ενέργεια στον registrar.");
        }

        $nameservers = $domain->nameservers->pluck('host')
            ->map(fn ($h) => mb_strtolower(trim((string) $h)))
            ->filter(fn ($h) => $h !== '')
            ->map(fn ($h) => ['name' => $h])
            ->values()
            ->all();

        $payload = array_merge([
            'domain' => ['name' => $name, 'extension' => $extension],
            'owner_handle' => $handles['registrant'],
            'admin_handle' => $handles['admin'] ?? $handles['registrant'],
            'tech_handle' => $handles['tech'] ?? $handles['registrant'],
            'billing_handle' => $handles['billing'] ?? $handles['registrant'],
            'autorenew' => 'off',
        ], $extra);
        if ($nameservers !== []) {
            // A transfer may deliberately keep the current delegation — send
            // nameservers only when the row carries them.
            $payload['name_servers'] = $nameservers;
        }

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
     * Reusable handles for the domain's contact rows: an existing
     * registrar_contact_handle is used as-is; a contact without one becomes an
     * OP «customer» (POST /v1beta/customers). Missing types fall back to the
     * registrant at the call site — never invented here.
     *
     * @return array<string, string> contact type → handle
     */
    private function ensureHandles(Domain $domain, DomainRegistrarCredentials $credentials): array
    {
        $handles = [];
        foreach ($domain->contacts as $contact) {
            $existing = trim((string) $contact->registrar_contact_handle);
            if ($existing !== '') {
                $handles[$contact->type] = $existing;

                continue;
            }

            // Best-effort split of «Οδός 12» / «Νίκος Παπαδόπουλος» into the
            // structured fields OP requires; OP's own validation errors (400 +
            // desc) surface verbatim — actionable, never swallowed.
            $nameParts = preg_split('/\s+/u', trim((string) $contact->name), 2) ?: [];
            $street = trim((string) $contact->address1);
            $number = '';
            if (preg_match('/^(.*?)\s+(\S*\d\S*)$/u', $street, $m) === 1) {
                [, $street, $number] = $m;
            }
            // Registrant phone is ICANN-relevant WHOIS data — split on real
            // ITU country-code lengths; a LOCAL number keeps ALL its digits
            // (never strip an area code) under the +30 default.
            $digits = str_replace([' ', '-', '(', ')'], '', trim((string) $contact->phone));
            if (str_starts_with($digits, '00')) {
                $digits = '+'.substr($digits, 2); // 0030… international form
            }
            $phonePayload = null;
            if ($digits !== '') {
                [$cc, $subscriber] = $this->splitCountryCode($digits);
                $phonePayload = [
                    'country_code' => $cc,
                    'area_code' => '',
                    'subscriber_number' => $subscriber,
                ];
            }

            $payload = array_filter([
                'name' => [
                    'first_name' => $nameParts[0] ?? '',
                    'last_name' => $nameParts[1] ?? ($nameParts[0] ?? ''),
                ],
                'company_name' => trim((string) $contact->org) !== '' ? trim((string) $contact->org) : null,
                'email' => $contact->email,
                'phone' => $phonePayload,
                'address' => [
                    'street' => $street,
                    'number' => $number,
                    'zipcode' => (string) $contact->postcode,
                    'city' => (string) $contact->city,
                    'country' => $contact->country ?: 'GR',
                ],
            ], fn ($v) => $v !== null);

            $handle = $this->request($credentials, 'POST', '/v1beta/customers', $payload)->json('data.handle');
            if (! is_string($handle) || $handle === '') {
                throw new RuntimeException("Το Openprovider δεν επέστρεψε handle για την επαφή «{$contact->name}» ({$contact->type}).");
            }
            // Persist IMMEDIATELY — a later register failure must not lose the
            // handle, else every retry creates a duplicate OP customer record
            // (orphan personal data at the registrar).
            $contact->forceFill(['registrar_contact_handle' => $handle])->save();
            $handles[$contact->type] = $handle;
        }

        return $handles;
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
            expiresAt: $this->datePart($data['expiration_date'] ?? null),
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

        $data = $this->request($credentials, 'GET', '/v1beta/tlds/'.rawurlencode($tld).'?with_price=true')->json('data');
        $prices = is_array($data) && is_array($data['prices'] ?? null) ? $data['prices'] : null;
        if ($prices === null) {
            throw new RuntimeException('Το Openprovider δεν επέστρεψε τιμές για το .'.$tld.'.');
        }

        $costs = [];
        foreach (self::PRICE_KEYS as $opKey => $operation) {
            $block = $prices[$opKey] ?? null;
            $entry = is_array($block) ? ($block['reseller'] ?? null) : null;
            if (! is_array($entry) || ! is_numeric($entry['price'] ?? null)) {
                continue;
            }
            $currency = is_string($entry['currency'] ?? null) && trim($entry['currency']) !== ''
                ? strtoupper(trim($entry['currency']))
                : 'EUR';
            $costs[$operation] = ['cost' => (float) $entry['price'], 'currency' => $currency];
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
            '/v1beta/domains?limit='.max(1, $limit).'&offset='.max(0, $offset)
        )->json('data');

        $results = is_array($data) && is_array($data['results'] ?? null) ? $data['results'] : [];
        $records = [];
        foreach ($results as $result) {
            if (! is_array($result)) {
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
        $response = $this->request($credentials, 'GET', '/v1beta/customers/'.rawurlencode($handle), tolerateStatus: 404);
        if ($response->status() === 404) {
            return null; // unknown/foreign handle — not an error, just no assign-aid
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            return null;
        }

        $first = is_array($data['name'] ?? null) ? trim((string) ($data['name']['first_name'] ?? '')) : '';
        $last = is_array($data['name'] ?? null) ? trim((string) ($data['name']['last_name'] ?? '')) : '';
        $person = trim($first.' '.$last);
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
        $street = trim(((string) ($address['street'] ?? '')).' '.((string) ($address['number'] ?? '')));
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
     * '+CC' + subscriber per ITU zone rules: +1/+7 are one-digit; zones 3/4
     * (Europe) are two-digit EXCEPT the 35x/37x/38x/42x three-digit ranges
     * (+357 Κύπρος, +353, +371, +380, +420, …); everything else defaults to
     * two digits — a local (no-prefix) number keeps all its digits under +30.
     *
     * @return array{0: string, 1: string} [country_code, subscriber]
     */
    private function splitCountryCode(string $digits): array
    {
        if (! str_starts_with($digits, '+')) {
            return ['+30', $digits];
        }
        if (preg_match('/^\+([17])(\d+)$/', $digits, $m) === 1) {
            return ['+'.$m[1], $m[2]];
        }
        $three = substr($digits, 1, 3);
        if (preg_match('/^(35\d|37\d|38[0-9])$/', $three) === 1 && ! in_array($three, ['384', '388'], true)
            || preg_match('/^42[013]$/', $three) === 1) {
            return ['+'.$three, substr($digits, 4)];
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
                $data = $this->request($credentials, 'GET', '/v1beta/domains/'.rawurlencode($domain->registrar_domain_id))->json('data');
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
        $results = $this->request($credentials, 'GET', '/v1beta/domains?full_name='.rawurlencode($name.'.'.$extension))
            ->json('data.results');
        // Prefer a LIVE record: after a lapse-and-rebuy the account can hold
        // BOTH the lingering DEL tombstone AND the fresh ACT record for the
        // same fqdn (id-ascending puts the tombstone first) — picking the
        // tombstone would disown a registration that was just paid for.
        $candidates = is_array($results) ? array_values(array_filter($results, 'is_array')) : [];
        $first = null;
        foreach ($candidates as $candidate) {
            if (! in_array((string) ($candidate['status'] ?? ''), self::DEAD_STATUSES, true)) {
                $first = $candidate;
                break;
            }
        }
        $first ??= $candidates[0] ?? null;
        if (! is_array($first)) {
            // TYPED: «not in the account» must be distinguishable from a
            // transport failure — the register adopt-guard acts on the
            // difference (third party vs could-not-read).
            throw new DomainNotFoundAtRegistrar('Το '.$domain->fqdn.' δεν βρέθηκε στον λογαριασμό Openprovider.');
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
            'PEN', 'REQ' => DomainStatus::PendingRegister,
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
                'Openprovider error '.$response->status().': '.(is_scalar($desc) && $desc !== '' ? (string) $desc : $response->body())
            );
        }

        return $response;
    }

    private function send(DomainRegistrarCredentials $credentials, string $token, string $method, string $path, array $payload): Response
    {
        return Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->send($method, $this->baseUrl($credentials).$path, $payload === [] ? [] : ['json' => $payload]);
    }

    /**
     * Cached bearer (6h) — Openprovider tokens live ~24h; 401 mid-flight
     * re-logins. Cached ENCRYPTED: with the DB cache driver a plaintext entry
     * would put a live full-access bearer into the `cache` table (and every DB
     * dump), while the credentials themselves are encrypted at rest.
     */
    private function token(DomainRegistrarCredentials $credentials): string
    {
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
        if (! $credentials->has('username') || ! $credentials->has('password')) {
            throw new DomainRegistrarNotConfigured(
                'Η σύνδεση Openprovider δεν έχει username/password — συμπληρώστε τα στη «Σύνδεση registrar».'
            );
        }

        $response = Http::acceptJson()
            ->timeout(30)
            ->post($this->baseUrl($credentials).'/v1beta/auth/login', [
                'username' => (string) $credentials->get('username'),
                'password' => (string) $credentials->get('password'),
            ]);

        if ($response->failed()) {
            $desc = $response->json('desc');

            throw new RuntimeException(
                'Openprovider login '.$response->status().': '.(is_scalar($desc) ? (string) $desc : 'αποτυχία σύνδεσης')
            );
        }

        $token = $response->json('data.token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function baseUrl(DomainRegistrarCredentials $credentials): string
    {
        return $credentials->sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }

    private function tokenCacheKey(DomainRegistrarCredentials $credentials): string
    {
        // Keyed by endpoint × username — never the password (no secret in a key).
        return 'domains:op:token:'.md5($this->baseUrl($credentials).'|'.(string) $credentials->get('username'));
    }

    /** @return array{0: string, 1: string} name + extension for the OP payload shape. */
    private function splitFqdn(string $fqdn): array
    {
        $fqdn = mb_strtolower(trim($fqdn));
        $dot = mb_strpos($fqdn, '.');
        if ($dot === false || $dot === 0) {
            throw new RuntimeException('Μη έγκυρο όνομα domain: '.$fqdn);
        }

        return [mb_substr($fqdn, 0, $dot), mb_substr($fqdn, $dot + 1)];
    }
}
