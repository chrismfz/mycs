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
    private const DETAIL_KEYS = ['STATUS', 'RENEWALMODE', 'TRANSFERMODE', 'PAIDUNTIL_DATE', 'REGISTRATION_EXPIRATION_DATE', 'RENEWAL_DATE',
        'CREATED_DATE', 'UPDATED_DATE', 'ZONE', 'X-WHOISPRIVACY', 'X-TRANSFERLOCK-UNTIL', 'X-REGISTRY-LOCK', 'X-TIME-TO-SUSPENSION'];

    private const ROLES = ['registrant' => 'OWNER_CONTACT', 'admin' => 'ADMIN_CONTACT', 'tech' => 'TECH_CONTACT', 'billing' => 'BILLING_CONTACT'];

    public function key(): string
    {
        return 'cnic';
    }

    public function capabilities(): DomainRegistrarCapabilities
    {
        return new DomainRegistrarCapabilities(supportsPortfolioImport: true);
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

        return new AvailabilityResult($fqdn, str_starts_with($check, '210'), $check !== '' ? $check : null,
            premium: $r->first('X-FEE-CLASS') !== null && strcasecmp((string) $r->first('X-FEE-CLASS'), 'standard') !== 0);
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

    public function listDomains(DomainRegistrarCredentials $credentials, int $offset, int $limit): RegistrarDomainPage
    {
        $r = $this->read($credentials, ['COMMAND' => 'QueryDomainList', 'WIDE' => 1, 'ORDERBY' => 'DOMAIN', 'FIRST' => max(0, $offset), 'LIMIT' => max(1, $limit)]);
        $records = [];
        $names = $r->list('DOMAIN');
        foreach ($names as $i => $fqdn) {
            $fqdn = mb_strtolower(trim($fqdn));
            if (!str_contains($fqdn, '.')) {
                continue;
            }
            [$sld, $tld] = explode('.', $fqdn, 2);
            $records[] = new RegistrarDomainRecord(
                sld: $sld,
                tld: $tld,
                expiresAt: self::date($r->list('DOMAIN_REGISTRATION_EXPIRATION_DATE')[$i] ?? null),
                registeredAt: self::date($r->list('DOMAIN_CREATED_DATE')[$i] ?? null),
                registrarDomainId: ($r->list('ROID')[$i] ?? '') !== '' ? $r->list('ROID')[$i] : null,
                status: DomainStatus::Active, // listed in the account
                autoRenew: isset($r->list('RENEWALMODE')[$i]) ? in_array(strtoupper($r->list('RENEWALMODE')[$i]), ['AUTORENEW', 'DEFAULT'], true) : null,
            );
        }
        $total = $r->first('TOTAL');

        return new RegistrarDomainPage($records, $total !== null && ctype_digit($total) ? (int) $total : null, count($names));
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
        $statuses = $r->list('STATUS');
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
            // Paid until (what the customer has); the registry's own expiry is in its details.
            expiresAt: self::date($r->first('PAIDUNTIL_DATE') ?? $r->first('REGISTRATION_EXPIRATION_DATE')),
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

    /** @param  list<string>  $statuses */
    private static function status(array $statuses): ?DomainStatus
    {
        $all = strtolower(implode(' ', $statuses));
        if (str_contains($all, 'redemption') || str_contains($all, 'pendingdelete')) {
            return null; // in its deletion: the lifecycle decides by its dates, never a guess
        }

        return DomainStatus::Active; // in the account, not being deleted
    }

    /** «2027-11-01 10:00:00.0» → «2027-11-01». */
    private static function date(?string $value): ?string
    {
        return $value !== null && preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) === 1 ? $m[1] : null;
    }

    /** A read: refused or unknown throws (545: not in the account). */
    private function read(DomainRegistrarCredentials $credentials, array $command): Response
    {
        $r = $this->call($credentials, $command);
        if ($r->success()) {
            return $r;
        }
        if ($r->notFound()) {
            throw new DomainNotFoundAtRegistrar('CentralNic Reseller: ' . $r->summary(), resultCode: 545);
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
