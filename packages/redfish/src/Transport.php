<?php

namespace MyCS\Redfish;

/** The HTTP seam: the app sends it (its TLS and IP rules, the BMC's address), the tests answer it. Only ever a GET (changes go through an ActionTransport). */
interface Transport
{
    /**
     * @param  string  $path  «/redfish/v1/...», on the one BMC this transport is for
     * @param  array<string, string>  $headers
     * @return array{0: int, 1: string} [HTTP status, body]; status 0 when the BMC could not be reached
     */
    public function get(string $path, array $headers): array;
}
