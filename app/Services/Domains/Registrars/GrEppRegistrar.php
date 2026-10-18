<?php

namespace App\Services\Domains\Registrars;

use App\Contracts\DomainRegistrar;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Services\Domains\DomainNotFoundAtRegistrar;
use App\Services\Domains\DomainRegistrarNotConfigured;
use App\Services\Domains\RegistrarRefused;
use App\Support\Domains\AvailabilityResult;
use App\Support\Domains\DomainChanges;
use App\Support\Domains\DomainRegistrarCapabilities;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use App\Support\Domains\RegistrarContact;
use App\Support\Domains\RegistrarDomainPage;
use App\Support\Domains\TldPricing;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use MyCS\GrEpp\Command\ContactInfo;
use MyCS\GrEpp\Command\DomainCheck;
use MyCS\GrEpp\Command\DomainInfo;
use MyCS\GrEpp\Config;
use MyCS\GrEpp\Data\DomainInfo as Info;
use MyCS\GrEpp\GrEppException;
use MyCS\GrEpp\Http\CurlTransport;
use MyCS\GrEpp\Http\Transport;
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
class GrEppRegistrar implements DomainRegistrar
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
        // Reads only until G5; the registry has no privacy toggle, no transfer lock, no price feed, no portfolio list.
        return new DomainRegistrarCapabilities;
    }

    /** Contract: never throws. A login (and nothing else): the credentials and our IP at the registry. */
    public function ping(DomainRegistrarCredentials $credentials): bool
    {
        try {
            return $this->locked($credentials, fn (Session $session) => $session->login()->code === 1000);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Free, taken, or «available under restrictions» (geographic terms, .gov.gr: not free here, the regulator decides;
     * its reason says so). The registry's only availability check.
     */
    public function checkAvailability(string $fqdn, DomainRegistrarCredentials $credentials): AvailabilityResult
    {
        $name = mb_strtolower($fqdn);
        $results = $this->command($credentials, fn (string $clTRID) => DomainCheck::request([$name], $clTRID), DomainCheck::parse(...));
        $result = $results[$name] ?? throw new RuntimeException("The registry did not answer for {$fqdn}.");
        $reason = $result->restricted ? 'restricted: ' . ($result->comment ?? $result->reason) : ($result->comment ?? $result->reason);

        return new AvailabilityResult($fqdn, $result->available, $reason);
    }

    /** The registry's truth for one of our domains (domain:info): status, expiry day, nameservers, contacts, details. */
    public function syncDomain(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        try {
            $info = $this->command($credentials, fn (string $clTRID) => DomainInfo::request($domain->fqdn, $clTRID),
                fn (Response $response) => DomainInfo::parse($response, $domain->fqdn));
        } catch (Refused $e) {
            // 2303: no such name; 2201: not ours to read (another registrar's, no code given).
            if (in_array($e->resultCode, [2303, 2201], true)) {
                throw new DomainNotFoundAtRegistrar("{$domain->fqdn} is not ours at the registry ({$e->resultCode}: {$e->registryMessage}).");
            }
            throw new RuntimeException("The registry refused to read {$domain->fqdn}: {$e->getMessage()}");
        }
        if ($info->clientId !== null && strcasecmp($info->clientId, (string) $credentials->get('username')) !== 0) {
            throw new DomainNotFoundAtRegistrar("{$domain->fqdn} is managed by another registrar at the registry ({$info->clientId}).");
        }

        return self::syncResultFrom($info);
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
        if (in_array('pendingCreate', $info->statuses, true)) {
            return [DomainStatus::PendingRegister, null];
        }
        if ($info->expiresOn === null) {
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

    public function renew(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        throw self::notYet('renew');
    }

    public function register(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        throw self::notYet('register');
    }

    public function transferIn(Domain $domain, string $authCode, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        throw self::notYet('transfer');
    }

    /** The registry never gives the code: it emails the registrant a reset link (DACoR, G8). */
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
    private function command(DomainRegistrarCredentials $credentials, Closure $request, Closure $parse): mixed
    {
        return $this->locked($credentials, function (Session $session) use ($request, $parse) {
            $clTRID = $session->newTransactionId();

            return $parse($session->command($request($clTRID), $clTRID));
        });
    }

    /**
     * @template T
     *
     * @param  Closure(Session): T  $call
     * @return T
     */
    private function locked(DomainRegistrarCredentials $credentials, Closure $call): mixed
    {
        $config = self::config($credentials);
        try {
            return Cache::lock('grepp-epp:' . sha1($config->endpoint . '|' . $config->clientId), 120)
                ->block(60, fn () => $call(self::session($config, $credentials)));
        } catch (LockTimeoutException) {
            throw new RuntimeException('The .gr registry account is busy with another command: try again in a minute.');
        } catch (GrEppException $e) {
            throw $e instanceof Refused ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    private static function config(DomainRegistrarCredentials $credentials): Config
    {
        if (!$credentials->has('username') || !$credentials->has('password')) {
            throw new DomainRegistrarNotConfigured('The .gr registry connection has no username or password: fill them in under Domain Registrars.');
        }

        return new Config((string) $credentials->get('username'), (string) $credentials->get('password'),
            endpoint: $credentials->sandbox ? Config::UAT : Config::PRODUCTION);
    }

    private static function session(Config $config, DomainRegistrarCredentials $credentials): Session
    {
        $key = sha1($config->endpoint . '|' . $config->clientId . '|' . hash('sha256', (string) $credentials->get('password')));
        $transport = self::$transport !== null ? (self::$transport)() : new CurlTransport;
        if (!$transport instanceof Transport) {
            throw new RuntimeException('The .gr transport seam must give a Transport.');
        }

        return self::$sessions[$key] ??= new Session($config, $transport);
    }

    /** Forget the sessions (tests; a changed transport). */
    public static function reset(): void
    {
        foreach (self::$sessions as $session) {
            $session->logout();
        }
        self::$sessions = [];
    }
}
