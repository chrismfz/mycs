<?php

namespace MyCS\Extensions\Servers\Mailcow;

use Illuminate\Support\Facades\Http;
use Nixpal\Mailcow\Http\Transport;

/** The package's transport through Laravel's HTTP client: its timeouts, TLS verified, and Http::fake in tests. */
final class LaravelTransport implements Transport
{
    public function __construct(private readonly int $timeout = 30) {}

    public function send($method, $url, array $headers, $body = null)
    {
        $request = Http::connectTimeout(10)->timeout($this->timeout)->withHeaders($headers);
        $response = strtoupper((string) $method) === 'GET'
            ? $request->get($url)
            : $request->withBody((string) $body, 'application/json')->send(strtoupper((string) $method), $url);

        return [$response->status(), $response->body()];
    }
}
