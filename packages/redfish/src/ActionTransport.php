<?php

namespace MyCS\Redfish;

/**
 * A transport that may also change something on the BMC (a reset, the locate light, the next boot). The client's
 * actions need one; given a plain Transport it can only read, whatever is called on it.
 */
interface ActionTransport extends Transport
{
    /**
     * @param  'POST'|'PATCH'  $method
     * @param  string  $path  «/redfish/v1/...», on the one BMC this transport is for
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $json  the body
     * @return array{0: int, 1: string} [HTTP status, body]; status 0 when the BMC could not be reached
     */
    public function send(string $method, string $path, array $headers, array $json): array;
}
