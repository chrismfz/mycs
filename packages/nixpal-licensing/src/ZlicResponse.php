<?php

namespace Nixpal\Licensing;

final class ZlicResponse
{
    /** @var int */
    public $httpStatus;

    /** @var array */
    public $body;

    public function __construct($httpStatus, array $body)
    {
        $this->httpStatus = $httpStatus;
        $this->body = $body;
    }

    /** Same encoding as zlic.php: json_encode() with default flags. */
    public function json()
    {
        return json_encode($this->body);
    }
}
