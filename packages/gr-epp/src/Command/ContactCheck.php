<?php

namespace MyCS\GrEpp\Command;

use DOMElement;
use MyCS\GrEpp\Data\CheckResult;
use MyCS\GrEpp\Response;

/** contact:check (the guide's examples 9, 10): whether a contact id is free (ours are «c60_» and up to 12 characters). */
final class ContactCheck
{
    /** @param  list<string>  $ids */
    public static function request(array $ids, string $clTRID): string
    {
        $command = Builder::start('check', 'contact');
        foreach ($ids as $id) {
            $command->add($command->object, 'contact', 'id', $id);
        }

        return $command->finish($clTRID);
    }

    /** @return array<string, CheckResult> by id */
    public static function parse(Response $response): array
    {
        $xpath = $response->xpath();
        $data = Read::required($response, '//contact:chkData');
        $comments = Read::comments($xpath, '//extcontact:resData/extcontact:comment');
        $results = [];
        foreach ($xpath->query('contact:cd', $data) as $cd) {
            $id = $xpath->query('contact:id', $cd)->item(0);
            if ($id instanceof DOMElement) {
                $text = trim($id->textContent);
                $results[$text] = new CheckResult($text, in_array($id->getAttribute('avail'), ['1', 'true'], true),
                    Read::text($xpath, 'contact:reason', $cd), $comments[$text] ?? null);
            }
        }

        return $results;
    }
}
