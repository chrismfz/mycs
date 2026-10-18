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
 * port 43 (the TLD's whois server, personal data masked) → a registry adapter where neither exists (.gr and .ελ: the
 * registry's WHOIS API, GrWhois, G6 of grepp; without its account «not supported»). Also «is it free?» when the registrar cannot say: RDAP's «not found».
 *
 * After review (#178): only definite answers are cached (an hour), never a registry's failure; the bootstrap is cached a
 * day only once it parses; the TLD's port 43 server a day; one RDAP client per request.
 */
class DomainLookup
{
    /** TLDs whose registry answers neither RDAP nor port 43 here (punycode); .gr and .ελ go to GrWhois before this. */
    public const UNSUPPORTED = ['gr', 'xn--qxam'];

    private ?RdapClient $rdap = null;

    private bool $rdapTried = false;

    public function __construct(private readonly ?SocketConnector $socket = null) {}

    /**
     * @return array{status: string, record: RdapRecord|null, text: string|null} status: found, not_found, not_listed (.gr: not in its WHOIS), unsupported, error
     */
    public function whois(string $fqdn): array
    {
        $fqdn = mb_strtolower($fqdn);
        $tld = substr($fqdn, (int) strrpos($fqdn, '.') + 1);
        $key = 'domain-whois-answer:' . sha1($fqdn);
        if (GrWhois::covers($tld)) {
            $key .= ':' . GrWhois::scope(); // the live registry's answers never served for the test one's
            if (($cached = Cache::get($key)) !== null) {
                return $cached;
            }
            [$status, $record] = app(GrWhois::class)->lookup($fqdn);
            $answer = ['status' => $status, 'record' => $record, 'text' => null];
            if ($status === 'found' || $status === 'not_listed') {
                Cache::put($key, $answer, 3600);
            }

            return $answer;
        }
        if (in_array($tld, self::UNSUPPORTED, true)) {
            return ['status' => 'unsupported', 'record' => null, 'text' => null];
        }
        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }
        [$status, $record] = $this->rdap()?->lookup($fqdn) ?? ['unsupported', null];
        $answer = ['status' => $status, 'record' => $record, 'text' => null];
        if ($status !== 'found' && $status !== 'not_found') {
            $socket = $this->socket ?? app(SocketConnector::class);
            $server = Cache::remember('domain-whois-server:' . $tld, 86400, fn () => (new Port43($socket))->serverFor($tld) ?? '');
            [$status, $text] = $server === '' ? ['unsupported', null] : (new Port43($socket))->lookup($fqdn, $server);
            $answer = ['status' => $status, 'record' => null, 'text' => $text];
        }
        if ($answer['status'] === 'found' || $answer['status'] === 'not_found') {
            Cache::put($key, $answer, 3600);
        }

        return $answer;
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
        if ($this->rdapTried) {
            return $this->rdap;
        }
        $this->rdapTried = true;
        $transport = new LaravelTransport;
        $json = Cache::get('domain-rdap-bootstrap');
        // IANA failed a moment ago: not asked again for a minute (no one makes us hammer it).
        if ($json === null && !Cache::has('domain-rdap-bootstrap-failed')) {
            [$status, $body] = $transport->get(RdapBootstrap::URL);
            // Kept only once it parses (a captive or broken answer would turn RDAP off for a day).
            if ($status === 200 && RdapBootstrap::fromJson($body) !== null) {
                Cache::put('domain-rdap-bootstrap', $json = $body, 86400);
            } else {
                Cache::put('domain-rdap-bootstrap-failed', true, 60);
            }
        }
        $bootstrap = $json === null ? null : RdapBootstrap::fromJson($json);

        return $this->rdap = $bootstrap === null ? null : new RdapClient($transport, $bootstrap);
    }
}
