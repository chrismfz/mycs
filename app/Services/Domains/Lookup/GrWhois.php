<?php

namespace App\Services\Domains\Lookup;

use App\Models\DomainRegistrarConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MyCS\GrEpp\Whois\WhoisRecord;
use Nixpal\DomainTools\Whois\RdapRecord;
use Throwable;

/**
 * «Who has it?» for .gr and .ελ (G6 of grepp): the registry has no RDAP and no open port 43, only its WHOIS RESTful API
 * (docs/domains/grepp/whois-api.md), with an account of its own (the .gr connection's «WHOIS API» username and password)
 * and only from declared IPs. A name in Unicode (the API takes no punycode). At most 2 requests a second (its burst
 * limit: a third answered 429, live): one at a time, 0.6 s apart, across processes. Its answer as an RDAP one.
 */
final class GrWhois
{
    public const PRODUCTION = 'https://grwhois.ics.forth.gr:800';

    public const SANDBOX = 'https://uat-grwhois.ics.forth.gr:800';

    /** Seconds between two requests (its burst limit: 2 a second). */
    private const GAP = 0.6;

    public static function covers(string $tld): bool
    {
        return in_array(mb_strtolower($tld), ['gr', 'xn--qxam', 'ελ'], true);
    }

    /** The .gr connection with a WHOIS account, if any. */
    public static function connection(): ?DomainRegistrarConnection
    {
        foreach (DomainRegistrarConnection::query()->where('registrar', 'grepp')->where('is_active', true)->orderBy('id')->get() as $connection) {
            $credentials = $connection->credentials();
            if (trim((string) ($credentials['whois_username'] ?? '')) !== '' && (string) ($credentials['whois_password'] ?? '') !== '') {
                return $connection;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: RdapRecord|null, 2: WhoisRecord|null} status (found, not_found, unsupported, error),
     *                                                                   the record as RDAP's, and the registry's own
     */
    public function lookup(string $fqdn): array
    {
        $connection = self::connection();
        if ($connection === null) {
            return ['unsupported', null, null];
        }
        $name = idn_to_utf8(mb_strtolower($fqdn), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($name === false || $name === '') {
            return ['error', null, null];
        }
        $credentials = $connection->credentials();
        $base = $connection->mode === 'production' ? self::PRODUCTION : self::SANDBOX;
        try {
            $response = Cache::lock('grwhois-request', 30)->block(10, function () use ($base, $name, $credentials) {
                $wait = (float) Cache::get('grwhois-last', 0) + self::GAP - microtime(true);
                if ($wait > 0) {
                    usleep((int) ($wait * 1_000_000));
                }
                try {
                    return Http::timeout(10)->connectTimeout(5)->acceptJson()->withHeaders(['Accept-Language' => 'en'])
                        ->withBasicAuth((string) $credentials['whois_username'], (string) $credentials['whois_password'])
                        ->get($base . '/api/v1/domain/' . rawurlencode($name));
                } finally {
                    Cache::put('grwhois-last', microtime(true), 60);
                }
            });
        } catch (Throwable) {
            return ['error', null, null]; // busy, no answer: never cached
        }
        if ($response->status() === 404) {
            return ['not_found', null, null];
        }
        $record = $response->status() === 200 ? WhoisRecord::fromJson($response->body()) : null;
        if ($record === null) {
            return ['error', null, null]; // 400, 401, 403, 429 or a body that does not read
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
