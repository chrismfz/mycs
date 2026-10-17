<?php

namespace MyCS\GrEpp;

/**
 * The command never reached the registry (no session: the login was refused or failed, the connection never opened):
 * nothing done, nothing charged; safe to send again once fixed.
 */
final class NotSent extends GrEppException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
