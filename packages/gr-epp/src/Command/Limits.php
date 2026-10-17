<?php

namespace MyCS\GrEpp\Command;

use InvalidArgumentException;

/** The registry's limits per command (the guide): checked before sending, never a 2306 for the whole batch. */
final class Limits
{
    /** @param  list<string>  $items */
    public static function check(array $items, int $max, string $what): void
    {
        if ($items === [] || count($items) > $max) {
            throw new InvalidArgumentException("A check takes 1 to {$max} {$what}.");
        }
    }
}
