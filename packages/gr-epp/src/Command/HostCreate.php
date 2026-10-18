<?php

namespace MyCS\GrEpp\Command;

use InvalidArgumentException;
use MyCS\GrEpp\Data\Result;
use MyCS\GrEpp\Response;

/**
 * host:create (the guide's examples 23, 24): a nameserver the registry does not have yet (a domain's nameservers must
 * exist first). Addresses only for a host under .gr / .ελ (glue); for another, none (else 2308). At most 16.
 */
final class HostCreate
{
    /** @param  list<string>  $addresses  IPv4 and IPv6 */
    public static function request(string $name, array $addresses, string $clTRID): string
    {
        $command = Builder::start('create', 'host');
        $command->add($command->object, 'host', 'name', mb_strtolower($name));
        foreach (self::addresses($name, $addresses) as [$ip, $version]) {
            $command->add($command->object, 'host', 'addr', $ip)->setAttribute('ip', $version);
        }

        return $command->finish($clTRID);
    }

    public static function parse(Response $response): Result
    {
        return Results::of($response);
    }

    /** True for a host under .gr or .ελ (its glue is registered with it). */
    public static function internal(string $name): bool
    {
        return preg_match('/\.(gr|ελ|xn--qxam)$/u', mb_strtolower(rtrim($name, '.'))) === 1;
    }

    /**
     * @param  list<string>  $addresses
     * @return list<array{0: string, 1: string}> [ip, v4|v6]
     */
    public static function addresses(string $host, array $addresses): array
    {
        if ($addresses !== [] && !self::internal($host)) {
            throw new InvalidArgumentException("{$host} is not under .gr or .ελ: the registry takes no addresses for it (2308).");
        }
        if (count($addresses) > 16) {
            throw new InvalidArgumentException('At most 16 addresses per nameserver.');
        }

        return array_map(function (string $ip): array {
            return match (true) {
                filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => [$ip, 'v4'],
                filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => [$ip, 'v6'],
                default => throw new InvalidArgumentException("Not an IP address: {$ip}"),
            };
        }, $addresses);
    }
}
