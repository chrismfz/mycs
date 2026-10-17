<?php

namespace MyCS\GrEpp;

/**
 * The command was sent and no usable answer came back (no connection after sending, a timeout, HTTP not 200, XML not
 * readable, 2400 «command failed», 25xx «closing»): it may have been done. Never sent again blindly (D-17): a charge
 * stays unconfirmed until someone checks the registry.
 */
final class OutcomeUnknown extends GrEppException
{
    public function __construct(string $message, public readonly ?int $resultCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
