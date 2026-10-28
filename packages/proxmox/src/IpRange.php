<?php

namespace MyCS\Proxmox;

use InvalidArgumentException;

/**
 * The addresses of an IP pool (docs/proxmox.md, B3): a network's IPv4 hosts, a range, a mask either way, and whether an
 * address is in a network (IPv4 or IPv6). Framework-free.
 */
final class IpRange
{
    /** A pool never grows by more than this at once (a /20 of IPv4): a mistyped /8 is refused, not sixteen million rows. */
    public const MAX = 4096;

    /** «a.b.c.d/nn» or «x::/nn», normalized; refused when it is neither or its address is not the network's own. */
    public static function network(string $cidr): string
    {
        [$address, $length] = self::split($cidr);
        $packed = (string) inet_pton($address);
        if ($packed !== self::masked($packed, $length)) {
            throw new InvalidArgumentException("{$cidr} is not a network: its address has host bits set.");
        }

        return inet_ntop($packed) . '/' . $length;
    }

    /** The network an address is in, at that length: «192.0.2.77» and 24 → «192.0.2.0/24». */
    public static function networkOf(string $address, int $length): string
    {
        [$address] = self::split($address . '/' . $length);
        $packed = self::masked((string) inet_pton($address), $length);

        return inet_ntop($packed) . '/' . $length;
    }

    /**
     * A network's IPv4 hosts: without its network and broadcast addresses, except a /31 or /32 (RFC 3021), and without the
     * gateway when given.
     *
     * @return list<string>
     */
    public static function hosts(string $cidr, ?string $except = null): array
    {
        [$address, $length] = self::split(self::network($cidr));
        if (!self::isV4($address)) {
            throw new InvalidArgumentException('Only an IPv4 network is listed address by address; an IPv6 prefix is added as one.');
        }
        $first = (int) ip2long($address);
        $last = $first + (2 ** (32 - $length)) - 1;
        if ($length < 31) {
            $first++;
            $last--;
        }

        return self::between($first, $last, $except);
    }

    /**
     * From one IPv4 address to another, both included.
     *
     * @return list<string>
     */
    public static function range(string $from, string $to, ?string $except = null): array
    {
        if (!self::isV4($from) || !self::isV4($to)) {
            throw new InvalidArgumentException('A range is of two IPv4 addresses.');
        }
        $first = (int) ip2long($from);
        $last = (int) ip2long($to);
        if ($last < $first) {
            throw new InvalidArgumentException("{$to} comes before {$from}.");
        }

        return self::between($first, $last, $except);
    }

    /** «255.255.255.0» or «24» → 24. */
    public static function prefix(string $mask): int
    {
        $mask = trim($mask);
        if (preg_match('/^\/?(\d{1,3})$/', $mask, $m)) {
            $length = (int) $m[1];
            if ($length > 128) {
                throw new InvalidArgumentException("{$mask} is not a prefix length.");
            }

            return $length;
        }
        if (!self::isV4($mask)) {
            throw new InvalidArgumentException("{$mask} is not a netmask.");
        }
        $bits = sprintf('%032b', ip2long($mask));
        if (!preg_match('/^1*0*$/', $bits)) {
            throw new InvalidArgumentException("{$mask} is not a contiguous netmask.");
        }

        return substr_count($bits, '1');
    }

    /** 24 → «255.255.255.0» (IPv4 only). */
    public static function netmask(int $prefix): string
    {
        if ($prefix < 0 || $prefix > 32) {
            throw new InvalidArgumentException("/{$prefix} is not an IPv4 prefix.");
        }

        return (string) long2ip($prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);
    }

    /**
     * Whether an address, or a whole prefix, is in a network of the same family: a prefix wider than the network is not
     * (ADV-505-10).
     */
    public static function contains(string $cidr, string $address): bool
    {
        [$network, $length] = self::split($cidr);
        $parts = explode('/', $address, 2);
        $address = $parts[0];
        if (filter_var($address, FILTER_VALIDATE_IP) === false || self::isV4($network) !== self::isV4($address)) {
            return false;
        }
        if (isset($parts[1]) && (!ctype_digit($parts[1]) || (int) $parts[1] < $length)) {
            return false;
        }

        return self::masked((string) inet_pton($address), $length) === self::masked((string) inet_pton($network), $length);
    }

    /** Whether two networks (or prefixes) share an address: one holds the other. */
    public static function overlaps(string $a, string $b): bool
    {
        return self::contains($a, $b) || self::contains($b, $a);
    }

    /**
     * An IPv4 network's first and last address (its network and broadcast address, below /31).
     *
     * @return array{0: string, 1: string}
     */
    public static function bounds(string $cidr): array
    {
        [$address, $length] = self::split(self::network($cidr));
        if (!self::isV4($address)) {
            throw new InvalidArgumentException('Only an IPv4 network has a broadcast address.');
        }
        $first = (int) ip2long($address);

        return [(string) long2ip($first), (string) long2ip($first + (2 ** (32 - $length)) - 1)];
    }

    /** The address on its own, normalized (an IPv6 one compressed, in lower case); a prefix keeps its length. */
    public static function normalize(string $address): string
    {
        $address = trim($address);
        if (str_contains($address, '/')) {
            return self::network($address);
        }
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException("{$address} is not an IP address.");
        }

        return (string) inet_ntop((string) inet_pton($address));
    }

    public static function isV4(string $address): bool
    {
        return filter_var(explode('/', $address, 2)[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /** @return array{0: string, 1: int} */
    private static function split(string $cidr): array
    {
        $parts = explode('/', trim($cidr), 2);
        if (count($parts) !== 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false || !ctype_digit($parts[1])) {
            throw new InvalidArgumentException("{$cidr} is not a network (address/length).");
        }
        $length = (int) $parts[1];
        if ($length > (self::isV4($parts[0]) ? 32 : 128)) {
            throw new InvalidArgumentException("{$cidr}: /{$length} is too long for its family.");
        }

        return [$parts[0], $length];
    }

    private static function masked(string $packed, int $length): string
    {
        $out = '';
        foreach (str_split($packed) as $i => $byte) {
            $bits = max(0, min(8, $length - 8 * $i));
            $out .= chr(ord($byte) & ((0xFF << (8 - $bits)) & 0xFF));
        }

        return $out;
    }

    /** @return list<string> */
    private static function between(int $first, int $last, ?string $except): array
    {
        if ($last - $first + 1 > self::MAX) {
            throw new InvalidArgumentException('More than ' . self::MAX . ' addresses at once.');
        }
        $skip = $except !== null && self::isV4($except) ? ip2long($except) : null;
        $out = [];
        for ($ip = $first; $ip <= $last; $ip++) {
            if ($ip !== $skip) {
                $out[] = (string) long2ip($ip);
            }
        }

        return $out;
    }
}
