<?php

namespace App\Services\Domains\Registrars;

use App\Contracts\ChangesOwner;
use App\Contracts\DomainRegistrar;
use App\Contracts\EmailsAuthCode;
use App\Contracts\ManagesChildHosts;
use App\Contracts\ManagesDnssec;
use App\Contracts\ReadsAccountBalance;
use App\Contracts\RecallsRegistration;
use App\Contracts\StaffPricedNames;
use App\Contracts\UpdatesContacts;
use App\Enums\DomainStatus;
use App\Models\Contact;
use App\Models\Domain;
use App\Services\Domains\DnssecChangedSinceRead;
use App\Services\Domains\DomainNotFoundAtRegistrar;
use App\Services\Domains\DomainRegistrarNotConfigured;
use App\Services\Domains\RegistrarNotAsked;
use App\Services\Domains\RegistrarOutcomeUnknown;
use App\Services\Domains\RegistrarRefused;
use App\Support\Domains\AvailabilityResult;
use App\Support\Domains\ChildHost;
use App\Support\Domains\DnssecRecord;
use App\Support\Domains\DomainChanges;
use App\Support\Domains\DomainRegistrarCapabilities;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use App\Support\Domains\Phone;
use App\Support\Domains\RegistrarBalance;
use App\Support\Domains\RegistrarContact;
use App\Support\Domains\RegistrarDomainPage;
use App\Support\Domains\RegistrarTransactions;
use App\Support\Domains\TldPricing;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use MyCS\GrEpp\Command\AccountInfo;
use MyCS\GrEpp\Command\ContactCheck;
use MyCS\GrEpp\Command\ContactCreate;
use MyCS\GrEpp\Command\ContactDelete;
use MyCS\GrEpp\Command\ContactInfo;
use MyCS\GrEpp\Command\ContactUpdate;
use MyCS\GrEpp\Command\DacorIssueToken;
use MyCS\GrEpp\Command\DomainCheck;
use MyCS\GrEpp\Command\DomainCreate;
use MyCS\GrEpp\Command\DomainDelete;
use MyCS\GrEpp\Command\DomainInfo;
use MyCS\GrEpp\Command\DomainRenew;
use MyCS\GrEpp\Command\DomainSecDns;
use MyCS\GrEpp\Command\DomainTransfer;
use MyCS\GrEpp\Command\DomainUpdate;
use MyCS\GrEpp\Command\HostCheck;
use MyCS\GrEpp\Command\HostCreate;
use MyCS\GrEpp\Command\HostDelete;
use MyCS\GrEpp\Command\HostInfo;
use MyCS\GrEpp\Command\HostUpdate;
use MyCS\GrEpp\Command\OwnerChange;
use MyCS\GrEpp\Command\Rules;
use MyCS\GrEpp\Config;
use MyCS\GrEpp\Data\ContactChanges;
use MyCS\GrEpp\Data\ContactDraft;
use MyCS\GrEpp\Data\DomainInfo as Info;
use MyCS\GrEpp\Data\DsRecord;
use MyCS\GrEpp\Data\HostInfo as HostData;
use MyCS\GrEpp\Data\PostalInfo;
use MyCS\GrEpp\GrEppException;
use MyCS\GrEpp\Http\CurlTransport;
use MyCS\GrEpp\Http\TracingTransport;
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
class GrEppRegistrar implements ChangesOwner, DomainRegistrar, EmailsAuthCode, ManagesChildHosts, ManagesDnssec, ReadsAccountBalance, RecallsRegistration, StaffPricedNames, UpdatesContacts
{
    /** The registry's grace after expiry (pendingDelete: renewable only, D-8; its FAQ). */
    public const GRACE_DAYS = 15;

    /** The seam tests and the live check use: the HTTP transport (default curl, HTTPS, peer verified). */
    public static ?Closure $transport = null;

    /** @var array<string, Session> by account (endpoint, user, a hash of the password: a changed one is a new session) */
    private static array $sessions = [];

    /** @var array<string, bool> by session: its connection's debug log switch, as the last call read it */
    private static array $debug = [];

    public function key(): string
    {
        return 'grepp';
    }

    public function capabilities(): DomainRegistrarCapabilities
    {
        // The registry has no privacy toggle, no transfer lock, no price feed, no portfolio list. A name in its 15 days
        // after expiry is renewed (no restore), in even years (its FAQ). G5a: registrations, renewals; G5b-2: transfers.
        return new DomainRegistrarCapabilities(renewsInGrace: true, renewYearStep: 2, authCodeByEmail: true, transferKeepsNameservers: true,
            supportsRegistration: true, supportsRecall: true, supportsGlueHosts: true, supportsTransfer: true);
    }

    /** Contract: never throws. A login (and nothing else): the credentials and our IP at the registry. */
    public function ping(DomainRegistrarCredentials $credentials): bool
    {
        // Its own session, ended after (a login on a live session is refused itself: review 5 of #187).
        $session = null;
        try {
            $session = new Session(self::config($credentials), self::transport(false, fn (): bool => $credentials->debug));

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
                    // A free name of 2 characters is premium: never sold here (the EETT's 300 € a deed, owner 2026-10-18).
                    $premium = $result->available && self::twoCharacters($fqdn);
                    $results[$fqdn] = new AvailabilityResult($fqdn, $result->available, $premium ? 'two characters: by staff' : $reason, premium: $premium);
                }
            }
        }

        return $results;
    }

    /**
     * A name of 2 characters or fewer (ab.gr, ab.com.gr, αβ.ελ, its punycode too): the EETT charges 300 € for each deed (registration,
     * renewal, transfer) and the registry's ceiling to the customer is 550 € + VAT (docs/domains/grepp/faq.md). Never sold
     * or charged from here at a TLD's price: staff do it by hand (owner 2026-10-18; none at myip so far).
     */
    public static function twoCharacters(string $fqdn): bool
    {
        try {
            $name = Rules::name($fqdn); // Unicode, lower case
        } catch (InvalidArgumentException) {
            return false; // not a name the registry takes: every deed refuses it before anything is sent (Rules::name again)
        }

        $labels = explode('.', $name);

        // A .gr or .ελ name only (the EETT's fee), whichever registrar or connection its TLD has (F4 of #202).
        return in_array(end($labels), ['gr', 'ελ'], true) && mb_strlen($labels[0]) <= 2;
    }

    public function staffPriced(string $fqdn): bool
    {
        return self::twoCharacters($fqdn);
    }

    private static function notTwoCharacters(string $fqdn): void
    {
        if (self::twoCharacters($fqdn)) {
            throw new RegistrarNotAsked("{$fqdn} has 2 characters or fewer: the EETT charges 300 € for each deed, so staff do it by hand (not at the TLD's price).");
        }
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
                throw new DomainNotFoundAtRegistrar("{$domain->fqdn} is not ours at the registry ({$e->resultCode}: {$e->registryMessage}).",
                    elsewhere: $e->resultCode === 2201, resultCode: $e->resultCode); // 2201: another registrar's; 2303: no such name
            }
            throw new RuntimeException("The registry refused to read {$domain->fqdn}: {$e->getMessage()}");
        }
        if ($info->clientId !== null && strcasecmp($info->clientId, self::registrarId($credentials)) !== 0) {
            // The registry gave its record, which it gives only its sponsor: ours, and the connection's «Registrar id» is
            // wrong. Never taken as a transfer away (ADV-1 of #203): elsewhere false, staff fix the setting.
            throw new DomainNotFoundAtRegistrar("{$domain->fqdn}: the registry's clID is {$info->clientId}, not the connection's registrar id "
                . self::registrarId($credentials) . ': check its «Registrar id».', elsewhere: false);
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

    /**
     * P1b-2: one of our contacts' address, phone and email as the contact's (contact:update; its name and company never,
     * the registry keeps them). Its Greek postal info, as made (draft()).
     */
    public function updateContact(string $handle, Contact $contact, DomainRegistrarCredentials $credentials): void
    {
        // Only one made under our prefix: another registrar's (a domain transferred in) is not ours to change (M3 of #248).
        if (stripos($handle, self::prefix($credentials) . '_') !== 0) {
            throw new InvalidArgumentException("The registry contact {$handle} is not one of ours: not changed.");
        }
        $voice = Phone::epp($contact->phone, $contact->country) ?? throw new InvalidArgumentException("The contact {$handle} has no phone: the registry needs one.");
        $changes = new ContactChanges(
            localAddress: ['street' => array_values(array_filter([trim((string) $contact->address1), trim((string) $contact->address2)])),
                'city' => (string) $contact->city, 'province' => trim((string) $contact->state) ?: (string) $contact->city,
                'postcode' => (string) $contact->postcode, 'country' => (string) $contact->country],
            voice: $voice,
            email: (string) $contact->email,
        );
        try {
            $this->command($credentials, fn (string $clTRID) => ContactUpdate::request($handle, $changes, $clTRID), ContactUpdate::parse(...));
        } catch (Refused $e) {
            throw new RegistrarRefused("The registry refused to update the contact {$handle}: {$e->getMessage()}");
        }
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
        self::notTwoCharacters($domain->fqdn);

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
        self::notTwoCharacters($domain->fqdn);

        return $this->locked($credentials, function (Session $session) use ($domain, $years, $credentials): DomainSyncResult {
            [$handles, $hosts] = self::prepare(function () use ($session, $domain, $years, $credentials) {
                Rules::years($years);

                return [self::ensureContacts($session, $domain, self::prefix($credentials)), self::ensureHosts($session, $domain->nameservers->pluck('host')->all(), $domain->fqdn)];
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

    /**
     * WRITE, charged and never recalled (G8-5a): its holder changed: «ownerNameChange» (the same holder's name) or
     * «ownerChange» (another holder), to a registry contact of ours made now with $holder's details (a registry contact's
     * name never changes: a new one each time, «{prefix}_mow<domain id, base 36>»). Ours and changeable first, and not
     * where the EETT forbids it; whatever fails before the update asked nothing that costs. 1000: done; 1001: awaiting the
     * EETT (geographic terms, .gov.gr).
     */
    public function changeOwner(Domain $domain, Contact $holder, string $kind, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        // Never a 2-character name from here: its EETT fee is not a TLD's price (review 2 of #215, as register and renew).
        self::notTwoCharacters($domain->fqdn);
        $op = match ($kind) {
            ChangesOwner::NAME => OwnerChange::NAME_CHANGE,
            ChangesOwner::OWNER => OwnerChange::OWNER_CHANGE,
            default => throw new RegistrarNotAsked("Not a change of holder: {$kind}."),
        };
        // Charged for nothing: the holder it has (ADV-4 of #215); a name change to the same name.
        $current = $domain->registrantContact;
        if ($current instanceof Contact && ($holder->is($current) || ($kind === ChangesOwner::NAME
            && self::sameName(self::draft('c00_x', $current)->local->name, self::draft('c00_x', $holder)->local->name)))) {
            throw new RegistrarNotAsked("{$domain->fqdn} already has this holder: nothing to change.");
        }

        return $this->locked($credentials, function (Session $session) use ($domain, $holder, $op, $kind, $credentials): DomainSyncResult {
            $id = self::prepare(function () use ($session, $domain, $holder, $op, $kind, $credentials) {
                // The same holder keeps its account (review 4: whether or not the domain has a registrant contact here).
                if ($kind === ChangesOwner::NAME && (int) $holder->client_id !== (int) $domain->client_id) {
                    throw new InvalidArgumentException("A name change keeps {$domain->fqdn}'s holder: a contact of another account is another holder (ownerChange).");
                }
                $info = self::ours($session, $domain, $credentials, writing: true);
                // One under way already (a registration, an update, an earlier change awaiting the EETT: review 5).
                if (array_intersect($info->statuses, ['pendingCreate', 'pendingUpdate']) !== []) {
                    throw new InvalidArgumentException("{$domain->fqdn} has a change under way at the registry (" . implode(', ', $info->statuses) . ').');
                }
                $forbidden = array_intersect($info->regulatorStatuses, ['regulatorUpdateProhibited', 'regulatorHold', 'regulatorDebtOwnership', 'pendingRegulatorApproval',
                    $op === OwnerChange::NAME_CHANGE ? 'regulatorOwnerNameChangeProhibited' : 'regulatorOwnerChangeProhibited']);
                if ($forbidden !== []) {
                    throw new InvalidArgumentException("The EETT does not allow it for {$domain->fqdn} now (" . implode(', ', $forbidden) . ').');
                }
                [$id, $there] = self::ownerContact($session, $domain, self::prefix($credentials), $holder);
                if (!$there) {
                    ContactCreate::parse(self::send($session, fn (string $clTRID) => ContactCreate::request(self::draft($id, $holder), $clTRID)));
                }

                return $id;
            });
            try {
                $result = self::charge(fn () => OwnerChange::parse(self::send($session, fn (string $clTRID) => OwnerChange::request($domain->fqdn, $id, $op, $clTRID))));
            } catch (RegistrarRefused $e) {
                // Refused: nothing done, so the contact made for it goes (free; ADV-5 of #215); never on «maybe done».
                try {
                    ContactDelete::parse(self::send($session, fn (string $clTRID) => ContactDelete::request($id, $clTRID)));
                } catch (Throwable) {
                    // left: an unused contact of ours, reused by the next try with the same details (ownerContact)
                }

                throw $e;
            }
            // Awaiting the EETT, the registry still holds the old holder: the new contact only in its details (ADV-6).
            $details = array_filter([
                'owner_change' => ($kind === ChangesOwner::NAME ? 'name change' : 'change of holder') . ($result->code === 1001 ? ': awaiting the EETT' : ': done'),
                'owner_contact' => $id,
                'comments' => implode(' ', $result->comments) ?: null,
            ]);
            // The registry's record after it, as a sync would read it (review 3: never a partial record applied over the
            // domain's details; awaiting the EETT, the registry still holds the old holder).
            try {
                $after = self::syncResultFrom(self::read($session, $domain));

                return new DomainSyncResult($after->expiresAt, $after->nameservers, $after->registrarDomainId, $after->status, $after->rawStatus,
                    $after->contactHandles, false, null, null, $after->registeredAt, $details + $after->details, $after->graceEndsOn, $after->redemptionEndsOn);
            } catch (Throwable) {
                return new DomainSyncResult(details: $details); // not read back: the next sync reads it
            }
        });
    }

    /**
     * The registry contact for a new holder, ours: «{prefix}_mow<domain id, base 36>», a letter added while taken; one
     * already there with the holder's name and email (made by a try whose answer was lost: review 6) is used, not made
     * again. Its details checked (draft) before any id is asked.
     *
     * @return array{0: string, 1: bool} [its id, whether it is there already]
     */
    private static function ownerContact(Session $session, Domain $domain, string $prefix, Contact $holder): array
    {
        $base = "{$prefix}_mow" . base_convert((string) $domain->getKey(), 10, 36);
        $draft = self::draft($base, $holder);
        foreach (array_chunk(array_merge([''], range('a', 'z')), ContactCheck::MAX) as $suffixes) {
            $ids = array_map(fn (string $s) => Rules::contactId($base . $s, $prefix), $suffixes);
            $check = array_change_key_case(ContactCheck::parse(self::send($session, fn (string $clTRID) => ContactCheck::request($ids, $clTRID))));
            foreach ($ids as $id) {
                $found = $check[strtolower($id)] ?? throw new InvalidArgumentException("The registry's check did not answer for contact {$id}.");
                if ($found->available) {
                    return [$id, false];
                }
                $info = ContactInfo::parse(self::send($session, fn (string $clTRID) => ContactInfo::request($id, $clTRID)));
                $held = $info->postalInfo['loc'] ?? $info->postalInfo['int'] ?? null;
                if ($held !== null && self::sameName($held->name, $draft->local->name) && strcasecmp(trim((string) $info->email), trim($draft->email)) === 0) {
                    return [$id, true];
                }
            }
        }
        throw new InvalidArgumentException("No free contact id for {$domain->fqdn}'s new holder.");
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
    /** @param  list<string>  $roles  its roles to ensure (an update: never the registrant, an owner change) */
    private static function ensureContacts(Session $session, Domain $domain, string $prefix, array $roles = Domain::ROLES): array
    {
        $handles = [];
        $made = []; // contact id => registry id: one contact in several roles is checked once
        foreach ($roles as $role) {
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
                $check = array_change_key_case(ContactCheck::parse(self::send($session, fn (string $clTRID) => ContactCheck::request([$id], $clTRID))));
                // Its own case or another (review 5 of #192); none at all: not «exists», never asked.
                $found = $check[strtolower($id)] ?? throw new InvalidArgumentException("The registry's check did not answer for contact {$id}.");
                $draft = self::draft($id, $contact);
                if ($found->available) {
                    ContactCreate::parse(self::send($session, fn (string $clTRID) => ContactCreate::request($draft, $clTRID)));
                } else {
                    // There already (one whose answer was lost, or another instance's, a reset database's: ADV-1 of
                    // #192): read, and used only if it is this contact (its name, which the registry never changes).
                    $info = ContactInfo::parse(self::send($session, fn (string $clTRID) => ContactInfo::request($id, $clTRID)));
                    $held = $info->postalInfo['loc'] ?? $info->postalInfo['int'] ?? null;
                    // Its name and its email (ADV-7 of #195: the same name alone may be another person's contact).
                    if ($held === null || !self::sameName($held->name, $draft->local->name)
                        || strcasecmp(trim((string) $info->email), trim($draft->email)) !== 0) {
                        throw new InvalidArgumentException("The registry's contact {$id} holds another name or email than this contact's: not used. Staff decide (a new contact here, or the registry's corrected).");
                    }
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
    /** The same name, whatever its spacing, case or Unicode form. */
    private static function sameName(string $a, string $b): bool
    {
        $norm = fn (string $s) => mb_strtolower((string) preg_replace('/\s+/u', ' ', trim(\Normalizer::normalize($s, \Normalizer::FORM_C) ?: $s)));

        return $norm($a) === $norm($b);
    }

    private static function draft(string $id, Contact $contact): ContactDraft
    {
        $name = trim($contact->first_name . ' ' . $contact->last_name) ?: trim((string) $contact->company_name);
        $voice = Phone::epp($contact->phone, $contact->country) ?? throw new InvalidArgumentException("The contact {$id} has no phone: the registry needs one.");

        return new ContactDraft($id,
            new PostalInfo('loc', $name, trim((string) $contact->company_name) ?: null, array_values(array_filter([trim((string) $contact->address1), trim((string) $contact->address2)])),
                (string) $contact->city, trim((string) $contact->state) ?: (string) $contact->city, (string) $contact->postcode, (string) $contact->country),
            null, $voice, (string) $contact->email, hidden: null);
    }

    /**
     * Its nameservers, existing at the registry (a domain's must): an external one missing is created (no address); one
     * under .gr/.ελ missing needs its glue (IPs), made by staff first.
     *
     * @param  list<string>  $hosts
     * @return list<string>
     */
    private static function ensureHosts(Session $session, array $hosts, ?string $fqdn = null): array
    {
        $hosts = Rules::nameservers($hosts);
        foreach (array_chunk($hosts, HostCheck::MAX) as $batch) {
            $check = HostCheck::parse(self::send($session, fn (string $clTRID) => HostCheck::request($batch, $clTRID)));
            foreach ($batch as $host) {
                if (!($check[$host]->available ?? false)) {
                    continue; // registered there already
                }
                if (HostCreate::internal($host)) {
                    // Under this domain: ours to make (its child nameservers); under another: its own registrar's (G6).
                    $own = $fqdn !== null ? Rules::name($fqdn) : null;
                    throw new InvalidArgumentException($own !== null && $host !== $own && !str_ends_with($host, ".{$own}")
                        ? "The nameserver {$host} is not at the .gr registry: the registrar of " . self::registeredName($host) . ' must register it (with its IP) first.'
                        : "The nameserver {$host} is not at the registry: create it with its IP (glue) first (Child nameservers).");
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
        } catch (InvalidArgumentException|GrEppException|DomainRegistrarNotConfigured $e) {
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
            throw new RegistrarOutcomeUnknown($e->getMessage(), 0, $e);
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

    /**
     * WRITE (G5b-2): a name moved to us, by its code (the registrant's, from DACoR or its registrar). The registrant goes
     * into a contact id of ours that does not exist yet (the registry copies it as it holds it: the owner's name never
     * changes in a transfer); approved at once, its nameservers kept, no period added (free at the .gr registry). Then
     * its admin, tech and billing (the other registrar's, e.g. 533_) replaced by ours from the domain's contacts: after
     * the transfer, so a failure there never undoes it (kept in its details for staff; the next change retries it).
     */
    public function transferIn(Domain $domain, string $authCode, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        self::notTwoCharacters($domain->fqdn);

        return $this->locked($credentials, function (Session $session) use ($domain, $authCode, $credentials) {
            $registrant = self::prepare(function () use ($session, $domain, $authCode, $credentials) {
                if (trim($authCode) === '') {
                    throw new InvalidArgumentException('A .gr transfer needs the name\'s authorisation code.');
                }

                return self::freshContactId($session, $domain, self::prefix($credentials));
            });
            $result = self::charge(fn () => DomainTransfer::parse(self::send($session,
                fn (string $clTRID) => DomainTransfer::request($domain->fqdn, $authCode, $registrant, $clTRID))));
            Domain::query()->whereKey($domain->getKey())->update(['registrant_handle' => $registrant]);
            $domain->forceFill(['registrant_handle' => $registrant])->syncOriginalAttribute('registrant_handle');

            $details = array_filter(['transfer' => $result->transferStatus, 'comments' => implode(' ', $result->comments) ?: null]);
            // Its roles replaced only once it is ours (approved: review 4 of #198); a pending one keeps them for later.
            if (!in_array($result->transferStatus, ['serverApproved', 'clientApproved'], true)) {
                $details['contacts'] = 'to replace once the transfer completes';
            } else {
                try {
                    $details['contacts'] = self::replaceRoles($session, $domain, self::prefix($credentials), $credentials);
                } catch (OutcomeUnknown|RegistrarOutcomeUnknown $e) {
                    $details['contacts'] = 'maybe replaced (no answer): ' . mb_substr($e->getMessage(), 0, 300); // review 5
                } catch (Throwable $e) {
                    $details['contacts'] = 'not replaced: ' . mb_substr($e->getMessage(), 0, 300); // done all the same
                }
            }
            // The owner as the registry holds it, against this domain's registrant contact (review 6: shown, never changed).
            try {
                $held = ContactInfo::parse(self::send($session, fn (string $clTRID) => ContactInfo::request($registrant, $clTRID)));
                $local = $domain->registrantContact;
                if ($local instanceof Contact && strcasecmp(trim((string) $held->email), trim((string) $local->email)) !== 0) {
                    $details['registrant'] = 'the registry\'s owner (copied) differs from this domain\'s registrant contact here: check it';
                }
            } catch (Throwable) {
            }
            try {
                $after = self::syncResultFrom(self::read($session, $domain));

                return new DomainSyncResult($after->expiresAt, $after->nameservers, $after->registrarDomainId, $after->status, $after->rawStatus,
                    $after->contactHandles, false, null, null, $after->registeredAt, $details + $after->details, $after->graceEndsOn, $after->redemptionEndsOn);
            } catch (Throwable) {
                // Not read back: as its answer said (ADV-2 of #198: a pending transfer is never Active); the next sync reads it.
                $approved = in_array($result->transferStatus, ['serverApproved', 'clientApproved'], true);

                return new DomainSyncResult(status: $approved ? DomainStatus::Active : null, rawStatus: $approved ? 'ok' : 'pendingTransfer', details: $details);
            }
        });
    }

    /**
     * A contact id of ours the registry does not have yet, for a transferred name's registrant: «{prefix}_mtr<domain
     * id, base 36>», a letter added while it is taken (a contact made by an earlier transfer of the same domain); checked
     * three at a time.
     */
    private static function freshContactId(Session $session, Domain $domain, string $prefix): string
    {
        // «mtr», never «mcs…»: a contact's own ids are «mcs<id base 36>» (review 1 of #198: «mcst1» was contact 1045's).
        $base = "{$prefix}_mtr" . base_convert((string) $domain->getKey(), 10, 36);
        foreach (array_chunk(array_merge([''], range('a', 'z')), ContactCheck::MAX) as $suffixes) {
            $ids = array_map(fn (string $s) => Rules::contactId($base . $s, $prefix), $suffixes);
            $check = array_change_key_case(ContactCheck::parse(self::send($session, fn (string $clTRID) => ContactCheck::request($ids, $clTRID))));
            foreach ($ids as $id) {
                // No answer for an id asked is no answer, never «taken» (ADV-4 of #198, as ensureContacts).
                $found = $check[strtolower($id)] ?? throw new InvalidArgumentException("The registry's check did not answer for contact {$id}.");
                if ($found->available) {
                    return $id;
                }
            }
        }
        throw new InvalidArgumentException("No free contact id for {$domain->fqdn}'s registrant.");
    }

    /**
     * After a transfer: its admin, tech and billing set to ours (from the domain's contacts), the registry's others on them
     * (the previous registrar's) removed. A role without a contact here keeps what it has.
     */
    private static function replaceRoles(Session $session, Domain $domain, string $prefix, DomainRegistrarCredentials $credentials): string
    {
        // Before the update: whatever fails is «not replaced» (a read, a contact); only the update's lost answer is «maybe».
        [$add, $remove] = self::prepare(function () use ($session, $domain, $prefix, $credentials) {
            $info = self::ours($session, $domain, $credentials, writing: true); // ours and updatable first (review 4)
            $add = $remove = [];
            foreach (self::ensureContacts($session, $domain, $prefix, ['admin', 'tech', 'billing']) as $role => $ours) {
                $there = $info->contacts[$role] ?? [];
                $add[$role] = in_array(strtolower($ours), array_map('strtolower', $there), true) ? [] : [$ours];
                $remove[$role] = array_values(array_filter($there, fn (string $id) => strtolower($id) !== strtolower($ours)));
            }

            return [$add, $remove];
        });
        if (array_filter($add) === [] && array_filter($remove) === []) {
            return 'ours already';
        }
        DomainUpdate::parse(self::send($session, fn (string $clTRID) => DomainUpdate::request($domain->fqdn, $clTRID, [], [], $add, $remove)));

        return 'replaced';
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

    /**
     * WRITE (G5b-1): its nameservers (a full set: what is missing added, what is extra removed; external hosts made, an
     * internal one needs its glue first) and the contacts of its admin, tech and billing roles (ours made from the
     * domain's contacts; the registry's others on those roles removed: another registrar's `533_`, the old module's).
     * Never the registrant: changing it is an owner change, charged (G8). Read fresh first: the change is what differs
     * from the registry, never an empty update. No transfer lock or WHOIS privacy at the .gr registry.
     */
    public function updateDomain(Domain $domain, DomainChanges $changes, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        if ($changes->transferLock !== null || $changes->whoisPrivacy !== null) {
            throw new RegistrarNotAsked('The .gr registry has no transfer lock or WHOIS privacy.');
        }
        if ($changes->isEmpty()) {
            throw new RegistrarNotAsked('Nothing to change.');
        }

        return $this->locked($credentials, function (Session $session) use ($domain, $changes, $credentials) {
            [$info, $addHosts, $removeHosts, $addContacts, $removeContacts] = self::prepare(function () use ($session, $domain, $changes, $credentials) {
                $info = self::read($session, $domain);
                // Ours and updatable, before anything is made (ADV-1 of #195): another registrar's, or one the registry
                // will not change now, gets no host, no contact and no update.
                if ($info->clientId !== null && strcasecmp($info->clientId, self::registrarId($credentials)) !== 0) {
                    throw new InvalidArgumentException("{$domain->fqdn} is managed by another registrar at the registry ({$info->clientId}).");
                }
                $blocked = array_intersect($info->statuses, ['serverUpdateProhibited', 'clientUpdateProhibited', 'pendingTransfer', 'pendingDelete']);
                if ($blocked !== []) {
                    throw new InvalidArgumentException("{$domain->fqdn} cannot be changed at the registry now (" . implode(', ', $blocked) . ').');
                }
                $addHosts = $removeHosts = [];
                if ($changes->nameservers !== null) {
                    if ($changes->nameservers === []) {
                        throw new InvalidArgumentException('A domain is never left without nameservers.'); // ADV-2 of #195
                    }
                    $wanted = self::ensureHosts($session, $changes->nameservers, $domain->fqdn);
                    // The registry's as ours (Rules::name: case, a trailing dot, xn--, NFC: review 5 of #195), its own
                    // spelling kept for the removal.
                    $current = [];
                    foreach ($info->nameservers as $host) {
                        try {
                            $current[Rules::name($host)] = $host;
                        } catch (InvalidArgumentException) {
                            $current[strtolower($host)] = $host;
                        }
                    }
                    $addHosts = array_values(array_diff($wanted, array_keys($current)));
                    $removeHosts = array_values(array_intersect_key($current, array_flip(array_diff(array_keys($current), $wanted))));
                }
                $addContacts = $removeContacts = [];
                if ($changes->applyContacts) {
                    // The registry keeps a contact on a role: one cleared here is not cleared there (review 3 of #195).
                    foreach (['admin', 'tech', 'billing'] as $role) {
                        if ($domain->{"{$role}_contact_id"} === null && ($info->contacts[$role] ?? []) !== []) {
                            throw new InvalidArgumentException("The .gr registry keeps a {$role} contact: choose another contact instead of none.");
                        }
                    }
                    // A role linked to another registrar's contact here, not changed (its handle kept), stays as it is: only
                    // what was asked is changed (ADV-6 of #195); G8's cleanup replaces those on purpose.
                    $prefix = self::prefix($credentials);
                    $roles = array_values(array_filter(['admin', 'tech', 'billing'], function (string $role) use ($domain, $prefix) {
                        $handle = trim((string) $domain->{"{$role}_handle"});

                        return $handle === '' || stripos($handle, "{$prefix}_") === 0;
                    }));
                    foreach (self::ensureContacts($session, $domain, $prefix, $roles) as $role => $ours) {
                        $there = $info->contacts[$role] ?? [];
                        $has = in_array(strtolower($ours), array_map('strtolower', $there), true);
                        $addContacts[$role] = $has ? [] : [$ours];
                        $removeContacts[$role] = array_values(array_filter($there, fn (string $id) => strtolower($id) !== strtolower($ours)));
                    }
                }

                return [$info, $addHosts, $removeHosts, $addContacts, $removeContacts];
            });
            if ($addHosts === [] && $removeHosts === [] && array_filter($addContacts) === [] && array_filter($removeContacts) === []) {
                return self::syncResultFrom($info); // as asked already: nothing sent
            }
            self::charge(fn () => DomainUpdate::parse(self::send($session, fn (string $clTRID) => DomainUpdate::request($domain->fqdn, $clTRID,
                $addHosts, $removeHosts, $addContacts, $removeContacts))));
            try {
                return self::syncResultFrom(self::read($session, $domain));
            } catch (Throwable) {
                // Done, not read back: what it was with what was changed, never a made-up status (review 2 of #195).
                $before = self::syncResultFrom($info);
                $nameservers = $changes->nameservers !== null ? Rules::nameservers($changes->nameservers) : $before->nameservers;

                return new DomainSyncResult($before->expiresAt, $nameservers, $before->registrarDomainId, $before->status, $before->rawStatus,
                    $before->contactHandles, false, null, null, $before->registeredAt, $before->details, $before->graceEndsOn, $before->redemptionEndsOn);
            }
        });
    }

    /**
     * The account's balance (G8-1, account:info): negative too; whether chargeable actions are allowed and when they will
     * be, or were, suspended (then every registration and renewal stops). A read.
     */
    public function accountBalance(DomainRegistrarCredentials $credentials): RegistrarBalance
    {
        // The shop's short session (5 seconds to wait for the account, 5 to answer: review 6 of #206): staff's «Read
        // balance» is a web request, the sync may hold the account; busy, it is read again the next hour.
        $info = $this->locked($credentials, fn (Session $session) => AccountInfo::parse(self::send($session, fn (string $clTRID) => AccountInfo::request($clTRID))), shop: true);

        return new RegistrarBalance(amount: $info->balance, currency: $info->currency !== '' ? $info->currency : null,
            chargesAllowed: $info->chargeableActionsAllowed,
            suspendsOn: $info->toBeSuspendedOn !== null ? Carbon::instance($info->toBeSuspendedOn) : null,
            suspendedOn: $info->suspendedOn !== null ? Carbon::instance($info->suspendedOn) : null);
    }

    /** DNSSEC (G8-4b): DS records only (the registry's DNSSEC guide v1.5, grepp.md §5.14). */
    public function dnssecKind(): string
    {
        return DnssecRecord::DS;
    }

    /** Its DS records as the registry has them (domain:info). A read. */
    public function dnssecRecords(Domain $domain, DomainRegistrarCredentials $credentials): array
    {
        return $this->locked($credentials, fn (Session $session) => self::prepare(fn () => array_map(
            fn (DsRecord $ds) => DnssecRecord::ds($ds->keyTag, $ds->algorithm, $ds->digestType, $ds->digest),
            self::ours($session, $domain, $credentials)->dsRecords,
        )));
    }

    /**
     * WRITE (DNSSEC): its DS records set to these: the extra ones removed (all of them when none is wanted), the missing
     * ones added, in one update. Added ones are checked by the registry against its nameservers (every 20 minutes, the
     * domain serverUpdateProhibited until then; one that does not match a signed DNSKEY there refuses them all). Not
     * charged.
     */
    public function setDnssec(Domain $domain, array $records, DomainRegistrarCredentials $credentials, ?array $expected = null): ?string
    {
        return $this->locked($credentials, function (Session $session) use ($domain, $records, $credentials, $expected) {
            [$add, $remove] = self::prepare(function () use ($session, $domain, $records, $credentials, $expected) {
                $wanted = array_map(function (DnssecRecord $record) {
                    if ($record->kind !== DnssecRecord::DS) {
                        throw new InvalidArgumentException('The .gr registry takes DS records only, not DNSKEYs.');
                    }

                    return new DsRecord((int) $record->keyTag, $record->algorithm, (int) $record->digestType, (string) $record->digest);
                }, $records);
                $wanted = Rules::dsRecords($wanted); // its rules before anything is read
                $info = self::ours($session, $domain, $credentials, writing: true);
                if ($wanted !== [] && $info->nameservers === []) {
                    throw new InvalidArgumentException("{$domain->fqdn} has no nameservers: DS records need them (the registry refuses).");
                }
                $have = $info->dsRecords;
                // Changed since staff read it (ADV-1 of #210): never a DS they did not see removed.
                if ($expected !== null && !DnssecRecord::sameSet($expected, array_map(fn (DsRecord $d) => DnssecRecord::ds($d->keyTag, $d->algorithm, $d->digestType, $d->digest), $have))) {
                    throw new DnssecChangedSinceRead("{$domain->fqdn}'s DS records changed at the registry since they were read: read them again.");
                }
                $add = array_values(array_filter($wanted, fn (DsRecord $w) => array_filter($have, fn (DsRecord $h) => $h->same($w)) === []));
                $extra = array_values(array_filter($have, fn (DsRecord $h) => array_filter($wanted, fn (DsRecord $w) => $w->same($h)) === []));

                return [$add, $wanted === [] && $have !== [] ? true : $extra];
            });
            if ($add === [] && $remove === []) {
                return null; // as asked already: nothing sent
            }
            $result = self::charge(fn () => DomainSecDns::parse(self::send($session, fn (string $clTRID) => DomainSecDns::request($domain->fqdn, $clTRID, $add, $remove))));

            return $add !== [] ? 'The registry checks the DS records against the nameservers within 20 minutes (the domain is locked for changes until then); all are refused if one does not match a signed DNSKEY there.'
                . ($result->comments !== [] ? ' ' . implode(' ', $result->comments) : '') : null;
        });
    }

    /** At most this many child hosts read (one host:info each). */
    private const CHILD_HOSTS_READ = 30;

    /**
     * The domain's child nameservers as the registry has them (domain:info's hosts, then host:info each): the forgotten
     * ones too. Reads only.
     */
    public function childHosts(Domain $domain, DomainRegistrarCredentials $credentials): array
    {
        // A read: whatever fails is not «maybe done» (review 4 of #196). Every host listed; past the limit, or one whose read
        // the registry refuses, listed unread (taken as in use: never offered for deletion: review 2).
        return $this->locked($credentials, fn (Session $session) => self::prepare(function () use ($session, $domain, $credentials) {
            $hosts = [];
            $info = self::ours($session, $domain, $credentials);
            // The domain's own nameservers are in use whatever host:info says (ADV-3b of #196: «linked» not relied on).
            $own = array_map('strtolower', $info->nameservers);
            foreach ($info->hosts as $i => $name) {
                if ($i >= self::CHILD_HOSTS_READ) {
                    $hosts[] = new ChildHost($name, [], true, read: false);

                    continue;
                }
                try {
                    $host = HostInfo::parse(self::send($session, fn (string $clTRID) => HostInfo::request($name, $clTRID)));
                    $hosts[] = new ChildHost($host->name, array_column($host->addresses, 'ip'),
                        in_array('linked', $host->statuses, true) || in_array(strtolower($host->name), $own, true));
                } catch (Refused) {
                    $hosts[] = new ChildHost($name, [], true, read: false);
                }
            }

            return $hosts;
        }));
    }

    /** WRITE (child hosts): a nameserver under the domain, with its glue. Not charged. */
    public function createChildHost(Domain $domain, string $host, array $addresses, DomainRegistrarCredentials $credentials): void
    {
        $this->locked($credentials, function (Session $session) use ($domain, $host, $addresses, $credentials) {
            [$host, $addresses] = self::prepare(function () use ($session, $domain, $host, $addresses, $credentials) {
                $host = self::childOf($domain, $host);
                $addresses = self::glue($host, $addresses);
                self::ours($session, $domain, $credentials, writing: true);

                return [$host, $addresses];
            });
            self::charge(fn () => HostCreate::parse(self::send($session, fn (string $clTRID) => HostCreate::request($host, $addresses, $clTRID))));
        });
    }

    /** WRITE (child hosts): its glue set to these addresses (what is missing added, what is extra removed). */
    public function updateChildHost(Domain $domain, string $host, array $addresses, DomainRegistrarCredentials $credentials): void
    {
        $this->locked($credentials, function (Session $session) use ($domain, $host, $addresses, $credentials) {
            [$host, $add, $remove] = self::prepare(function () use ($session, $domain, $host, $addresses, $credentials) {
                $host = self::childOf($domain, $host);
                $wanted = self::glue($host, $addresses);
                self::ours($session, $domain, $credentials, writing: true);
                $current = array_column(self::ourHost($session, $host, $credentials)->addresses, 'ip');
                $key = fn (string $ip): string => (string) inet_pton($ip);
                $have = array_combine(array_map($key, $current), $current) ?: [];
                $want = array_combine(array_map($key, $wanted), $wanted) ?: [];

                return [$host, array_values(array_diff_key($want, $have)), array_values(array_diff_key($have, $want))];
            });
            if ($add === [] && $remove === []) {
                return; // as asked already: nothing sent
            }
            self::charge(fn () => HostUpdate::parse(self::send($session, fn (string $clTRID) => HostUpdate::request($host, $add, $remove, $clTRID))));
        });
    }

    /** WRITE (child hosts): one no domain uses; a linked one is refused before anything is sent. */
    public function deleteChildHost(Domain $domain, string $host, DomainRegistrarCredentials $credentials): void
    {
        $this->locked($credentials, function (Session $session) use ($domain, $host, $credentials) {
            $host = self::prepare(function () use ($session, $domain, $host, $credentials) {
                $host = self::childOf($domain, $host);
                $domainInfo = self::ours($session, $domain, $credentials, writing: true);
                $info = self::ourHost($session, $host, $credentials);
                // In use: «linked», or the domain's own nameserver (ADV-3 of #196: «linked» not relied on alone).
                if (in_array('linked', $info->statuses, true) || in_array($host, array_map('strtolower', $domainInfo->nameservers), true)) {
                    throw new InvalidArgumentException("{$host} is a nameserver of a domain: change that domain's nameservers first.");
                }

                return $host;
            });
            self::charge(fn () => HostDelete::parse(self::send($session, fn (string $clTRID) => HostDelete::request($host, $clTRID))));
        });
    }

    /** The registered name a host is under: ns1.example.com.gr → example.com.gr (com, net, org, edu, gov.gr: review 3 of #197). */
    private static function registeredName(string $host): string
    {
        $labels = explode('.', $host);
        $second = count($labels) >= 3 && in_array($labels[count($labels) - 2], ['com', 'net', 'org', 'edu', 'gov'], true);

        return implode('.', array_slice($labels, $second ? -3 : -2));
    }

    /** The domain read fresh, ours at the registry (its clID): another registrar's hosts are never touched. */
    private static function ours(Session $session, Domain $domain, DomainRegistrarCredentials $credentials, bool $writing = false): Info
    {
        $info = self::read($session, $domain);
        if ($info->clientId !== null && strcasecmp($info->clientId, self::registrarId($credentials)) !== 0) {
            throw new InvalidArgumentException("{$domain->fqdn} is managed by another registrar at the registry ({$info->clientId}).");
        }
        // A write under a domain being transferred, deleted or locked: none (ADV-5 of #196, as updateDomain).
        $blocked = $writing ? array_intersect($info->statuses, ['serverUpdateProhibited', 'clientUpdateProhibited', 'pendingTransfer', 'pendingDelete']) : [];
        if ($blocked !== []) {
            throw new InvalidArgumentException("{$domain->fqdn} cannot be changed at the registry now (" . implode(', ', $blocked) . ').');
        }

        return $info;
    }

    /** A host read fresh, ours at the registry (its clID: ADV-4 of #196; another registrar's left under our domain is not). */
    private static function ourHost(Session $session, string $host, DomainRegistrarCredentials $credentials): HostData
    {
        $info = HostInfo::parse(self::send($session, fn (string $clTRID) => HostInfo::request($host, $clTRID)));
        if ($info->clientId !== null && strcasecmp($info->clientId, self::registrarId($credentials)) !== 0) {
            throw new InvalidArgumentException("{$host} is another registrar's at the registry ({$info->clientId}).");
        }

        return $info;
    }

    /** A host under this domain (ns1, or ns1.example.gr), as the registry spells it. */
    private static function childOf(Domain $domain, string $host): string
    {
        $host = trim($host);
        $fqdn = Rules::name($domain->fqdn);
        $name = Rules::name(str_contains($host, '.') ? $host : "{$host}.{$fqdn}");
        if (!str_ends_with($name, ".{$fqdn}")) {
            throw new InvalidArgumentException("{$name} is not under {$fqdn}.");
        }
        // A host name: letters, digits and inner hyphens per label, 1 to 63 (ADV-2 of #196: refused here, nothing sent).
        $ascii = idn_to_ascii($name, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_CONTEXTJ | IDNA_CHECK_BIDI, INTL_IDNA_VARIANT_UTS46);
        foreach (explode('.', $ascii === false ? $name : $ascii) as $label) {
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/i', $label) !== 1) {
                throw new InvalidArgumentException("Not a host name: {$name}");
            }
        }

        return $name;
    }

    /** Ranges PHP's filter lets through that are never a nameserver's (ADV-1 of #196): multicast, documentation, CGNAT … */
    private const NOT_GLUE = ['224.0.0.0/4', '100.64.0.0/10', '192.0.2.0/24', '198.51.100.0/24', '203.0.113.0/24', '198.18.0.0/15',
        'ff00::/8', '2001:db8::/32', 'fec0::/10'];

    private static function notGlue(string $ip): bool
    {
        $address = inet_pton($ip);
        foreach (self::NOT_GLUE as $range) {
            [$network, $bits] = explode('/', $range);
            $net = inet_pton($network);
            if ($address === false || $net === false || strlen($address) !== strlen($net)) {
                continue;
            }
            $bytes = intdiv((int) $bits, 8);
            $rest = (int) $bits % 8;
            if (substr($address, 0, $bytes) === substr($net, 0, $bytes)
                && ($rest === 0 || ((ord($address[$bytes]) ^ ord($net[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Its glue: one address at least, public ones only (a private or reserved address is never a nameserver's).
     *
     * @param  list<string>  $addresses
     * @return list<string>
     */
    private static function glue(string $host, array $addresses): array
    {
        $addresses = array_values(array_filter(array_map('trim', $addresses), fn (string $ip) => $ip !== ''));
        if ($addresses === []) {
            throw new InvalidArgumentException("{$host} needs one IP address at least.");
        }
        foreach ($addresses as $i => $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false || self::notGlue($ip)) {
                throw new InvalidArgumentException("Not a public IP address: {$ip}");
            }
            $addresses[$i] = (string) inet_ntop((string) inet_pton($ip)); // one spelling: 2A01:04F8:0::1 is 2a01:4f8::1 (review 1)
        }
        $addresses = array_values(array_unique($addresses));
        HostCreate::addresses($host, $addresses); // the registry's own rules (v4/v6, at most 16)

        return $addresses;
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
            // Nothing sent (review 2 of #192): never «maybe done».
            throw new RegistrarNotAsked('The .gr registry account is busy with another command: try again in a minute.');
        } catch (NotSent $e) {
            throw new RegistrarNotAsked($e->getMessage(), 0, $e);
        } catch (GrEppException $e) {
            throw $e instanceof Refused ? $e : new RegistrarOutcomeUnknown($e->getMessage(), 0, $e);
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
    /** @param  (Closure(): bool)|null  $debug  whether its frames go to the debug log now */
    private static function transport(bool $shop = false, ?Closure $debug = null): Transport
    {
        $transport = self::$transport !== null ? (self::$transport)() : new CurlTransport($shop ? 5 : 60, $shop ? 3 : 10);
        if (!$transport instanceof Transport) {
            throw new RuntimeException('The .gr transport seam must give a Transport.');
        }

        // Each exchange's ids on the call's log, always; its frames (secrets hidden) only with the connection's debug log.
        $debug ??= fn (): bool => false;

        // The switch read at each exchange (review 4 of #193: turning it on or off never opens another session).
        return new TracingTransport($transport, function (array $summary, Closure $frames, float $ms) use ($debug): void {
            app(RegistrarTransactions::class)->record($summary);
            if ($debug()) {
                [$request, $response] = $frames();
                $outcome = $summary['code'] ?? ($summary['http'] === 0 ? 'no answer' : "HTTP {$summary['http']}");
                Log::channel('registrar-debug')->debug("grepp {$summary['command']} {$outcome} ({$ms} ms)", $summary + ['request' => $request, 'response' => $response]);
            }
        });
    }

    private static function session(Config $config, DomainRegistrarCredentials $credentials, bool $shop = false): Session
    {
        $key = sha1($config->endpoint . '|' . $config->clientId . '|' . hash('sha256', (string) $credentials->get('password')) . ($shop ? '|shop' : ''));
        self::$debug[$key] = $credentials->debug;

        return self::$sessions[$key] ??= new Session($config, self::transport($shop, fn (): bool => self::$debug[$key] ?? false));
    }

    /** End and forget the sessions: at the end of each request or command (AppServiceProvider), in tests (review 6). */
    public static function reset(): void
    {
        foreach (self::$sessions as $session) {
            $session->logout();
        }
        self::$sessions = [];
        self::$debug = [];
    }
}
