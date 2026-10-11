<?php

namespace Nixpal\DomainTools\Whois;

/**
 * The public, non-personal part of an RDAP domain answer (RFC 9083): registrar, dates,
 * status, nameservers, DNSSEC. Contacts are left out on purpose.
 */
final class RdapRecord
{
    /** @var string */
    public $domain = '';

    /** @var string|null */
    public $registrar;

    /** @var string|null IANA registrar ID */
    public $registrar_iana_id;

    /** @var string|null */
    public $registrar_url;

    /** @var string|null ISO 8601 dates as the registry gives them */
    public $registered;

    /** @var string|null */
    public $expires;

    /** @var string|null */
    public $changed;

    /** @var string[] */
    public $status = [];

    /** @var string[] */
    public $nameservers = [];

    /** @var bool|null */
    public $dnssec;

    /** @var string|null the registrar's own RDAP answer, when the registry links to it */
    public $related_url;

    /** @return self|null null unless it is a domain object */
    public static function fromArray(array $data)
    {
        if (($data['objectClassName'] ?? null) !== 'domain') {
            return null;
        }
        $record = new self;
        $record->domain = strtolower((string) ($data['ldhName'] ?? ''));

        foreach ((array) ($data['events'] ?? []) as $event) {
            $date = is_array($event) ? (string) ($event['eventDate'] ?? '') : '';
            switch (is_array($event) ? ($event['eventAction'] ?? '') : '') {
                case 'registration':
                    $record->registered = $date ?: null;
                    break;
                case 'expiration':
                    $record->expires = $date ?: null;
                    break;
                case 'last changed':
                    $record->changed = $date ?: null;
                    break;
            }
        }
        foreach ((array) ($data['status'] ?? []) as $status) {
            if (is_string($status) && $status !== '') {
                $record->status[] = $status;
            }
        }
        foreach ((array) ($data['nameservers'] ?? []) as $ns) {
            $name = is_array($ns) ? strtolower(rtrim((string) ($ns['ldhName'] ?? ''), '.')) : '';
            if ($name !== '') {
                $record->nameservers[] = $name;
            }
        }
        if (isset($data['secureDNS']['delegationSigned'])) {
            $record->dnssec = (bool) $data['secureDNS']['delegationSigned'];
        }
        foreach ((array) ($data['entities'] ?? []) as $entity) {
            if (!is_array($entity) || !in_array('registrar', (array) ($entity['roles'] ?? []), true)) {
                continue;
            }
            $record->registrar = self::vcardName($entity['vcardArray'] ?? null);
            foreach ((array) ($entity['publicIds'] ?? []) as $id) {
                if (is_array($id) && stripos((string) ($id['type'] ?? ''), 'IANA') !== false) {
                    $record->registrar_iana_id = (string) ($id['identifier'] ?? '') ?: null;
                }
            }
            foreach ((array) ($entity['links'] ?? []) as $link) {
                if (is_array($link) && ($link['rel'] ?? '') === 'about' && stripos((string) ($link['href'] ?? ''), 'http') === 0) {
                    $record->registrar_url = (string) $link['href'];
                }
            }
            break;
        }
        foreach ((array) ($data['links'] ?? []) as $link) {
            $type = is_array($link) ? (string) ($link['type'] ?? '') : '';
            if (is_array($link) && ($link['rel'] ?? '') === 'related' && stripos($type, 'rdap') !== false
                && stripos((string) ($link['href'] ?? ''), 'https://') === 0) {
                $record->related_url = (string) $link['href'];
            }
        }

        return $record;
    }

    /** Fills what the registry left out (thin registries) from the registrar's answer. */
    public function mergeFrom(self $other)
    {
        foreach (['registrar', 'registrar_iana_id', 'registrar_url', 'registered', 'expires', 'changed', 'dnssec'] as $field) {
            if ($this->{$field} === null && $other->{$field} !== null) {
                $this->{$field} = $other->{$field};
            }
        }
        if (!$this->nameservers) {
            $this->nameservers = $other->nameservers;
        }
        if (!$this->status) {
            $this->status = $other->status;
        }
    }

    private static function vcardName($vcard)
    {
        if (!is_array($vcard) || !isset($vcard[1]) || !is_array($vcard[1])) {
            return null;
        }
        foreach ($vcard[1] as $property) {
            if (is_array($property) && ($property[0] ?? null) === 'fn' && isset($property[3]) && is_string($property[3])) {
                return trim($property[3]) ?: null;
            }
        }

        return null;
    }
}
