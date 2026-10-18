<?php

namespace MyCS\GrEpp\Http;

use Closure;
use MyCS\GrEpp\Trace;
use Throwable;

/**
 * A transport that tells each exchange to $tap, after it: the summary (command, clTRID, svTRID, code) and both frames
 * with their secrets hidden (Trace::redact). The headers (the session cookie) are never told. A tap that fails never
 * fails the command: its answer is the registry's either way.
 */
final class TracingTransport implements Transport
{
    /**
     * @param  Closure(array{command: string, clTRID: ?string, svTRID: ?string, code: ?int, http: int}, string, string, float): void  $tap
     *                                                                                                                                      (summary, request, response, milliseconds)
     */
    public function __construct(private readonly Transport $inner, private readonly Closure $tap) {}

    public function post(string $url, #[\SensitiveParameter] string $body, array $headers): HttpResponse
    {
        $started = microtime(true);
        $response = $this->inner->post($url, $body, $headers);
        try {
            ($this->tap)(Trace::summary($body, $response->status, $response->body), Trace::redact($body),
                $response->status === 0 ? '(no answer: ' . ($response->error ?? 'transport error') . ')' : Trace::redact($response->body),
                round((microtime(true) - $started) * 1000, 1));
        } catch (Throwable) {
            // A log is never worth a lost answer.
        }

        return $response;
    }
}
