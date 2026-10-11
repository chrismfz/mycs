<?php

namespace Nixpal\DomainTools\Whois;

use Nixpal\DomainTools\Http\Transport;

/**
 * IANA's RDAP bootstrap for DNS (RFC 9224): which RDAP server answers for a TLD. The
 * JSON is kept by the caller's cache (it changes rarely); only https base URLs are used.
 */
final class RdapBootstrap
{
    const URL = 'https://data.iana.org/rdap/dns.json';

    /** @var array<string,string> tld => base URL ending in "/" */
    private $servers = [];

    private function __construct(array $servers)
    {
        $this->servers = $servers;
    }

    /** @return self|null null when the JSON is not a bootstrap file */
    public static function fromJson($json)
    {
        $data = json_decode((string) $json, true);
        if (!is_array($data) || !isset($data['services']) || !is_array($data['services'])) {
            return null;
        }
        $servers = [];
        foreach ($data['services'] as $service) {
            if (!is_array($service) || count($service) < 2 || !is_array($service[0]) || !is_array($service[1])) {
                continue;
            }
            $base = null;
            foreach ($service[1] as $url) {
                if (is_string($url) && stripos($url, 'https://') === 0) {
                    $base = rtrim($url, '/') . '/';
                    break;
                }
            }
            if ($base === null) {
                continue;
            }
            foreach ($service[0] as $tld) {
                $servers[strtolower(trim((string) $tld, '.'))] = $base;
            }
        }

        return new self($servers);
    }

    /** Downloads the bootstrap file: [self|null, raw JSON for the cache]. */
    public static function fetch(Transport $http)
    {
        [$status, $body] = $http->get(self::URL, ['Accept: application/json'], 10);
        $bootstrap = $status === 200 ? self::fromJson($body) : null;

        return [$bootstrap, $bootstrap ? $body : null];
    }

    /** The RDAP base URL for a TLD ("co.uk" falls back to "uk"), or null. */
    public function serverFor($tld)
    {
        $tld = strtolower(trim((string) $tld, '.'));
        while ($tld !== '') {
            if (isset($this->servers[$tld])) {
                return $this->servers[$tld];
            }
            $dot = strpos($tld, '.');
            $tld = $dot === false ? '' : substr($tld, $dot + 1);
        }

        return null;
    }
}
