<?php

namespace MyCS\GrEpp\Http;

/** The HTTP seam (tests run without the network): one POST of an EPP frame. */
interface Transport
{
    /** @param  array<string, string>  $headers  name => value */
    public function post(string $url, string $body, array $headers): HttpResponse;
}
