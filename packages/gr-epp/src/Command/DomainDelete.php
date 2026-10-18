<?php

namespace MyCS\GrEpp\Command;

use InvalidArgumentException;
use MyCS\GrEpp\Data\Result;
use MyCS\GrEpp\Response;
use SensitiveParameter;

/**
 * domain:delete with the registry's operations (the guide's examples 55–60): «deleteDomain» (immediate, for all its
 * forms, never recalled), «deleteHomograph» (a reserved form), both with the authorisation code; «recallApplication»,
 * a registration recalled within 5 days, by its protocol.
 */
final class DomainDelete
{
    public static function delete(string $name, #[SensitiveParameter] string $authCode, string $clTRID, bool $homograph = false): string
    {
        if (trim($authCode) === '') {
            throw new InvalidArgumentException('Deleting a name needs its authorisation code.');
        }
        $command = Builder::start('delete', 'domain');
        $command->add($command->object, 'domain', 'name', Rules::name($name));
        $delete = $command->extension('extdomain', 'delete');
        $command->add($delete, 'extdomain', 'pw', $authCode);
        $command->add($delete, 'extdomain', 'op', $homograph ? 'deleteHomograph' : 'deleteDomain');

        return $command->finish($clTRID);
    }

    /** A registration recalled (within 5 days of it), by the protocol its create answered. */
    public static function recall(string $name, string $protocol, string $clTRID): string
    {
        $protocol = trim($protocol);
        if (preg_match('/^\d{1,20}\z/', $protocol) !== 1) {
            throw new InvalidArgumentException('A recall needs the registration\'s protocol (its number).');
        }
        $command = Builder::start('delete', 'domain');
        $command->add($command->object, 'domain', 'name', Rules::name($name));
        $delete = $command->extension('extdomain', 'delete');
        $command->add($delete, 'extdomain', 'protocol', $protocol);
        $command->add($delete, 'extdomain', 'op', 'recallApplication');

        return $command->finish($clTRID);
    }

    public static function parse(Response $response): Result
    {
        return Results::of($response);
    }
}
