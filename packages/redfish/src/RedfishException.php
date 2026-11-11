<?php

namespace MyCS\Redfish;

use RuntimeException;

final class RedfishException extends RuntimeException
{
    /** The code of a change the BMC never answered: sent, and unknown whether done. */
    public const UNANSWERED = -1;
}
