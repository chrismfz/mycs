<?php

namespace Nixpal\MyOpenprovider\Http;

/**
 * Minimal HTTP seam so the client can be tested without network access.
 */
interface Transport
{
    /**
     * @param  string  $method  GET, POST, PUT, DELETE
     * @param  string  $url  Absolute URL
     * @param  array<string,string>  $headers  Header name => value
     * @param  string|null  $body  Raw request body
     * @return array{0:int,1:string} [HTTP status, raw response body]; status 0 on a transport failure
     */
    public function send($method, $url, array $headers, $body = null);
}
