<?php

namespace App\Services\Domains\Lookup;

use Illuminate\Support\Facades\Cache;
use Nixpal\DomainTools\Whois\Port43;
use Nixpal\DomainTools\Whois\RdapBootstrap;
use Nixpal\DomainTools\Whois\RdapClient;
use Nixpal\DomainTools\Whois\RdapRecord;
use Nixpal\DomainTools\Whois\SocketConnector;

/**
 * «Who has it?» per TLD (docs/domains.md D8, owner 2026-10-17: by registry, not registrar): RDAP (IANA's bootstrap) →
 * port 43 (the TLD's whois server, personal data masked) → a registry adapter where neither exists (.gr: ICS-FORTH, with
 * grepp; until then «not supported»). Answers cached an hour; the bootstrap a day. Also «is it free?» when the
 * registrar cannot say: RDAP's «not found».
 */
class DomainLookup
{
    /** TLDs whose registry answers neither RDAP nor port 43 here: their adapter comes with their module. */
    public const UNSUPPORTED = ['gr', 'ελ'];

    public function __construct(private readonly ?SocketConnector $socket = null) {}

    /**
     * @return array{status: string, record: RdapRecord|null, text: string|null} status: found, not_found, unsupported, error
     */
    public function whois(string $fqdn): array
    {
        $fqdn = mb_strtolower($fqdn);
        $tld = substr($fqdn, (int) strrpos($fqdn, '.') + 1);
        if (in_array($tld, self::UNSUPPORTED, true)) {
            return ['status' => 'unsupported', 'record' => null, 'text' => null];
        }

        return Cache::remember('domain-whois:' . sha1($fqdn), 3600, function () use ($fqdn) {
            [$status, $record] = $this->rdap()?->lookup($fqdn) ?? ['unsupported', null];
            if ($status === 'found' || $status === 'not_found') {
                return ['status' => $status, 'record' => $record, 'text' => null];
            }
            // The socket from the container (tests replace it: never the network from a test).
            [$status, $text] = (new Port43($this->socket ?? app(SocketConnector::class)))->lookup($fqdn);

            return ['status' => $status, 'record' => null, 'text' => $text];
        });
    }

    /** Free (true), taken (false), or not known (null), by RDAP alone: the registrar's fallback. */
    public function availableByRdap(string $fqdn): ?bool
    {
        [$status] = $this->rdap()?->lookup(mb_strtolower($fqdn)) ?? ['unsupported', null];

        return match ($status) {
            'not_found' => true,
            'found' => false,
            default => null,
        };
    }

    private function rdap(): ?RdapClient
    {
        $transport = new LaravelTransport;
        $json = Cache::remember('domain-rdap-bootstrap', 86400, function () use ($transport) {
            [$status, $body] = $transport->get(RdapBootstrap::URL);

            return $status === 200 ? $body : null;
        });
        if ($json === null) {
            Cache::forget('domain-rdap-bootstrap');

            return null;
        }
        $bootstrap = RdapBootstrap::fromJson($json);

        return $bootstrap === null ? null : new RdapClient($transport, $bootstrap);
    }
}
