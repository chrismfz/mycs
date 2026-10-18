<?php

namespace MyCS\GrEpp\Command;

use MyCS\GrEpp\Data\Result;
use MyCS\GrEpp\Response;

/** host:update (the guide's examples 25, 26): addresses added and removed (glue), or a new name under the same domain. */
final class HostUpdate
{
    /**
     * @param  list<string>  $add
     * @param  list<string>  $remove
     */
    public static function request(string $name, array $add, array $remove, string $clTRID, ?string $newName = null): string
    {
        Rules::notEmpty($add !== [] || $remove !== [] || $newName !== null);
        $command = Builder::start('update', 'host');
        $command->add($command->object, 'host', 'name', Rules::name($name));
        foreach (['add' => $add, 'rem' => $remove] as $op => $addresses) {
            if ($addresses !== []) {
                $element = $command->add($command->object, 'host', $op);
                foreach (HostCreate::addresses($name, $addresses, removing: $op === 'rem') as [$ip, $version]) {
                    $command->add($element, 'host', 'addr', $ip)->setAttribute('ip', $version);
                }
            }
        }
        if ($newName !== null) {
            $command->add($command->add($command->object, 'host', 'chg'), 'host', 'name', Rules::name($newName));
        }

        return $command->finish($clTRID);
    }

    public static function parse(Response $response): Result
    {
        return Results::of($response);
    }
}
