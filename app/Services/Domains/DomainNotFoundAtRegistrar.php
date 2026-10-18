<?php

namespace App\Services\Domains;

use RuntimeException;

/**
 * The registrar account does NOT hold this domain (an empty by-name/by-id
 * answer) — distinct from a transport/auth failure so callers can tell
 * «genuinely not ours» (register may proceed / a taken name is a third
 * party's) from «could not read» (abort, never act blind).
 *
 * $elsewhere: the name exists, held by another registrar (true: the .gr registry's 2201), it does not exist at all
 * (false: 2303, gone), or not known (null: the account simply does not list it).
 */
class DomainNotFoundAtRegistrar extends RuntimeException
{
    public function __construct(string $message = '', public readonly ?bool $elsewhere = null)
    {
        parent::__construct($message);
    }
}
