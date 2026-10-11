<?php

namespace Nixpal\MyOpenprovider;

/**
 * One DNS record as Openprovider's zone API takes it (name relative to the zone, "" for
 * the apex), validated from form input. Pure: no I/O.
 */
final class DnsRecord
{
    /** Types staff and clients may add or remove (SOA and the zone's own NS are Openprovider's). */
    const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];

    /** TTLs offered (seconds). */
    const TTLS = [900, 3600, 14400, 86400];

    /** @var string */
    public $name;

    /** @var string */
    public $type;

    /** @var string */
    public $value;

    /** @var int */
    public $ttl;

    /** @var int|null */
    public $prio;

    private function __construct($name, $type, $value, $ttl, $prio)
    {
        $this->name = $name;
        $this->type = $type;
        $this->value = $value;
        $this->ttl = $ttl;
        $this->prio = $prio;
    }

    /**
     * @param  array  $input  name, type, value, ttl, prio (form fields)
     * @param  string  $zone  the zone (example.com), to accept a full name or "@" for the apex
     *
     * @throws \InvalidArgumentException with a reason key (type, name, value, ttl, prio)
     */
    public static function fromInput(array $input, $zone)
    {
        $type = strtoupper(trim((string) ($input['type'] ?? '')));
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('type');
        }

        $zone = strtolower(trim((string) $zone, ' .'));
        $name = strtolower(trim((string) ($input['name'] ?? ''), ' .'));
        if ($name === '@' || $name === $zone) {
            $name = '';
        } elseif ($zone !== '' && substr($name, -strlen('.' . $zone)) === '.' . $zone) {
            $name = substr($name, 0, -strlen('.' . $zone));
        }
        if ($name !== '' && !preg_match('/^(\*|[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?)(\.[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?)*$/', $name)) {
            throw new \InvalidArgumentException('name');
        }
        if ($type === 'CNAME' && $name === '') {
            // A CNAME cannot sit at the zone apex.
            throw new \InvalidArgumentException('name');
        }
        if ($type === 'NS' && $name === '') {
            // The zone's own NS set stays Openprovider's (and could not be removed here).
            throw new \InvalidArgumentException('name');
        }

        $value = trim((string) ($input['value'] ?? ''));
        if ($value === '' || strlen($value) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException('value');
        }
        if ($type === 'A' && !filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new \InvalidArgumentException('value');
        }
        if ($type === 'AAAA' && !filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            throw new \InvalidArgumentException('value');
        }
        if (in_array($type, ['CNAME', 'MX', 'NS'], true)) {
            $value = strtolower(rtrim($value, '.'));
            if (!preg_match('/^([a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)+[a-z0-9-]{2,63}$/', $value)) {
                throw new \InvalidArgumentException('value');
            }
        }

        $ttl = (int) ($input['ttl'] ?? 3600);
        if (!in_array($ttl, self::TTLS, true)) {
            throw new \InvalidArgumentException('ttl');
        }

        $prio = null;
        if (in_array($type, ['MX', 'SRV'], true)) {
            $prio = filter_var($input['prio'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 65535]]);
            if ($prio === false) {
                throw new \InvalidArgumentException('prio');
            }
        }

        return new self($name, $type, $value, $ttl, $prio);
    }

    /** A record as Openprovider listed it, its name made relative to the zone (as the update API takes it, also for removal). */
    public static function fromOpenprovider(array $record, $zone)
    {
        $zone = strtolower(trim((string) $zone, ' .'));
        $name = strtolower(rtrim((string) ($record['name'] ?? ''), '.'));
        if ($name === $zone) {
            $name = '';
        } elseif ($zone !== '' && substr($name, -strlen('.' . $zone)) === '.' . $zone) {
            $name = substr($name, 0, -strlen('.' . $zone));
        }
        $prio = isset($record['prio']) && $record['prio'] !== '' ? (int) $record['prio'] : null;

        return new self($name, strtoupper((string) ($record['type'] ?? '')), (string) ($record['value'] ?? ''), (int) ($record['ttl'] ?? 0), $prio);
    }

    /** Whether staff/clients may remove this record (not SOA, not the apex NS set). */
    public function removable()
    {
        return in_array($this->type, self::TYPES, true) && !($this->type === 'NS' && $this->name === '');
    }

    /** The record for Openprovider's zone API. */
    public function toArray()
    {
        $record = ['name' => $this->name, 'type' => $this->type, 'value' => $this->value, 'ttl' => $this->ttl];
        if ($this->prio !== null) {
            $record['prio'] = $this->prio;
        }

        return $record;
    }

    /** A stable key for one record (for remove buttons). */
    public function key()
    {
        return sha1(json_encode($this->toArray()));
    }
}
