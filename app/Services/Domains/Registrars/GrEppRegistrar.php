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
use MyCS\GrEpp\Command\Rules;
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
        // Reads only until G5; the registry has no privacy toggle, no transfer lock, no price feed, no portfolio list. A
        // name in its 15 days after expiry is renewed (no restore), in even years (its FAQ).
        return new DomainRegistrarCapabilities(renewsInGrace: true, renewYearStep: 2);
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
        } catch (\InvalidArgumentException $e) {
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
