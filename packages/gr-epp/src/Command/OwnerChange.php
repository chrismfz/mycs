<?php

namespace MyCS\GrEpp\Command;

use InvalidArgumentException;
use MyCS\GrEpp\Data\Result;
use MyCS\GrEpp\Response;

/**
 * The registrant of a domain changed (the guide's examples 44–48): «ownerNameChange», the same holder's name corrected
 * or changed, or «ownerChange», another holder (the new registrant one of our contacts). Both chargeable and never
 * recalled; immediate (1000), or 1001 awaiting EETT for .gov.gr and geographic terms.
 */
final class OwnerChange
{
    public const NAME_CHANGE = 'ownerNameChange';

    public const OWNER_CHANGE = 'ownerChange';

    public static function request(string $name, string $registrant, string $op, string $clTRID): string
    {
        if (!in_array($op, [self::NAME_CHANGE, self::OWNER_CHANGE], true)) {
            throw new InvalidArgumentException("Not an owner change: {$op}");
        }
        $command = Builder::start('update', 'domain');
        $command->add($command->object, 'domain', 'name', Rules::name($name));
        $command->add($command->add($command->object, 'domain', 'chg'), 'domain', 'registrant', Rules::contactId($registrant));
        $command->add($command->extension('extdomain', 'update'), 'extdomain', 'op', $op);

        return $command->finish($clTRID);
    }

    public static function parse(Response $response): Result
    {
        return Results::of($response);
    }
}
