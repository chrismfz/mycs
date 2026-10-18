<?php

namespace App\Services\Domains\Lookup;

use App\Models\DomainRegistrarConnection;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MyCS\GrEpp\Whois\WhoisRecord;
use Nixpal\DomainTools\Whois\RdapRecord;
use Throwable;

/**
 * «Who has it?» for .gr and .ελ (G6 of grepp): the registry has no RDAP and no open port 43, only its WHOIS RESTful API
 * (docs/domains/grepp/whois-api.md), with an account of its own (the .gr connection's «WHOIS API» username and password)
 * and only from declared IPs. A name in Unicode (the API takes no punycode). At most 2 requests a second (its burst
 * limit: a third answered 429, live): one at a time, 0.6 s apart, across processes; paused a minute when it is down or
 * limiting us. Its answer as an RDAP one; a name it does not list is «not listed», never «free» (it checks no
 * availability: EPP's domain:check does).
 */
final class GrWhois
{
    public const PRODUCTION = 'https://grwhois.ics.forth.gr:800';

    public const SANDBOX = 'https://uat-grwhois.ics.forth.gr:800';

    /** At most this many requests a day from us (the registry allows 10,000). */
    public const DAILY = 5000;

    /** Below this many left for the day at the registry, it is not asked again for an hour. */
    private const RESERVE = 500;

    /** Seconds between two requests (its burst limit: 2 a second). */
    private const GAP = 0.6;

    public static function covers(string $tld): bool
    {
        return in_array(mb_strtolower($tld), ['gr', 'xn--qxam', 'ελ'], true);
    }

    /**
     * The .gr connection with a WHOIS account: a usable one (never «off»), the live one first (review 1, 2 of #197: never
     * the test registry's answers where a live connection exists).
     */
    public static function connection(): ?DomainRegistrarConnection
    {
        // The live registry only (ADV-7 of #197): its test one's answers are never shown as real.
        $connections = DomainRegistrarConnection::query()->where('registrar', 'grepp')->where('is_active', true)->where('mode', 'production')
            ->orderBy('id')->get()->filter(fn (DomainRegistrarConnection $c) => $c->isUsable());
        foreach ($connections as $connection) {
            $credentials = $connection->credentials();
            if (trim((string) ($credentials['whois_username'] ?? '')) !== '' && (string) ($credentials['whois_password'] ?? '') !== '') {
                return $connection;
            }
        }

        return null;
    }

    /** Which registry its answers come from: part of their cache key (a mode switch never serves the other's). */
    public static function scope(): string
    {
        return self::connection()->mode ?? 'none';
    }

    /**
     * @return array{0: string, 1: RdapRecord|null, 2: WhoisRecord|null} status (found; not_listed: not in its WHOIS,
     *                                                                   which checks no availability; unsupported; error),
     *                                                                   the record as RDAP's, and the registry's own
     */
    public function lookup(string $fqdn): array
    {
        $connection = self::connection();
        if ($connection === null) {
            return ['unsupported', null, null];
        }
        // Non-transitional, as DomainName and Rules::name: a Unicode «καλός» stays καλός, not καλόσ.
        $name = idn_to_utf8(mb_strtolower($fqdn), IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46);
        if ($name === false || $name === '') {
            return ['error', null, null];
        }
        // The registry down or limiting us a moment ago: not asked again for a minute (review 4: a public page never ties
        // up the web workers waiting on it).
        if (Cache::has('grwhois-down')) {
            return ['error', null, null];
        }
        // Our own budget, under the registry's 10,000 a day (ADV-2 of #197: visitors from many addresses never use up the
        // account's quota; the rest of the day is the registry's to keep).
        $day = 'grwhois-day:' . gmdate('Y-m-d');
        if ((int) Cache::get($day, 0) >= self::DAILY) {
            return ['error', null, null];
        }
        Cache::add($day, 0, 90000);
        Cache::increment($day);
        $credentials = $connection->credentials();
        $base = $connection->mode === 'production' ? self::PRODUCTION : self::SANDBOX;
        try {
            $response = Cache::lock('grwhois-request', 30)->block(3, function () use ($base, $name, $credentials) {
                $wait = (float) Cache::get('grwhois-last', 0) + self::GAP - microtime(true);
                if ($wait > 0) {
                    usleep((int) ($wait * 1_000_000));
                }
                try {
                    return Http::timeout(5)->connectTimeout(3)->acceptJson()->withHeaders(['Accept-Language' => 'en'])
                        ->withBasicAuth((string) $credentials['whois_username'], (string) $credentials['whois_password'])
                        ->get($base . '/api/v1/domain/' . rawurlencode($name));
                } finally {
                    Cache::put('grwhois-last', microtime(true), 60);
                }
            });
        } catch (LockTimeoutException) {
            return ['error', null, null]; // busy: never cached
        } catch (Throwable $e) {
            Cache::put('grwhois-down', true, 60);
            Log::warning('The .gr WHOIS API did not answer: ' . $e::class);

            return ['error', null, null];
        }
        // What the registry says is left (X-RateLimit-Remaining), and how long to wait (X-RateLimit-Retry): obeyed (ADV-1, 2).
        $remaining = $response->header('X-RateLimit-Remaining');
        $retry = (int) $response->header('X-RateLimit-Retry');
        if ($response->status() === 429 || $retry > 0 || ($remaining !== '' && is_numeric($remaining) && (int) $remaining < self::RESERVE)) {
            Cache::put('grwhois-down', true, min(86400, max(60, $retry, $response->status() === 429 || $retry > 0 ? 60 : 3600)));
        }
        // Its own «not found» only (a JSON error 404: ADV-5); a proxy's or a wrong path's 404 is no answer.
        $error = json_decode($response->body(), true);
        if ($response->status() === 404) {
            return is_array($error) && ($error['error'] ?? null) === 404 ? ['not_listed', null, null] : ['error', null, null];
        }
        // A name it refuses (400): definite, kept like an answer (ADV-4).
        if ($response->status() === 400 && is_array($error)) {
            return ['invalid', null, null];
        }
        $record = $response->status() === 200 ? WhoisRecord::fromJson($response->body()) : null;
        if ($record === null) {
            // 400, 401 (its password), 403 (an undeclared IP), 429, or a body that does not read: said in the log, never
            // its credentials (review 5); a 429 or a server error pauses it a minute.
            if ($response->status() >= 500) {
                Cache::put('grwhois-down', true, 60);
            }
            Log::warning("The .gr WHOIS API answered {$response->status()}.", array_filter([
                'limit' => $response->header('X-RateLimit-Limit'), 'remaining' => $response->header('X-RateLimit-Remaining'),
                'retry' => $response->header('X-RateLimit-Retry')]));

            return ['error', null, null];
        }

        return ['found', self::asRdap($record), $record];
    }

    private static function asRdap(WhoisRecord $record): RdapRecord
    {
        $rdap = new RdapRecord;
        $rdap->domain = $record->name;
        $rdap->registrar = $record->registrar;
        $rdap->registrar_url = $record->registrarUrl;
        $rdap->registered = $record->createdOn;
        $rdap->expires = $record->expiresOn;
        $rdap->changed = $record->updatedOn;
        $rdap->status = $record->statuses;
        $rdap->nameservers = $record->nameservers;
        $rdap->dnssec = $record->dnssec;

        return $rdap;
    }
}
