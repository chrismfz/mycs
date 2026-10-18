<?php

namespace MyCS\GrEpp\Http;

use Closure;
use MyCS\GrEpp\Trace;
use Throwable;

/**
 * A transport that tells each exchange to $tap, after it: the summary (command, clTRID, svTRID, code), and its frames
 * with their secrets hidden (Trace::redact) when the tap asks for them (redacted only then: review 3 of #193). The
 * headers (the session cookie) are never told. A tap that fails never fails the command: its answer is the registry's.
 */
final class TracingTransport implements Transport
{
    /**
     * @param  Closure(array{command: string, clTRID: ?string, svTRID: ?string, code: ?int, http: int}, Closure(): array{0: string, 1: string}, float): void  $tap
     *                                                                                                                                                              (summary, the frames [request, response] redacted, milliseconds)
     */
    public function __construct(private readonly Transport $inner, private readonly Closure $tap) {}

    public function post(string $url, #[\SensitiveParameter] string $body, array $headers): HttpResponse
    {
        $started = microtime(true);
        $response = $this->inner->post($url, $body, $headers);
        try {
            $frames = fn (): array => [Trace::redact($body),
                $response->status === 0 ? '(no answer: ' . ($response->error ?? 'transport error') . ')' : Trace::redact($response->body)];
            ($this->tap)(Trace::summary($body, $response->status, $response->body), $frames, round((microtime(true) - $started) * 1000, 1));
        } catch (Throwable) {
            // A log is never worth a lost answer.
        }

        return $response;
    }
}
