<?php

namespace MyCS\GrEpp;

/**
 * The registry answered and refused (a 2xxx result that is not about the session or the server): nothing was done,
 * nothing charged. Its code and message as the registry gave them (in the session's language).
 */
final class Refused extends GrEppException
{
    /** @param  list<string>  $comments  the registry's reasons (extdomain/extcommon comments: «Σε Χρήση, Ταυτόσημο όνομα χώρου») */
    public function __construct(public readonly int $resultCode, public readonly string $registryMessage, public readonly ?string $svTRID = null,
        public readonly array $comments = [])
    {
        parent::__construct("The registry refused ({$resultCode}): {$registryMessage}" . ($comments !== [] ? ' — ' . implode(' ', $comments) : ''));
    }
}
