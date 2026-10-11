<?php

namespace Nixpal\MyOpenprovider;

/**
 * A nameserver host (glue) of a domain as Openprovider's nameserver API takes it:
 * a host name under the domain itself (ns1.example.com for example.com) with an
 * IPv4 and/or an IPv6 address. Pure validation, no Blesta.
 */
final class GlueHost
{
    /** @var string */
    public $name;

    /** @var string */
    public $ip;

    /** @var string */
    public $ip6;

    private function __construct($name, $ip, $ip6)
    {
        $this->name = $name;
        $this->ip = $ip;
        $this->ip6 = $ip6;
    }

    /**
     * @param  string  $name  the host (ns1, ns1.example.com)
     * @param  array  $ips  addresses; the first IPv4 and the first IPv6 are kept
     * @param  string  $domain  the domain the host must belong to
     *
     * @throws \InvalidArgumentException with a reason key (name, ip)
     */
    public static function fromInput($name, array $ips, $domain)
    {
        $domain = strtolower(trim((string) $domain, ' .'));
        $name = strtolower(trim((string) $name, ' .'));
        if ($name !== '' && $domain !== '' && strpos($name, '.') === false) {
            $name .= '.' . $domain;
        }
        $suffix = '.' . $domain;
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        if ($domain === '' || strlen($name) > 253 || substr($name, -strlen($suffix)) !== $suffix
            || !preg_match('/^' . $label . '(\.' . $label . ')*$/', substr($name, 0, -strlen($suffix)))) {
            throw new \InvalidArgumentException('name');
        }

        $ip = '';
        $ip6 = '';
        foreach ($ips as $address) {
            $address = trim((string) $address);
            if ($address === '') {
                continue;
            }
            if (self::local($address)) {
                throw new \InvalidArgumentException('ip');
            }
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE)) {
                $ip = $ip !== '' ? $ip : $address;
            } elseif (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE)) {
                $ip6 = $ip6 !== '' ? $ip6 : strtolower($address);
            } else {
                throw new \InvalidArgumentException('ip');
            }
        }
        if ($ip === '' && $ip6 === '') {
            throw new \InvalidArgumentException('ip');
        }

        return new self($name, $ip, $ip6);
    }

    /** Loopback, unspecified, link-local or multicast: never a nameserver's address. */
    private static function local($address)
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            $first = ord($packed[0]);

            return $first === 0 || $first === 127 || $first >= 224 || strncmp($packed, "\xA9\xFE", 2) === 0;
        }

        return $packed === str_repeat("\0", 16) || $packed === str_repeat("\0", 15) . "\1"
            || ord($packed[0]) === 0xFF || (ord($packed[0]) === 0xFE && (ord($packed[1]) & 0xC0) === 0x80);
    }

    /** The payload of POST /v1beta/dns/nameservers and PUT /v1beta/dns/nameservers/{name}. */
    public function toArray()
    {
        $host = ['name' => $this->name];
        if ($this->ip !== '') {
            $host['ip'] = $this->ip;
        }
        if ($this->ip6 !== '') {
            $host['ip6'] = $this->ip6;
        }

        return $host;
    }
}
