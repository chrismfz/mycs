<?php

namespace Nixpal\DomainTools\Http;

interface Transport
{
    /**
     * A GET request.
     *
     * @return array{0:int,1:string} HTTP status (0 on a network error) and body
     */
    public function get($url, array $headers = [], $timeout = 8);
}
