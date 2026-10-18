<?php

namespace MyCS\GrEpp\Command;

use MyCS\GrEpp\Data\Result;
use MyCS\GrEpp\Response;

/** host:delete (the guide's examples 27, 28): one of ours, used by no domain. */
final class HostDelete
{
    public static function request(string $name, string $clTRID): string
    {
        $command = Builder::start('delete', 'host');
        $command->add($command->object, 'host', 'name', Rules::name($name));

        return $command->finish($clTRID);
    }

    public static function parse(Response $response): Result
    {
        return Results::of($response);
    }
}
