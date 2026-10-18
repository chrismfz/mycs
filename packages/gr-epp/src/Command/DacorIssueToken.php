<?php

namespace MyCS\GrEpp\Command;

use MyCS\GrEpp\Data\Result;
use MyCS\GrEpp\Response;

/**
 * DACoR (dacor-1.0, grepp.md §5.12): the registry emails the registrant a link to reset the name's authorisation code.
 *
 * Nothing comes back but the masked address it went to («a***@b***.tld», in the comment); any registrar may ask.
 */
final class DacorIssueToken
{
    public static function request(string $name, string $clTRID): string
    {
        $command = Builder::start('update', 'domain');
        $command->add($command->object, 'domain', 'name', $name);
        $command->extension('dacor', 'issueToken');

        return $command->finish($clTRID);
    }

    public static function parse(Response $response): Result
    {
        return Results::of($response);
    }
}
