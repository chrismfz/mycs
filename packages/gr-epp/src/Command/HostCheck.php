<?php

namespace MyCS\GrEpp\Command;

use DOMElement;
use MyCS\GrEpp\Data\CheckResult;
use MyCS\GrEpp\Response;

/** host:check (the guide's examples 19, 20): whether a nameserver is registered at the registry. */
final class HostCheck
{
    /** @param  list<string>  $names */
    public static function request(array $names, string $clTRID): string
    {
        $command = Builder::start('check', 'host');
        foreach ($names as $name) {
            $command->add($command->object, 'host', 'name', $name);
        }

        return $command->finish($clTRID);
    }

    /** @return array<string, CheckResult> by name */
    public static function parse(Response $response): array
    {
        $xpath = $response->xpath();
        $data = Read::required($response, '//host:chkData');
        $comments = Read::comments($xpath, '//exthost:resData/exthost:comment');
        $results = [];
        foreach ($xpath->query('host:cd', $data) as $cd) {
            $name = $xpath->query('host:name', $cd)->item(0);
            if ($name instanceof DOMElement) {
                $text = mb_strtolower(trim($name->textContent));
                $results[$text] = new CheckResult($text, in_array($name->getAttribute('avail'), ['1', 'true'], true),
                    Read::text($xpath, 'host:reason', $cd), $comments[$text] ?? null);
            }
        }

        return $results;
    }
}
