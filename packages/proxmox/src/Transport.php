<?php

namespace MyCS\Proxmox;

/** The HTTP seam: the app sends it (its TLS and IP rules), the tests answer it. */
interface Transport
{
    /**
     * @param  string  $base  One node's address, «https://host:8006»
     * @param  string  $path  «/api2/json/...»
     * @param  array<string, string>  $headers
     * @return array{0: int, 1: string} [HTTP status, body]; status 0 when the node could not be reached
     */
    public function send(string $method, string $base, string $path, array $headers): array;
}
