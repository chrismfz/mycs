<?php

namespace MyCS\GrEpp\Command;

use DOMElement;
use MyCS\GrEpp\Data\HostInfo as Info;
use MyCS\GrEpp\Response;

/** host:info (the guide's examples 21, 22): a nameserver and its addresses (glue, only under .gr / .ελ). */
final class HostInfo
{
    public static function request(string $name, string $clTRID): string
    {
        $command = Builder::start('info', 'host');
        $command->add($command->object, 'host', 'name', $name);

        return $command->finish($clTRID);
    }

    public static function parse(Response $response): Info
    {
        $xpath = $response->xpath();
        $data = Read::required($response, '//host:infData');
        $addresses = [];
        foreach ($xpath->query('host:addr', $data) as $addr) {
            if ($addr instanceof DOMElement) {
                $addresses[] = ['ip' => trim($addr->textContent), 'version' => $addr->getAttribute('ip') ?: 'v4'];
            }
        }

        return new Info(
            name: mb_strtolower((string) Read::text($xpath, 'host:name', $data)),
            roid: (string) Read::text($xpath, 'host:roid', $data),
            statuses: Read::statuses($xpath, 'host:status', $data),
            addresses: $addresses,
            clientId: Read::text($xpath, 'host:clID', $data),
            created: Read::date($xpath, 'host:crDate', $data),
        );
    }
}
