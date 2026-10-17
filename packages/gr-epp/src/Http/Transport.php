<?php

namespace MyCS\GrEpp\Http;

/** The HTTP seam (tests run without the network): one POST of an EPP frame. */
interface Transport
{
    /** @param  array<string, string>  $headers  name => value */
    /** $body may hold the account's password (login): never in a trace. */
    public function post(string $url, #[\SensitiveParameter] string $body, array $headers): HttpResponse;
}
