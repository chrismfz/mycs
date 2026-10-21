<?php

namespace App\Services\Domains\Registrars;

use App\Contracts\DomainRegistrar;
use App\Contracts\ReadsAccountBalance;
use App\Contracts\UpdatesContacts;
use App\Enums\DomainStatus;
use App\Models\Contact;
use App\Models\Domain;
use App\Services\Domains\DomainNotFoundAtRegistrar;
use App\Services\Domains\DomainRegistrarNotConfigured;
use App\Services\Domains\RegistrarNotAsked;
use App\Services\Domains\RegistrarOutcomeUnknown;
use App\Services\Domains\RegistrarRefused;
use App\Support\Domains\AvailabilityResult;
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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use MyCS\Cnic\Command;
use MyCS\Cnic\Response;
use RuntimeException;
use Throwable;

/**
 * CentralNic Reseller (fka RRPproxy; ROADMAP «CNIC», owner 2026-10-21): myip's 198 domains there, some paid to 2034, so a
 * registrar of its own, not «moved at their renewal» only. Its API is packages/cnic (written from its public command
 * reference and the MIT php-sdk's wire format; the WHMCS module is ionCube and closed: nothing of it).
 *
 * Step 1, reads: the connection's ping and balance, availability, a domain's sync (its dates, nameservers, lock,
 * contacts), the account's list and contacts, its EPP code.
 * Step 2, management (nothing charged): its nameservers, its transfer lock, its contacts' details (address, phone, email,
 * never who they are) and which contact has each role (its handle made where none; never another owner through the API).
 * Step 3, its renewal (RenewDomain, through DomainRenewalService's locks and checks). A registration, transfer or restore
 * is not asked (RegistrarNotAsked: nothing sent, nothing charged).
 */
final class CnicRegistrar implements DomainRegistrar, ReadsAccountBalance, UpdatesContacts
{
    /** What staff see of its record: never its auth code. */
    private const DETAIL_KEYS = ['STATUS', 'RGP_STATUS', 'RENEWALMODE', 'TRANSFERMODE', 'PAIDUNTIL_DATE', 'REGISTRATION_EXPIRATION_DATE', 'RENEWAL_DATE',
        'CREATED_DATE', 'UPDATED_DATE', 'ZONE', 'X-WHOISPRIVACY', 'X-TRANSFERLOCK-UNTIL', 'X-REGISTRY-LOCK', 'X-TIME-TO-SUSPENSION'];

    private const ROLES = ['registrant' => 'OWNER_CONTACT', 'admin' => 'ADMIN_CONTACT', 'tech' => 'TECH_CONTACT', 'billing' => 'BILLING_CONTACT'];

    public function key(): string
    {
        return 'cnic';
    }

    public function capabilities(): DomainRegistrarCapabilities
    {
        // Managed (step 2), nothing sold, registered or renewed through it yet; its expiry is the day the name goes there
        // (reviews of #297). No WHOIS privacy, no DNSSEC, no glue hosts through it.
        // Its renewal (step 3): RenewDomain while it is active there. Never «renewed in grace»: ours is the day it goes there,
        // so our grace is its deletion or redemption, a restore staff make, its grace fee billed (code review of #302).
        return new DomainRegistrarCapabilities(supportsPortfolioImport: true, supportsTransferLock: true,
            supportsRegistration: false, supportsRenewal: true, supportsManagement: true, expiryIsWhenItGoes: true);
    }

    /** Contract: never throws — false on missing credentials, a refused login or no answer. */
    public function ping(DomainRegistrarCredentials $credentials): bool
    {
        try {
            return $this->call($credentials, ['COMMAND' => 'StatusAccount'])->success();
        } catch (Throwable) {
            return false;
        }
    }

    public function accountBalance(DomainRegistrarCredentials $credentials): RegistrarBalance
    {
        $r = $this->read($credentials, ['COMMAND' => 'StatusAccount']);

        // Its balance is AMOUNT, as its account page shows it (myip's, 2026-10-21: 42.85 EUR); CREDIT is a credit line on top
        // (0 there), not the balance as its documentation reads.
        return new RegistrarBalance(amount: (string) ($r->first('AMOUNT') ?? '0'), currency: $r->first('CURRENCY'));
    }

    public function checkAvailability(string $fqdn, DomainRegistrarCredentials $credentials): AvailabilityResult
    {
        $r = $this->read($credentials, ['COMMAND' => 'CheckDomains', 'DOMAIN0' => mb_strtolower($fqdn)]);
        $check = (string) ($r->first('DOMAINCHECK') ?? '');

        // Its premium price is not asked (no X-FEE-*): nothing is sold through it (reviews of #297).
        return new AvailabilityResult($fqdn, str_starts_with($check, '210'), $check !== '' ? $check : null);
    }

    public function syncDomain(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        $r = $this->read($credentials, ['COMMAND' => 'StatusDomain', 'DOMAIN' => $domain->fqdn]);

        return $this->result($r);
    }

    public function getTldPricing(string $tld, DomainRegistrarCredentials $credentials): TldPricing
    {
        throw new DomainRegistrarNotConfigured('CentralNic Reseller: its prices are not read (no new domains are sold through it).');
    }

    /**
     * A page of the account, each name read as its sync reads it (StatusDomain): the same expiry, nameservers, contacts
     * and lock for the import as for the sync, never its list\'s registry date (reviews of #297).
     */
    public function listDomains(DomainRegistrarCredentials $credentials, int $offset, int $limit): RegistrarDomainPage
    {
        $r = $this->read($credentials, ['COMMAND' => 'QueryDomainList', 'ORDERBY' => 'DOMAIN', 'FIRST' => max(0, $offset), 'LIMIT' => max(1, $limit)]);
        $records = [];
        $names = $r->list('DOMAIN');
        foreach ($names as $fqdn) {
            $fqdn = mb_strtolower(trim($fqdn));
            if (!str_contains($fqdn, '.')) {
                continue;
            }
            try {
                $one = $this->read($credentials, ['COMMAND' => 'StatusDomain', 'DOMAIN' => $fqdn]);
            } catch (DomainNotFoundAtRegistrar) {
                continue; // gone since the list was made
            }
            $result = $this->result($one);
            [$sld, $tld] = explode('.', $fqdn, 2);
            $records[] = new RegistrarDomainRecord(
                sld: $sld,
                tld: $tld,
                expiresAt: $result->expiresAt,
                registeredAt: $result->registeredAt,
                registrarDomainId: $result->registrarDomainId,
                status: $result->status,
                rawStatus: $result->rawStatus,
                nameservers: $result->nameservers,
                autoRenew: self::autoRenew($one->first('RENEWALMODE')),
                contactHandles: $result->contactHandles,
                transferLock: $result->transferLock,
                whoisPrivacy: $result->whoisPrivacy,
            );
        }
        $total = $r->first('TOTAL');

        return new RegistrarDomainPage($records, $total !== null && ctype_digit($total) ? (int) $total : null, count($names));
    }

    /** AUTORENEW: on; one that lets it go: off; DEFAULT (the account's, not known here) or another: unknown. */
    private static function autoRenew(?string $mode): ?bool
    {
        return match (strtoupper((string) $mode)) {
            'AUTORENEW', 'AUTORENEWMONTHLY', 'AUTORENEWQUATERLY' => true,
            'AUTOEXPIRE', 'AUTODELETE', 'RENEWONCE', 'EXPIREAUCTION' => false,
            default => null,
        };
    }

    public function getContact(string $handle, DomainRegistrarCredentials $credentials): ?RegistrarContact
    {
        try {
            $r = $this->read($credentials, ['COMMAND' => 'StatusContact', 'CONTACT' => $handle]);
        } catch (DomainNotFoundAtRegistrar) {
            return null;
        }
        $street = $r->list('STREET');
        $country = $r->first('COUNTRY');

        return new RegistrarContact(
            handle: $handle,
            name: trim(implode(' ', array_filter([$r->first('FIRST_NAME'), $r->first('MIDDLE_NAME'), $r->first('LAST_NAME')]))),
            org: $r->first('ORGANIZATION'),
            email: $r->first('EMAIL'),
            phone: $r->first('PHONE'),
            address1: ($street[0] ?? '') !== '' ? $street[0] : null,
            address2: ($street[1] ?? '') !== '' ? $street[1] : null,
            city: $r->first('CITY'),
            postcode: $r->first('ZIP'),
            country: $country !== null ? strtoupper($country) : null,
        );
    }

    /** Its auth code as its record holds it (a read: shown to who may see it, never logged). */
    public function getEppCode(Domain $domain, DomainRegistrarCredentials $credentials): ?string
    {
        return $this->read($credentials, ['COMMAND' => 'StatusDomain', 'DOMAIN' => $domain->fqdn])->first('AUTH');
    }

    /**
     * Its renewal (step 3): RenewDomain for these years. ONLY DomainRenewalService calls this: it holds the domain's lock,
     * reads CentralNic first (a period it already has is adopted, never charged again) and logs the charge before it is
     * asked. Its answer: refused (5xx) or try later (4xx): nothing charged; no code, an HTTP error of its front: maybe
     * charged (left pending, never asked again blindly). Charged, its record is read again; a read that fails then is
     * never a failure (the next sync lands the new date).
     */
    public function renew(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        if ($years < 1 || $years > 10) {
            throw new RegistrarRefused("CentralNic Reseller: {$years} years is not a renewal: nothing was sent.");
        }
        $this->write($credentials, ['COMMAND' => 'RenewDomain', 'DOMAIN' => $domain->fqdn, 'PERIOD' => $years]);
        try {
            return $this->result($this->read($credentials, ['COMMAND' => 'StatusDomain', 'DOMAIN' => $domain->fqdn]));
        } catch (Throwable) {
            return new DomainSyncResult(registrarDomainId: $domain->registrar_domain_id);
        }
    }

    public function register(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        throw self::notYet('registered');
    }

    public function transferIn(Domain $domain, string $authCode, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        throw self::notYet('transferred in');
    }

    /**
     * Its nameservers, its transfer lock, which contact has each role: one ModifyDomain, then read again. A nameserver
     * inside the name itself needs its glue at CentralNic, set there by hand; no WHOIS privacy through it. A role sent only
     * when its handle is not the one CentralNic has; another owner never (a trade, maybe charged: staff).
     */
    public function updateDomain(Domain $domain, DomainChanges $changes, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        if ($changes->isEmpty()) {
            throw new RuntimeException('Nothing to send to CentralNic Reseller.');
        }
        if ($changes->whoisPrivacy !== null) {
            throw new RegistrarNotAsked('CentralNic Reseller: WHOIS privacy is not set from MyCS: nothing was sent.');
        }
        $command = ['COMMAND' => 'ModifyDomain', 'DOMAIN' => $domain->fqdn];
        $now = null; // its record before the change, read once when needed
        $current = function () use (&$now, $domain, $credentials): DomainSyncResult {
            return $now ??= $this->result($this->read($credentials, ['COMMAND' => 'StatusDomain', 'DOMAIN' => $domain->fqdn]));
        };
        if ($changes->nameservers !== null) {
            // A line break taken out first, as it would be on the wire (adversarial ADV-6 of #300).
            $hosts = array_map(fn (string $host) => mb_strtolower(rtrim(trim(str_replace(["\r", "\n"], '', $host)), '.')), $changes->nameservers);
            foreach ($hosts as $host) {
                $inside = $host === $domain->fqdn || str_ends_with($host, '.' . $domain->fqdn);
                // One inside the name needs its glue at CentralNic: one it has already has it (ADV-8 of #300).
                if ($inside && !in_array($host, $current()->nameservers, true)) {
                    throw new RegistrarRefused("CentralNic Reseller: {$host} is inside {$domain->fqdn} and needs its glue (IP) there: set it at CentralNic by hand. Nothing was sent.");
                }
            }
            $command['NAMESERVER'] = $hosts;
        }
        if ($changes->transferLock !== null) {
            $command['TRANSFERLOCK'] = $changes->transferLock ? 1 : 0;
        }
        $handles = [];
        if ($changes->applyContacts) {
            $theirs = $current()->contactHandles;
            // Its owner first, before any contact is made (code review 7, ADV-9 of #300): never another owner here.
            $owner = trim((string) $domain->registrant_handle);
            if ($owner === '' || ($theirs['registrant'] ?? null) !== $owner) {
                throw new RegistrarRefused("CentralNic Reseller: the owner of {$domain->fqdn} here is not the one CentralNic has (another owner is a trade, maybe charged: staff do it there). Nothing was sent.");
            }
            foreach (array_keys(self::ROLES) as $role) {
                // A role just emptied here (the change saved before it is sent) while CentralNic has one: it cannot be taken
                // away through it (ADV-3 of #300). One that never had a contact here is not this.
                if ($role !== 'registrant' && $domain->wasChanged("{$role}_contact_id") && $domain->{"{$role}_contact_id"} === null && isset($theirs[$role])) {
                    throw new RegistrarRefused("CentralNic Reseller: the {$role} of {$domain->fqdn} cannot be left without a contact there (it has {$theirs[$role]}): choose one. Nothing was sent.");
                }
            }
            $handles = $this->ensureHandles($domain, $credentials);
            foreach (self::ROLES as $role => $key) {
                if ($role === 'registrant' || !isset($handles[$role]) || ($theirs[$role] ?? null) === $handles[$role]) {
                    continue; // its owner never; not ours to say, or already so
                }
                $command[str_replace('_', '', $key) . '0'] = $handles[$role];
            }
        }
        if (count($command) > 2) {
            $this->write($credentials, $command);
        }
        try {
            $base = $this->result($this->read($credentials, ['COMMAND' => 'StatusDomain', 'DOMAIN' => $domain->fqdn]));
        } catch (Throwable) {
            // Done, not read back (a 421, no answer): what was sent, never «failed» (an undo here would say the opposite
            // of CentralNic: code review 1, ADV-1 of #300). The next sync reads the rest.
            return new DomainSyncResult(nameservers: $command['NAMESERVER'] ?? [], contactHandles: $handles, transferLock: $changes->transferLock);
        }

        return new DomainSyncResult(
            expiresAt: $base->expiresAt,
            nameservers: $base->nameservers,
            registrarDomainId: $base->registrarDomainId,
            status: $base->status,
            rawStatus: $base->rawStatus,
            // What CentralNic says after the change wins: a role it did not take shows, and the sync warns (CR 10, ADV-4).
            contactHandles: $base->contactHandles + $handles,
            transferLock: $base->transferLock,
            whoisPrivacy: $base->whoisPrivacy,
            registeredAt: $base->registeredAt,
            details: $base->details,
        );
    }

    /** A contact's address, phone and email at CentralNic (P1b-2): ModifyContact; who it is never. */
    public function updateContact(string $handle, Contact $contact, DomainRegistrarCredentials $credentials): void
    {
        // Its account is shared with another company (nixpal): a handle MyCS did not make may hold its domains too, and
        // their WHOIS would change. Only ours; another is staff's (code review 3 of #300).
        if (!self::madeHere($handle)) {
            throw new RegistrarRefused("CentralNic Reseller: the contact {$handle} was not made by MyCS and may be another company's too in this shared account: update it at CentralNic by hand. Nothing was sent.");
        }
        $this->write($credentials, ['COMMAND' => 'ModifyContact', 'CONTACT' => $handle] + self::contactDetails($contact, clear: true));
    }

    /** A handle this MyCS made (AddContact, kept on its domain's meta). */
    private static function madeHere(string $handle): bool
    {
        return Domain::query()->where('meta', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], json_encode($handle)) . '%')->get(['meta'])
            ->contains(fn (Domain $domain) => in_array($handle, (array) ($domain->meta['contact_handles'] ?? []), true));
    }

    /**
     * Each role\'s handle: the one it has (cleared by Domain when its contact changes), the one made for that contact on this
     * domain before (a write refused after it), or a new one (AddContact). Only the account\'s own contacts, with a country.
     *
     * @return array<string, string> role → handle
     */
    private function ensureHandles(Domain $domain, DomainRegistrarCredentials $credentials): array
    {
        // Every role checked before anything is made (code review 7 of #300).
        foreach (array_keys(self::ROLES) as $role) {
            $contact = $domain->{"{$role}Contact"};
            if ($contact !== null && trim((string) $domain->{"{$role}_handle"}) === '') {
                if ((int) $contact->client_id !== (int) $domain->client_id) {
                    throw new RegistrarRefused("CentralNic Reseller: the {$role} contact of {$domain->fqdn} belongs to another account. Nothing was sent.");
                }
                if (trim((string) $contact->country) === '') {
                    throw new RegistrarRefused("CentralNic Reseller: the {$role} contact of {$domain->fqdn} has no country. Nothing was sent.");
                }
            }
        }
        // The handles its contacts already have, in any role (ADV-5 of #300); one staff linked to a role by their word is
        // that role\'s only, never lent to another (D4c, code review 5).
        $handles = [];
        $byContact = [];
        foreach (array_keys(self::ROLES) as $role) {
            $contact = $domain->{"{$role}Contact"};
            $existing = trim((string) $domain->{"{$role}_handle"});
            if ($existing === '') {
                continue;
            }
            $handles[$role] = $existing;
            if ($contact !== null && ($domain->meta['linked_handles'][$role] ?? null) !== $existing) {
                $byContact[$contact->id] ??= $existing;
            }
        }
        foreach (array_keys(self::ROLES) as $role) {
            $contact = $domain->{"{$role}Contact"};
            if (isset($handles[$role]) || $contact === null) {
                continue;
            }
            $handle = $byContact[$contact->id] ?? $this->madeHandle($domain, $contact->id);
            if ($handle === null) {
                try {
                    $made = $this->write($credentials, ['COMMAND' => 'AddContact',
                        'FIRSTNAME' => (string) $contact->first_name,
                        'LASTNAME' => (string) ($contact->last_name ?: $contact->first_name),
                        'ORGANIZATION' => trim((string) $contact->company_name) !== '' ? trim((string) $contact->company_name) : null,
                    ] + self::contactDetails($contact));
                } catch (RegistrarOutcomeUnknown|ConnectionException $e) {
                    // At most an orphan contact there; the domain was not touched: its roles go back here (code review 4).
                    throw new RuntimeException("CentralNic Reseller: the {$role} contact of {$domain->fqdn} could not be made ({$e->getMessage()}): nothing was changed on the domain.");
                }
                $handle = $made->first('CONTACT');
                if ($handle === null) {
                    throw new RuntimeException("CentralNic Reseller made the {$role} contact of {$domain->fqdn} without telling its handle: nothing was changed on the domain.");
                }
                $this->rememberHandle($domain, $contact->id, $handle);
            }
            Domain::query()->whereKey($domain->getKey())->update(["{$role}_handle" => $handle]);
            $domain->forceFill(["{$role}_handle" => $handle])->syncOriginalAttribute("{$role}_handle");
            $handles[$role] = $byContact[$contact->id] = $handle;
        }

        return $handles;
    }

    private function madeHandle(Domain $domain, int $contactId): ?string
    {
        $meta = json_decode((string) Domain::query()->toBase()->where('id', $domain->getKey())->value('meta'), true);
        $handle = is_array($meta) ? ($meta['contact_handles'][$contactId] ?? null) : null;

        return is_string($handle) && $handle !== '' ? $handle : null;
    }

    /** Kept at once, outside the caller\'s work and its undo: a handle made is never made again for this contact. */
    private function rememberHandle(Domain $domain, int $contactId, string $handle): void
    {
        $meta = json_decode((string) Domain::query()->toBase()->where('id', $domain->getKey())->value('meta'), true);
        $meta = is_array($meta) ? $meta : [];
        $meta['contact_handles'][$contactId] = $handle;
        Domain::query()->whereKey($domain->getKey())->update(['meta' => json_encode($meta)]);
        $domain->forceFill(['meta' => $meta])->syncOriginalAttribute('meta');
    }

    /** @return array<string, string|null> its address, phone and email as CentralNic takes them */
    private static function contactDetails(Contact $contact, bool $clear = false): array
    {
        $phone = Phone::split($contact->phone, $contact->country);
        // A second line or a state taken away is cleared there too on an update (ADV-7 of #300); the rest is required.
        $optional = fn (?string $value) => trim((string) $value) !== '' ? trim((string) $value) : ($clear ? Command::CLEAR : null);

        return [
            'STREET0' => trim((string) $contact->address1) ?: null,
            'STREET1' => $optional($contact->address2),
            'CITY' => trim((string) $contact->city) ?: null,
            'ZIP' => trim((string) $contact->postcode) ?: null,
            'STATE' => $optional($contact->state),
            'COUNTRY' => strtoupper(trim((string) $contact->country)) ?: null,
            'PHONE' => $phone !== null ? $phone[0] . '.' . $phone[1] : null,
            'EMAIL' => trim((string) $contact->email) ?: null,
        ];
    }

    public function restore(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        throw self::notYet('restored');
    }

    private static function notYet(string $what): RegistrarNotAsked
    {
        return new RegistrarNotAsked("CentralNic Reseller: a domain is not {$what} from MyCS yet (reads only, for now): nothing was sent. Do it at CentralNic by hand.");
    }

    private function result(Response $r): DomainSyncResult
    {
        $statuses = [...$r->list('STATUS'), ...$r->list('RGP_STATUS')];
        $handles = [];
        foreach (self::ROLES as $role => $key) {
            if (($handle = $r->first($key)) !== null) {
                $handles[$role] = $handle;
            }
        }
        $details = [];
        foreach (self::DETAIL_KEYS as $key) {
            if ($r->has($key)) {
                $details[strtolower($key)] = implode(', ', $r->list($key));
            }
        }
        $lock = $r->first('TRANSFER_LOCK');
        // A TLD without a transfer lock there (no such property): its toggle not offered (code review 8 of #300).
        $details['is_lockable'] = $lock !== null;
        $privacy = $r->first('X-WHOISPRIVACY');

        return new DomainSyncResult(
            expiresAt: self::expiry($r),
            nameservers: array_values(array_filter(array_map(fn (string $ns) => mb_strtolower(rtrim(trim($ns), '.')), $r->list('NAMESERVER')))),
            registrarDomainId: $r->first('ROID'),
            status: self::status($statuses),
            rawStatus: $statuses !== [] ? implode(', ', $statuses) : null,
            contactHandles: $handles,
            transferLock: $lock === null ? null : $lock === '1',
            whoisPrivacy: $privacy === null ? null : $privacy === '1',
            registeredAt: self::date($r->first('CREATED_DATE')),
            details: $details,
            paidUntil: self::date($r->first('PAIDUNTIL_DATE')) ?? self::date($r->first('REGISTRATION_EXPIRATION_DATE')),
        );
    }

    /**
     * The day it goes there unless renewed: paid until (else the registry\'s expiry), or its renewal date when that is
     * earlier (with AUTOEXPIRE CentralNic deletes it then, for some TLDs days or weeks before: reviews of #297). Only
     * real dates; none: unknown, never a guess.
     */
    private static function expiry(Response $r): ?string
    {
        $paid = self::date($r->first('PAIDUNTIL_DATE')) ?? self::date($r->first('REGISTRATION_EXPIRATION_DATE'));
        $renewal = self::date($r->first('RENEWAL_DATE'));

        return match (true) {
            $paid === null => $renewal,
            $renewal === null => $paid,
            default => min($paid, $renewal),
        };
    }

    /** @param  list<string>  $statuses */
    private static function status(array $statuses): ?DomainStatus
    {
        if ($statuses === []) {
            return null; // no status said: unknown, never Active (reviews of #297)
        }
        $all = strtolower(implode(' ', $statuses));
        foreach (['redemption', 'pendingdelete', 'pendingrestore', 'pendingtransfer', 'deleted', 'expired'] as $going) {
            if (str_contains($all, $going)) {
                return null; // being deleted or moved: the lifecycle decides by its dates, never a guess
            }
        }

        return DomainStatus::Active; // in the account, live
    }

    /** «2027-11-01 10:00:00.0» → «2027-11-01»; only a real date (never 0000-00-00 or 31 February). */
    private static function date(?string $value): ?string
    {
        if ($value === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) !== 1) {
            return null;
        }
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        return $y >= 1985 && checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /**
     * A write: done (2xx), or why not: try later (4xx: nothing done, RegistrarNotAsked), refused (5xx: nothing done, the
     * same would be refused again), or not known (no code: maybe done, never «refused»).
     */
    private function write(DomainRegistrarCredentials $credentials, array $command): Response
    {
        try {
            $r = $this->call($credentials, $command);
        } catch (DomainRegistrarNotConfigured $e) {
            throw $e;
        } catch (RuntimeException $e) {
            // An HTTP error of its front (a 502, a 504) after it was sent: maybe done (code review 2, ADV-2 of #300).
            throw new RegistrarOutcomeUnknown('CentralNic Reseller ' . (string) $command['COMMAND'] . ': ' . $e->getMessage() . ': whether it was done is not known');
        }
        if ($r->success()) {
            return $r;
        }
        $what = 'CentralNic Reseller ' . (string) $command['COMMAND'] . ': ' . $r->summary();

        throw match (true) {
            $r->temporary() => new RegistrarNotAsked($what . ' (try later: nothing was done)'),
            $r->refused() => new RegistrarRefused($what, $r->code),
            default => new RegistrarOutcomeUnknown($what . ': whether it was done is not known'),
        };
    }

    /** A read: refused or unknown throws (545: not in the account). */
    private function read(DomainRegistrarCredentials $credentials, array $command): Response
    {
        $r = $this->call($credentials, $command);
        if ($r->success()) {
            return $r;
        }
        if ($r->notFound()) {
            // «Not in the account» says nothing of where it went: deleted by CentralNic itself (AUTOEXPIRE on its renewal
            // date) answers the same as moved away. Noted only, never marked transferred away on its own (#297).
            throw new DomainNotFoundAtRegistrar('CentralNic Reseller: ' . $r->summary(), elsewhere: false, resultCode: 545);
        }

        throw new RuntimeException('CentralNic Reseller ' . (string) $command['COMMAND'] . ': ' . $r->summary());
    }

    private function call(DomainRegistrarCredentials $credentials, array $command): Response
    {
        if (!$credentials->has('username') || !$credentials->has('password')) {
            throw new DomainRegistrarNotConfigured('CentralNic Reseller: its username and password are not set.');
        }
        $response = Http::asForm()->timeout(30)->connectTimeout(10)
            ->post($credentials->sandbox ? Command::OTE : Command::LIVE, Command::form((string) $credentials->get('username'), (string) $credentials->get('password'), $command));
        if ($response->failed()) {
            throw new RuntimeException('CentralNic Reseller HTTP ' . $response->status() . ': no answer of its API.');
        }

        return Response::parse($response->body());
    }
}
