<?php

namespace MyCS\GrEpp\Command;

use MyCS\GrEpp\Data\Result;
use MyCS\GrEpp\Response;

/**
 * contact:delete (the guide's example 17): one of our contacts no domain uses, removed (one in use: 2305; another
 * registrar's: 2201). Free. Used for a contact made for a change the registry then refused.
 */
final class ContactDelete
{
    public static function request(string $id, string $clTRID): string
    {
        $command = Builder::start('delete', 'contact');
        $command->add($command->object, 'contact', 'id', Rules::anyContactId($id));

        return $command->finish($clTRID);
    }

    public static function parse(Response $response): Result
    {
        return Results::of($response);
    }
}
