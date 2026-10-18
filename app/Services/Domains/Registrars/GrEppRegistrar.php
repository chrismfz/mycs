<?php

namespace App\Services\Domains\Registrars;

use App\Contracts\DomainRegistrar;
use App\Contracts\EmailsAuthCode;
use App\Contracts\RecallsRegistration;
use App\Enums\DomainStatus;
use App\Models\Contact;
use App\Models\Domain;
use App\Services\Domains\DomainNotFoundAtRegistrar;
use App\Services\Domains\DomainRegistrarNotConfigured;
use App\Services\Domains\RegistrarNotAsked;
use App\Services\Domains\RegistrarRefused;
use App\Support\Domains\AvailabilityResult;
use App\Support\Domains\DomainChanges;
use App\Support\Domains\DomainRegistrarCapabilities;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use App\Support\Domains\Phone;
use App\Support\Domains\RegistrarContact;
use App\Support\Domains\RegistrarDomainPage;
use App\Support\Domains\TldPricing;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use MyCS\GrEpp\Command\ContactCheck;
use MyCS\GrEpp\Command\ContactCreate;
use MyCS\GrEpp\Command\ContactInfo;
use MyCS\GrEpp\Command\DacorIssueToken;
use MyCS\GrEpp\Command\DomainCheck;
use MyCS\GrEpp\Command\DomainCreate;
use MyCS\GrEpp\Command\DomainDelete;
use MyCS\GrEpp\Command\DomainInfo;
use MyCS\GrEpp\Command\DomainRenew;
use MyCS\GrEpp\Command\HostCheck;
use MyCS\GrEpp\Command\HostCreate;
use MyCS\GrEpp\Command\Rules;
use MyCS\GrEpp\Config;
use MyCS\GrEpp\Data\ContactDraft;
use MyCS\GrEpp\Data\DomainInfo as Info;
use MyCS\GrEpp\Data\PostalInfo;
use MyCS\GrEpp\GrEppException;
use MyCS\GrEpp\Http\CurlTransport;
use MyCS\GrEpp\Http\Transport;
use MyCS\GrEpp\NotSent;
use MyCS\GrEpp\OutcomeUnknown;
use MyCS\GrEpp\Refused;
use MyCS\GrEpp\Response;
use MyCS\GrEpp\Session;
use RuntimeException;
use Throwable;

/**
 * The .gr / .ελ registry (ICS-FORTH) over EPP-over-HTTPS (docs/domains/grepp.md; the client: packages/gr-epp).
 *
 * G3: reads only (ping, availability, a domain's truth, a contact). Writes come with G5: until then each refuses before
 * anything is sent (RegistrarRefused: nothing done, never an unconfirmed charge).
 *
 * One session per account and process, kept for the process (a worker reuses it; the registry ends one idle for 15
 * minutes and Session logs in again); commands one at a time per account, across processes (a cache lock).
 */
class GrEppRegistrar implements DomainRegistrar, EmailsAuthCode, RecallsRegistration
{
    /** The registry's grace after expiry (pendingDelete: renewable only, D-8; its FAQ). */
    public const GRACE_DAYS = 15;

    /** The seam tests and the live check use: the HTTP transport (default curl, HTTPS, peer verified). */
    public static ?Closure $transport = null;

    /** @var array<string, Session> by account (endpoint, user, a hash of the password: a changed one is a new session) */
    private static array $sessions = [];

    public function key(): string
    {
        return 'grepp';
    }

    public function capabilities(): DomainRegistrarCapabilities
    {
        // Reads only until G5; the registry has no privacy toggle, no transfer lock, no price feed, no portfolio list. A
        // name in its 15 days after expiry is renewed (no restore), in even years (its FAQ).
        // G5a: registrations and renewals; transfers from G5b (not sold before: review 1 of #191).
        return new DomainRegistrarCapabilities(renewsInGrace: true, renewYearStep: 2, authCodeByEmail: true, transferKeepsNameservers: true,
            supportsRegistration: true, supportsRecall: true);
    }

    /** Contract: never throws. A login (and nothing else): the credentials and our IP at the registry. */
    public function ping(DomainRegistrarCredentials $credentials): bool
    {
        // Its own session, ended after (a login on a live session is refused itself: review 5 of #187).
        $session = null;
        try {
            $session = new Session(self::config($credentials), self::transport());

            return $session->login()->code === 1000;
        } catch (Throwable) {
            return false;
        } finally {
            $session?->logout();
        }
    }

    /**
     * Free, taken, or «available under restrictions» (geographic terms, .gov.gr: not free here, the regulator decides;
     * its reason says so). The registry's only availability check.
     */
    public function checkAvailability(string $fqdn, DomainRegistrarCredentials $credentials): AvailabilityResult
    {
        return $this->checkAvailabilityMany([$fqdn], $credentials)[$fqdn]
            ?? throw new RuntimeException("The registry did not answer for {$fqdn}.");
    }

    /**
     * The shop's search: up to 7 names in one domain:check (the registry's limit), on its own short session (5 seconds,
     * a 5-second wait for the account: review 3 of #187), by the names asked whatever their form (the registry answers
     * in Unicode; the search may ask in punycode).
     *
     * @param  list<string>  $fqdns
     * @return array<string, AvailabilityResult> by the name asked
     */
    public function checkAvailabilityMany(array $fqdns, DomainRegistrarCredentials $credentials): array
    {
        $asked = [];
        foreach ($fqdns as $fqdn) {
            $asked[$fqdn] = Rules::name($fqdn); // Unicode, lower case, as the registry answers
        }
        $results = [];
        foreach (array_chunk(array_keys($asked), DomainCheck::MAX) as $batch) {
            $names = array_values(array_unique(array_map(fn ($fqdn) => $asked[$fqdn], $batch)));
            $answers = $this->command($credentials, fn (string $clTRID) => DomainCheck::request($names, $clTRID), DomainCheck::parse(...), shop: true);
            foreach ($batch as $fqdn) {
                $result = $answers[$asked[$fqdn]] ?? null;
                if ($result !== null) {
                    $reason = $result->restricted ? 'restricted: ' . ($result->comment ?? $result->reason) : ($result->comment ?? $result->reason);
                    $results[$fqdn] = new AvailabilityResult($fqdn, $result->available, $reason);
                }
            }
        }

        return $results;
    }

    /** The registry's truth for one of our domains (domain:info): status, expiry day, nameservers, contacts, details. */
    public function syncDomain(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        try {
            $info = $this->command($credentials, fn (string $clTRID) => DomainInfo::request($domain->fqdn, $clTRID),
                fn (Response $response) => DomainInfo::parse($response, $domain->fqdn));
        } catch (Refused $e) {
            // Past its 15 days the registry deletes the name: «no such name» then is its end (review 1 of #187), not
            // «not ours». Only so for one whose expiry + 15 days is past (UTC).
            $expires = $domain->expires_at?->toDateString();
            if ($e->resultCode === 2303 && $expires !== null
                && Carbon::parse($expires, 'UTC')->addDays(self::GRACE_DAYS)->toDateString() < Carbon::now('UTC')->toDateString()) {
                return new DomainSyncResult(status: DomainStatus::Deleted, rawStatus: 'deleted', deadRecord: true,
                    details: ['registry' => "No such name at the registry ({$e->registryMessage}): deleted after its 15 days."]);
            }
            // 2303: no such name; 2201: not ours to read (another registrar's, no code given).
            if (in_array($e->resultCode, [2303, 2201], true)) {
                throw new DomainNotFoundAtRegistrar("{$domain->fqdn} is not ours at the registry ({$e->resultCode}: {$e->registryMessage}).");
            }
            throw new RuntimeException("The registry refused to read {$domain->fqdn}: {$e->getMessage()}");
        }
        if ($info->clientId !== null && strcasecmp($info->clientId, self::registrarId($credentials)) !== 0) {
            throw new DomainNotFoundAtRegistrar("{$domain->fqdn} is managed by another registrar at the registry ({$info->clientId}).");
        }

        $result = self::syncResultFrom($info);
        // A registration pending at the registry moves only a domain pending here (an imported active one stays: review
        // 10 of #187; the registry's statuses are in its details).
        if ($result->status === DomainStatus::PendingRegister && !in_array($domain->status, [DomainStatus::PendingRegister, null], true)) {
            return new DomainSyncResult($result->expiresAt, $result->nameservers, $result->registrarDomainId, null, $result->rawStatus,
                $result->contactHandles, false, null, null, $result->registeredAt, $result->details, $result->graceEndsOn, $result->redemptionEndsOn);
        }

        return $result;
    }

    /**
     * The registrar's id at the registry, as domain:clID carries it: the connection's, else the EPP user without its
     * trailing digits («myip1» → «myip»: the registry's users of one registrar, live 2026-10-18).
     */
    public static function registrarId(DomainRegistrarCredentials $credentials): string
    {
        $id = trim((string) $credentials->get('registrar_id', ''));

        return $id !== '' ? $id : (string) preg_replace('/\d+$/', '', (string) $credentials->get('username'));
    }

    public static function syncResultFrom(Info $info): DomainSyncResult
    {
        [$status, $graceEnds] = self::status($info);
        $contacts = array_filter([
            'registrant' => $info->registrant,
            'admin' => $info->contacts['admin'][0] ?? null,
            'tech' => $info->contacts['tech'][0] ?? null,
            'billing' => $info->contacts['billing'][0] ?? null,
        ]);

        return new DomainSyncResult(
            expiresAt: $info->expiresOn,
            nameservers: $info->nameservers,
            registrarDomainId: $info->roid,
            status: $status,
            rawStatus: implode(',', array_merge($info->statuses, $info->regulatorStatuses)) ?: 'ok',
            contactHandles: $contacts,
            registeredAt: $info->createdOn,
            details: array_filter([
                'roid' => $info->roid,
                'statuses' => implode(', ', $info->statuses) ?: 'ok',
                'regulator_statuses' => implode(', ', $info->regulatorStatuses) ?: null,
                'status_reasons' => $info->statusReasons !== [] ? implode('; ', array_map(fn ($s, $r) => "{$s}: {$r}", array_keys($info->statusReasons), $info->statusReasons)) : null,
                'protocol' => $info->protocol,
                'punycode' => $info->punycode,
                'bundle' => $info->bundle !== [] ? implode(', ', array_map(fn ($b) => "{$b->name} ({$b->recordType}" . ($b->chargeable ? ', chargeable' : '') . ')', $info->bundle)) : null,
                'ds_check' => $info->dsValidation !== null ? ($info->dsValidation->successful ? 'ok' : 'failed') . ($info->dsValidation->outcome !== null ? ": {$info->dsValidation->outcome}" : '') : null,
                'updated' => $info->updated?->format('Y-m-d H:i') . ($info->updated !== null ? ' UTC' : ''),
                'comments' => $info->comments !== [] ? implode(' ', $info->comments) : null,
            ], fn ($value) => $value !== null && $value !== ''),
            graceEndsOn: $graceEnds,
        );
    }

    /**
     * Our status from the registry's: pendingCreate → pending registration; pendingDelete (the 15 days after expiry,
     * renewal only) → grace until expiry + 15; past its expiry day otherwise → expired; else active (the regulator's
     * statuses and serverUpdateProhibited are details, not a lifecycle).
     *
     * @return array{0: ?DomainStatus, 1: ?string} [status, grace ends on]
     */
    private static function status(Info $info): array
    {
        // Not assigned yet: pendingCreate, or awaiting the regulator (.gov.gr, geographic terms; EETT may refuse: review 2).
        if (in_array('pendingCreate', $info->statuses, true) || in_array('pendingRegulatorApproval', $info->regulatorStatuses, true)) {
            return [DomainStatus::PendingRegister, null];
        }
        // On its way to another registrar (or in): not a state the app keeps; never «active» for the adopter (review 5).
        if ($info->expiresOn === null || in_array('pendingTransfer', $info->statuses, true)) {
            return [null, null];
        }
        $expires = Carbon::parse($info->expiresOn, 'UTC');
        $graceEnds = $expires->copy()->addDays(self::GRACE_DAYS)->toDateString();
        if (in_array('pendingDelete', $info->statuses, true)) {
            return [DomainStatus::Grace, $graceEnds];
        }
        // The registry's day is UTC (its FAQ): past it, the name is out of the zone.
        if ($expires->toDateString() < Carbon::now('UTC')->toDateString()) {
            return Carbon::now('UTC')->toDateString() <= $graceEnds ? [DomainStatus::Grace, $graceEnds] : [DomainStatus::Expired, null];
        }

        return [DomainStatus::Active, null];
    }

    /** One of our contacts (contact:info); null for one the registry does not have, or another registrar's. */
    public function getContact(string $handle, DomainRegistrarCredentials $credentials): ?RegistrarContact
    {
        try {
            $contact = $this->command($credentials, fn (string $clTRID) => ContactInfo::request($handle, $clTRID), ContactInfo::parse(...));
        } catch (Refused $e) {
            if (in_array($e->resultCode, [2303, 2201], true)) {
                return null;
            }
            throw new RuntimeException("The registry refused to read the contact {$handle}: {$e->getMessage()}");
        }
        $postal = $contact->postalInfo['loc'] ?? $contact->postalInfo['int'] ?? null;

        return new RegistrarContact(
            handle: $contact->id,
            name: (string) $postal?->name,
            org: $postal?->org,
            email: $contact->email,
            phone: $contact->voice,
            address1: $postal?->street[0] ?? null,
            address2: isset($postal?->street[1]) ? implode(', ', array_slice($postal->street, 1)) : null,
            city: $postal?->city,
            postcode: $postal?->postcode,
            country: $postal?->country,
        );
    }

    public function getTldPricing(string $tld, DomainRegistrarCredentials $credentials): TldPricing
    {
        throw new DomainRegistrarNotConfigured('The .gr registry publishes no price feed: its fees are set in Domain Pricing.');
    }

    public function listDomains(DomainRegistrarCredentials $credentials, int $offset, int $limit): RegistrarDomainPage
    {
        throw new DomainRegistrarNotConfigured('The .gr registry has no portfolio list: .gr domains are imported from WHMCS (D6).');
    }

    /**
     * WRITE (G5a): renew for $years (even, 2–10) with the current expiry day the registry has (read fresh: its
     * confirmation, which also makes a renewal sent twice fail rather than renew twice). In its 15 days after expiry too.
     * Returns the registry's truth after it.
     */
    public function renew(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        return $this->locked($credentials, function (Session $session) use ($domain, $years): DomainSyncResult {
            // Before the charge: anything failing here asked nothing that costs (RegistrarNotAsked).
            $current = self::prepare(function () use ($session, $domain, $years) {
                Rules::years($years);

                return self::read($session, $domain);
            });
            if ($current->expiresOn === null) {
                throw new RegistrarNotAsked("{$domain->fqdn} has no expiry at the registry: renew it in grweb.");
            }
            $result = self::charge(fn () => DomainRenew::parse(self::send($session, fn (string $clTRID) => DomainRenew::request($domain->fqdn, (string) $current->expiresOn, $years, $clTRID))));

            return self::truthAfter($session, $domain, $result->expiresOn);
        });
    }

    /**
     * WRITE (G5a): register for $years (even, 2–10). Its contacts become ours at the registry first (c60_…, one per
     * contact, made once), its nameservers must exist there (an external one is created; one under .gr needs its glue
     * made by staff first). Answered «pending» (1001) with its protocol: kept (its recall within 5 days needs it).
     */
    public function register(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        return $this->locked($credentials, function (Session $session) use ($domain, $years, $credentials): DomainSyncResult {
            [$handles, $hosts] = self::prepare(function () use ($session, $domain, $years, $credentials) {
                Rules::years($years);

                return [self::ensureContacts($session, $domain, self::prefix($credentials)), self::ensureHosts($session, $domain)];
            });
            if (!isset($handles['registrant'])) {
                throw new RegistrarNotAsked("{$domain->fqdn} has no registrant contact: the registry needs one.");
            }
            $roles = array_map(fn (string $id) => [$id], array_diff_key($handles, ['registrant' => true]));
            $result = self::charge(fn () => DomainCreate::parse(self::send($session,
                fn (string $clTRID) => DomainCreate::request($domain->fqdn, $years, $hosts, $handles['registrant'], $roles, $clTRID))));

            return new DomainSyncResult(status: DomainStatus::PendingRegister, rawStatus: 'pendingCreate', contactHandles: $handles,
                details: array_filter(['protocol' => $result->protocol, 'comments' => implode(' ', $result->comments) ?: null]));
        });
    }

    /**
     * WRITE (G5a): a registration recalled (within 5 days of it), by its protocol: the one kept from its answer, else
     * read now. The registry refuses it later, or for anything but a registration. Staff only (DomainManagementService).
     */
    public function recall(Domain $domain, DomainRegistrarCredentials $credentials): string
    {
        return $this->locked($credentials, function (Session $session) use ($domain): string {
            $protocol = self::prepare(function () use ($session, $domain) {
                $kept = $domain->meta['registrar']['protocol'] ?? null;

                return is_string($kept) && $kept !== '' ? $kept : (string) self::read($session, $domain)->protocol;
            });
            $result = self::charge(fn () => DomainDelete::parse(self::send($session, fn (string $clTRID) => DomainDelete::recall($domain->fqdn, $protocol, $clTRID))));

            return implode(' ', $result->comments) ?: __('domains.manage.recalled', ['domain' => $domain->fqdn]);
        });
    }

    /** The registrar's prefix for contact ids (the connection's, e.g. c60): our contacts are made with it. */
    private static function prefix(DomainRegistrarCredentials $credentials): string
    {
        $prefix = trim((string) $credentials->get('contact_prefix', ''));
        if (preg_match('/^[A-Za-z0-9]{3}\z/', $prefix) !== 1) {
            throw new DomainRegistrarNotConfigured('The .gr registry connection has no contact prefix (e.g. c60): fill it in under Domain Registrars.');
        }

        return $prefix;
    }

    /**
     * Each role's contact at the registry, ours (prefix_…): its handle if it has one of ours, else the contact's at the
     * registry, made once (id «prefix_mcs<contact id, base 36>»: the same contact is the same registry contact on every
     * domain; one made by a call whose answer was lost is found and used, never made twice). Kept on the domain at once.
     *
     * @return array<string, string> role => contact id
     */
    private static function ensureContacts(Session $session, Domain $domain, string $prefix): array
    {
        $handles = [];
        $made = []; // contact id => registry id: one contact in several roles is checked once
        foreach (Domain::ROLES as $role) {
            $existing = trim((string) $domain->{"{$role}_handle"});
            if ($existing !== '' && stripos($existing, "{$prefix}_") === 0) {
                $handles[$role] = $existing;

                continue;
            }
            $contact = $domain->{"{$role}Contact"};
            if (!$contact instanceof Contact) {
                continue;
            }
            if ((int) $contact->client_id !== (int) $domain->client_id) {
                throw new InvalidArgumentException("The {$role} contact of {$domain->fqdn} belongs to another account.");
            }
            $id = $made[$contact->id] ??= (function () use ($session, $contact, $prefix): string {
                $id = Rules::contactId("{$prefix}_mcs" . base_convert((string) $contact->id, 10, 36), $prefix);
                $check = ContactCheck::parse(self::send($session, fn (string $clTRID) => ContactCheck::request([$id], $clTRID)));
                if ($check[$id]->available ?? false) {
                    ContactCreate::parse(self::send($session, fn (string $clTRID) => ContactCreate::request(self::draft($id, $contact), $clTRID)));
                }

                return $id;
            })();
            Domain::query()->whereKey($domain->getKey())->update(["{$role}_handle" => $id]);
            $domain->forceFill(["{$role}_handle" => $id])->syncOriginalAttribute("{$role}_handle");
            $handles[$role] = $id;
        }

        return $handles;
    }

    /** A contact as the registry takes it: its Greek postal info only (as the current module makes them), no disclose. */
    private static function draft(string $id, Contact $contact): ContactDraft
    {
        $name = trim($contact->first_name . ' ' . $contact->last_name) ?: trim((string) $contact->company_name);
        $voice = Phone::epp($contact->phone) ?? throw new InvalidArgumentException("The contact {$id} has no phone: the registry needs one.");

        return new ContactDraft($id,
            new PostalInfo('loc', $name, trim((string) $contact->company_name) ?: null, array_values(array_filter([trim((string) $contact->address1), trim((string) $contact->address2)])),
                (string) $contact->city, trim((string) $contact->state) ?: (string) $contact->city, (string) $contact->postcode, (string) $contact->country),
            null, $voice, (string) $contact->email, hidden: null);
    }

    /**
     * Its nameservers, existing at the registry (a domain's must): an external one missing is created (no address); one
     * under .gr/.ελ missing needs its glue (IPs), made by staff first.
     *
     * @return list<string>
     */
    private static function ensureHosts(Session $session, Domain $domain): array
    {
        $hosts = Rules::nameservers($domain->nameservers->pluck('host')->all());
        foreach (array_chunk($hosts, HostCheck::MAX) as $batch) {
            $check = HostCheck::parse(self::send($session, fn (string $clTRID) => HostCheck::request($batch, $clTRID)));
            foreach ($batch as $host) {
                if (!($check[$host]->available ?? false)) {
                    continue; // registered there already
                }
                if (HostCreate::internal($host)) {
                    throw new InvalidArgumentException("The nameserver {$host} is not at the registry: create it with its IP (glue) first.");
                }
                HostCreate::parse(self::send($session, fn (string $clTRID) => HostCreate::request($host, [], $clTRID)));
            }
        }

        return $hosts;
    }

    private static function read(Session $session, Domain $domain): Info
    {
        return DomainInfo::parse(self::send($session, fn (string $clTRID) => DomainInfo::request($domain->fqdn, $clTRID)), $domain->fqdn);
    }

    /** @param  Closure(string): string  $request */
    private static function send(Session $session, Closure $request): Response
    {
        $clTRID = $session->newTransactionId();

        return $session->command($request($clTRID), $clTRID);
    }

    /**
     * Before the charge: any failure here asked nothing that costs (contacts, hosts, a read): RegistrarNotAsked, never
     * an unconfirmed charge (D-17); a refusal keeps the registry's words.
     *
     * @template T
     *
     * @param  Closure(): T  $steps
     * @return T
     */
    private static function prepare(Closure $steps): mixed
    {
        try {
            return $steps();
        } catch (InvalidArgumentException|GrEppException $e) {
            throw new RegistrarNotAsked($e->getMessage(), 0, $e);
        }
    }

    /**
     * The charge itself: refused → RegistrarRefused (nothing done); not sent → RegistrarNotAsked; no usable answer →
     * RuntimeException (maybe done: never sent again blindly, D-17).
     *
     * @template T
     *
     * @param  Closure(): T  $send
     * @return T
     */
    private static function charge(Closure $send): mixed
    {
        try {
            return $send();
        } catch (Refused $e) {
            throw new RegistrarRefused($e->getMessage());
        } catch (NotSent|InvalidArgumentException $e) {
            throw new RegistrarNotAsked($e->getMessage(), 0, $e);
        } catch (OutcomeUnknown $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /** The registry's truth after a charge; if it cannot be read now, what the charge answered (the next sync reads it). */
    private static function truthAfter(Session $session, Domain $domain, ?string $expiresOn): DomainSyncResult
    {
        try {
            return self::syncResultFrom(self::read($session, $domain));
        } catch (Throwable) {
            return new DomainSyncResult(expiresAt: $expiresOn, status: DomainStatus::Active, rawStatus: 'ok');
        }
    }

    public function transferIn(Domain $domain, string $authCode, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        throw self::notYet('transfer');
    }

    /**
     * DACoR (G4b): the registry emails the registrant a link to reset the code; only the masked address comes back. Free.
     */
    public function emailAuthCode(Domain $domain, DomainRegistrarCredentials $credentials): string
    {
        try {
            $result = $this->command($credentials, fn (string $clTRID) => DacorIssueToken::request($domain->fqdn, $clTRID), DacorIssueToken::parse(...));
        } catch (Refused $e) {
            throw new RegistrarRefused($e->getMessage());
        }

        return $result->comments[0] ?? __('domains.manage.auth_code_emailed');
    }

    /** The registry never gives the code: it emails the registrant a reset link (DACoR: emailAuthCode). */
    public function getEppCode(Domain $domain, DomainRegistrarCredentials $credentials): ?string
    {
        throw self::notYet('give an EPP code (the registry emails the registrant instead: DACoR)');
    }

    public function updateDomain(Domain $domain, DomainChanges $changes, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        throw self::notYet('update');
    }

    public function restore(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        throw new RegistrarRefused('The .gr registry has no restore: a name is renewable for 15 days after expiry, then lost.');
    }

    /** A write before G5: refused before anything is sent (nothing done; never an unconfirmed charge, D-17). */
    private static function notYet(string $what): RegistrarRefused
    {
        return new RegistrarRefused("The .gr registry cannot {$what} from MyCS yet (grepp G5): do it in grweb.");
    }

    /**
     * One command under the account's lock: built with a fresh clTRID, answered with it (Session checks the echo).
     *
     * @template T
     *
     * @param  Closure(string): string  $request
     * @param  Closure(Response): T  $parse
     * @return T
     */
    private function command(DomainRegistrarCredentials $credentials, Closure $request, Closure $parse, bool $shop = false): mixed
    {
        return $this->locked($credentials, function (Session $session) use ($request, $parse) {
            $clTRID = $session->newTransactionId();

            return $parse($session->command($request($clTRID), $clTRID));
        }, $shop);
    }

    /**
     * @template T
     *
     * @param  Closure(Session): T  $call
     * @return T
     */
    private function locked(DomainRegistrarCredentials $credentials, Closure $call, bool $shop = false): mixed
    {
        $config = self::config($credentials);
        // One command at a time per account across processes: the registry's limit on sessions per account is not
        // documented, so the app never opens more than one at a time. Its TTL above the worst case (hello, login, a
        // command, and the 2002 retry's logout, hello, login, command: review 7 of #187). The shop waits 5 seconds.
        try {
            return Cache::lock('grepp-epp:' . sha1($config->endpoint . '|' . $config->clientId), 600)
                ->block($shop ? 5 : 60, fn () => $call(self::session($config, $credentials, $shop)));
        } catch (LockTimeoutException) {
            throw new RuntimeException('The .gr registry account is busy with another command: try again in a minute.');
        } catch (NotSent $e) {
            throw new RegistrarNotAsked($e->getMessage(), 0, $e);
        } catch (GrEppException $e) {
            throw $e instanceof Refused ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    private static function config(DomainRegistrarCredentials $credentials): Config
    {
        if (!$credentials->has('username') || !$credentials->has('password')) {
            throw new DomainRegistrarNotConfigured('The .gr registry connection has no username or password: fill them in under Domain Registrars.');
        }
        try {
            return new Config((string) $credentials->get('username'), (string) $credentials->get('password'),
                endpoint: $credentials->sandbox ? Config::UAT : Config::PRODUCTION);
        } catch (InvalidArgumentException $e) {
            throw new DomainRegistrarNotConfigured('The .gr registry connection: ' . $e->getMessage()); // never the password (review 8)
        }
    }

    /** A transport: the seam's, else curl (the shop's: 5 seconds, 3 to connect). */
    private static function transport(bool $shop = false): Transport
    {
        $transport = self::$transport !== null ? (self::$transport)() : new CurlTransport($shop ? 5 : 60, $shop ? 3 : 10);
        if (!$transport instanceof Transport) {
            throw new RuntimeException('The .gr transport seam must give a Transport.');
        }

        return $transport;
    }

    private static function session(Config $config, DomainRegistrarCredentials $credentials, bool $shop = false): Session
    {
        $key = sha1($config->endpoint . '|' . $config->clientId . '|' . hash('sha256', (string) $credentials->get('password')) . ($shop ? '|shop' : ''));

        return self::$sessions[$key] ??= new Session($config, self::transport($shop));
    }

    /** End and forget the sessions: at the end of each request or command (AppServiceProvider), in tests (review 6). */
    public static function reset(): void
    {
        foreach (self::$sessions as $session) {
            $session->logout();
        }
        self::$sessions = [];
    }
}
