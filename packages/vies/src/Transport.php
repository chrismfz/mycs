<?php

namespace MyCS\Vies;

/** Sends one JSON POST. Tests give their own; CurlTransport is the real one. */
interface Transport
{
    /**
     * @param  array<string, mixed>  $body
     * @return array{status: int, body: string}
     *
     * @throws ViesUnavailable the request could not be made
     */
    public function postJson(string $url, array $body): array;
}
