<?php

namespace App\Services\Domains\Registrars;

use App\Contracts\DomainRegistrar;
use App\Contracts\ReadsAccountBalance;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Services\Domains\DomainNotFoundAtRegistrar;
use App\Services\Domains\DomainRegistrarNotConfigured;
use App\Services\Domains\RegistrarNotAsked;
use App\Support\Domains\AvailabilityResult;
use App\Support\Domains\DomainChanges;
use App\Support\Domains\DomainRegistrarCapabilities;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
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
 * Step 1, reads only: the connection's ping and balance, availability, a domain's sync (its dates, nameservers, lock,
 * contacts), the account's list and contacts (the import), its EPP code. Every write (renew, register, transfer, a
 * change, a restore) is not asked (RegistrarNotAsked: nothing sent, nothing charged): staff do it at CNIC by hand until
 * the next steps.
 */
final class CnicRegistrar implements DomainRegistrar, ReadsAccountBalance
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
        // Reads only (step 1): nothing sold, registered, renewed or changed through it; its expiry is the day the name goes
        // there (reviews of #297).
        return new DomainRegistrarCapabilities(supportsPortfolioImport: true, supportsRegistration: false, supportsRenewal: false,
            supportsManagement: false, expiryIsWhenItGoes: true);
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

        // Its credit is what it can spend (AMOUNT is what was used).
        return new RegistrarBalance(amount: (string) ($r->first('CREDIT') ?? '0'), currency: $r->first('CURRENCY'));
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

    public function updateDomain(Domain $domain, DomainChanges $changes, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        throw self::notYet('changed');
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
