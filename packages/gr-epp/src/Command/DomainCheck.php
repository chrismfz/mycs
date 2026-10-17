<?php

namespace MyCS\GrEpp\Command;

use DOMElement;
use MyCS\GrEpp\Data\CheckResult;
use MyCS\GrEpp\Response;

/**
 * domain:check (grepp.md §5.2, the guide's examples 29–34): free or not, with the registry's reason and comment. The
 * registry's only availability check (its WHOIS API has none). A registrant id asks for .gov.gr and the like, where
 * who registers matters.
 */
final class DomainCheck
{
    /** The registry's limit per check (the guide): more refuses the whole batch (2306). */
    public const MAX = 7;

    /** @param  list<string>  $names */
    public static function request(array $names, string $clTRID, ?string $registrantId = null): string
    {
        Limits::check($names, self::MAX, 'domain names');
        $command = Builder::start('check', 'domain');
        foreach ($names as $name) {
            $command->add($command->object, 'domain', 'name', $name);
        }
        if ($registrantId !== null) {
            $command->add($command->extension('extdomain', 'check'), 'extdomain', 'registrantid', $registrantId);
        }

        return $command->finish($clTRID);
    }

    /** @return array<string, CheckResult> by name, lower case (review 6 of #185) */
    public static function parse(Response $response): array
    {
        $xpath = $response->xpath();
        $data = Read::required($response, '//domain:chkData');
        $comments = array_change_key_case(Read::comments($xpath, '//extdomain:resData/extdomain:comment'), CASE_LOWER);
        $results = [];
        foreach ($xpath->query('domain:cd', $data) as $cd) {
            $name = $xpath->query('domain:name', $cd)->item(0);
            if (!$name instanceof DOMElement) {
                continue;
            }
            $text = mb_strtolower(trim($name->textContent));
            $results[$text] = new CheckResult($text, Read::bool($name->getAttribute('avail')), Read::text($xpath, 'domain:reason', $cd), $comments[$text] ?? null);
        }

        return $results;
    }
}
