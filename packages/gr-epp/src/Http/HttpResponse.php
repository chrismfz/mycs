<?php

namespace MyCS\GrEpp\Http;

/**
 * One HTTP answer: status 0 when nothing came back, with the transport's error and whether the request surely never
 * left (no DNS, no connection, TLS refused) or may have (a timeout, a connection dropped after sending).
 */
final readonly class HttpResponse
{
    /** @param  list<string>  $setCookies  each Set-Cookie header's value, as sent */
    public function __construct(
        public int $status,
        public string $body,
        public array $setCookies = [],
        public ?string $error = null,
        public bool $neverSent = false,
    ) {}
}
