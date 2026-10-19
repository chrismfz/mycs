<?php

namespace MyCS\GrEpp\Command;

use MyCS\GrEpp\Data\DsRecord;
use MyCS\GrEpp\Data\Result;
use MyCS\GrEpp\Response;

/**
 * A domain's DS records changed (domain:update with secDNS-1.1's update, the registry's DNSSEC guide v1.5): those to
 * remove (or all), then those to add, as RFC 5910 §5.2.5 orders them. Up to 16 each. Only on an update: never with a
 * create (2101). Added ones answer 1001: the registry checks them against the domain's nameservers (every 20 minutes),
 * the domain serverUpdateProhibited until then; all are refused if one does not match a signed DNSKEY there.
 */
final class DomainSecDns
{
    /**
     * @param  list<DsRecord>  $add
     * @param  list<DsRecord>|true  $remove  true: all of them
     */
    public static function request(string $name, string $clTRID, array $add = [], array|true $remove = []): string
    {
        $add = Rules::dsRecords($add);
        if ($remove !== true) {
            $remove = Rules::dsRecords($remove);
        }
        Rules::notEmpty($add !== [] || $remove === true || $remove !== []);
        foreach ($add as $record) {
            if ($remove !== true && array_filter($remove, fn (DsRecord $r) => $r->same($record)) !== []) {
                throw new \InvalidArgumentException("A DS record both added and removed (key tag {$record->keyTag}).");
            }
        }
        $command = Builder::start('update', 'domain');
        $command->add($command->object, 'domain', 'name', Rules::name($name));
        $update = $command->extension('secDNS', 'update');
        if ($remove === true || $remove !== []) {
            $rem = $command->add($update, 'secDNS', 'rem');
            if ($remove === true) {
                $command->add($rem, 'secDNS', 'all', 'true');
            } else {
                self::records($command, $rem, $remove);
            }
        }
        if ($add !== []) {
            self::records($command, $command->add($update, 'secDNS', 'add'), $add);
        }

        return $command->finish($clTRID);
    }

    public static function parse(Response $response): Result
    {
        return Results::of($response);
    }

    /** @param  list<DsRecord>  $records */
    private static function records(Builder $command, \DOMElement $parent, array $records): void
    {
        foreach ($records as $record) {
            $ds = $command->add($parent, 'secDNS', 'dsData');
            $command->add($ds, 'secDNS', 'keyTag', (string) $record->keyTag);
            $command->add($ds, 'secDNS', 'alg', (string) $record->algorithm);
            $command->add($ds, 'secDNS', 'digestType', (string) $record->digestType);
            $command->add($ds, 'secDNS', 'digest', $record->digest);
        }
    }
}
