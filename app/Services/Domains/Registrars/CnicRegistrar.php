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
 * A renewal, registration, transfer or restore is still not asked (RegistrarNotAsked: nothing sent, nothing charged).
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
        return new DomainRegistrarCapabilities(supportsPortfolioImport: true, supportsTransferLock: true, supportsRegistration: false,
            supportsRenewal: false, supportsManagement: true, expiryIsWhenItGoes: true);
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

    public function renew(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        throw self::notYet('renewed');
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
        if ($changes->nameservers !== null) {
            $hosts = array_map(fn (string $host) => mb_strtolower(rtrim(trim($host), '.')), $changes->nameservers);
            foreach ($hosts as $host) {
                if ($host === $domain->fqdn || str_ends_with($host, '.' . $domain->fqdn)) {
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
            $theirs = $this->result($this->read($credentials, ['COMMAND' => 'StatusDomain', 'DOMAIN' => $domain->fqdn]))->contactHandles;
            $handles = $this->ensureHandles($domain, $credentials);
            if (!isset($handles['registrant'])) {
                throw new RegistrarRefused("CentralNic Reseller: {$domain->fqdn} has no registrant here: nothing was sent.");
            }
            foreach (self::ROLES as $role => $key) {
                if (!isset($handles[$role]) || ($theirs[$role] ?? null) === $handles[$role]) {
                    continue; // not ours to say, or already so
                }
                if ($role === 'registrant') {
                    throw new RegistrarRefused("CentralNic Reseller: another owner of {$domain->fqdn} is not set through the API (a trade, maybe charged): staff do it there. Nothing was sent.");
                }
                $command[str_replace('_', '', $key) . '0'] = $handles[$role];
            }
        }
        if (count($command) > 2) {
            $this->write($credentials, $command);
        }
        $base = $this->result($this->read($credentials, ['COMMAND' => 'StatusDomain', 'DOMAIN' => $domain->fqdn]));

        return new DomainSyncResult(
            expiresAt: $base->expiresAt,
            nameservers: $base->nameservers,
            registrarDomainId: $base->registrarDomainId,
            status: $base->status,
            rawStatus: $base->rawStatus,
            contactHandles: $handles + $base->contactHandles,
            transferLock: $base->transferLock,
            whoisPrivacy: $base->whoisPrivacy,
            registeredAt: $base->registeredAt,
            details: $base->details,
        );
    }

    /** A contact's address, phone and email at CentralNic (P1b-2): ModifyContact; who it is never. */
    public function updateContact(string $handle, Contact $contact, DomainRegistrarCredentials $credentials): void
    {
        $this->write($credentials, ['COMMAND' => 'ModifyContact', 'CONTACT' => $handle] + self::contactDetails($contact));
    }

    /**
     * Each role\'s handle: the one it has (cleared by Domain when its contact changes), the one made for that contact on this
     * domain before (a write refused after it), or a new one (AddContact). Only the account\'s own contacts, with a country.
     *
     * @return array<string, string> role → handle
     */
    private function ensureHandles(Domain $domain, DomainRegistrarCredentials $credentials): array
    {
        $handles = [];
        $byContact = [];
        foreach (array_keys(self::ROLES) as $role) {
            $contact = $domain->{"{$role}Contact"};
            $existing = trim((string) $domain->{"{$role}_handle"});
            if ($existing !== '') {
                $handles[$role] = $existing;
                if ($contact !== null) {
                    $byContact[$contact->id] ??= $existing;
                }

                continue;
            }
            if ($contact === null) {
                continue;
            }
            if ((int) $contact->client_id !== (int) $domain->client_id) {
                throw new RegistrarRefused("CentralNic Reseller: the {$role} contact of {$domain->fqdn} belongs to another account. Nothing was sent.");
            }
            if (trim((string) $contact->country) === '') {
                throw new RegistrarRefused("CentralNic Reseller: the {$role} contact of {$domain->fqdn} has no country. Nothing was sent.");
            }
            $handle = $byContact[$contact->id] ?? $this->madeHandle($domain, $contact->id);
            if ($handle === null) {
                $made = $this->write($credentials, ['COMMAND' => 'AddContact',
                    'FIRSTNAME' => (string) $contact->first_name,
                    'LASTNAME' => (string) ($contact->last_name ?: $contact->first_name),
                    'ORGANIZATION' => trim((string) $contact->company_name) !== '' ? trim((string) $contact->company_name) : null,
                ] + self::contactDetails($contact));
                $handle = $made->first('CONTACT');
                if ($handle === null) {
                    throw new RegistrarOutcomeUnknown("CentralNic Reseller made the {$role} contact of {$domain->fqdn} without telling its handle.");
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
    private static function contactDetails(Contact $contact): array
    {
        $phone = Phone::split($contact->phone, $contact->country);

        return [
            'STREET0' => trim((string) $contact->address1) ?: null,
            'STREET1' => trim((string) $contact->address2) ?: null,
            'CITY' => trim((string) $contact->city) ?: null,
            'ZIP' => trim((string) $contact->postcode) ?: null,
            'STATE' => trim((string) $contact->state) ?: null,
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
        $r = $this->call($credentials, $command);
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
