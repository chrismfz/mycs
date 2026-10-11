<?php

namespace Nixpal\MyOpenprovider;

/**
 * An Openprovider API call failed (HTTP error, an error code in the envelope, or no
 * usable answer). The message carries Openprovider's own description.
 */
class OpenproviderException extends \RuntimeException
{
    /** @var bool true when Openprovider answered and refused (nothing was done) */
    private $definitive;

    public function __construct($message, $definitive = true)
    {
        parent::__construct($message);
        $this->definitive = (bool) $definitive;
    }

    /**
     * False when the outcome is unknown: no answer (timeout, connection), a 5xx, or a
     * body that is not an Openprovider envelope. A write may then have happened anyway.
     */
    public function isDefinitive()
    {
        return $this->definitive;
    }
}
