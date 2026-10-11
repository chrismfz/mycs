<?php

namespace Nixpal\DomainTools\Whois;

use Nixpal\DomainTools\Http\Transport;

/**
 * RDAP lookup of a domain: the registry's answer, completed from the registrar's when the
 * registry links to it (thin registries such as .com keep few fields).
 */
final class RdapClient
{
    /** @var Transport */
    private $http;

    /** @var RdapBootstrap */
    private $bootstrap;

    public function __construct(Transport $http, RdapBootstrap $bootstrap)
    {
        $this->http = $http;
        $this->bootstrap = $bootstrap;
    }

    public function supports($tld)
    {
        return $this->bootstrap->serverFor($tld) !== null;
    }

    /**
     * @return array{0:string,1:RdapRecord|null} "found" with the record, or "not_found",
     *                                           "unsupported", "error"
     */
    public function lookup($domain)
    {
        $domain = strtolower((string) $domain);
        $tld = substr($domain, strpos($domain, '.') + 1);
        $base = $this->bootstrap->serverFor($tld);
        if ($base === null) {
            return ['unsupported', null];
        }

        [$status, $body] = $this->http->get($base . 'domain/' . rawurlencode($domain), ['Accept: application/rdap+json'], 8);
        if ($status === 404) {
            return ['not_found', null];
        }
        $data = $status === 200 ? json_decode($body, true) : null;
        $record = is_array($data) ? RdapRecord::fromArray($data) : null;
        if ($record === null) {
            return ['error', null];
        }

        if ($record->related_url !== null && ($record->registrar === null || $record->expires === null || !$record->nameservers)) {
            [$status, $body] = $this->http->get($record->related_url, ['Accept: application/rdap+json'], 6);
            $related = $status === 200 ? json_decode($body, true) : null;
            if (is_array($related) && ($other = RdapRecord::fromArray($related))) {
                $record->mergeFrom($other);
            }
        }

        return ['found', $record];
    }
}
