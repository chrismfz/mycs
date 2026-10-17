<?php

namespace MyCS\GrEpp\Tests;

use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Http\Transport;

/** The registry, answered from a queue; what was sent is kept (url, body, headers). */
final class FakeTransport implements Transport
{
    /** @var list<array{url: string, body: string, headers: array<string, string>}> */
    public array $sent = [];

    /** @var list<HttpResponse> */
    private array $queue = [];

    public function queue(HttpResponse $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    /** An answer from the registry's examples (tests/fixtures/v4.3), with cookies to set. */
    public function fixture(string $name, array $setCookies = []): self
    {
        return $this->queue(new HttpResponse(200, (string) file_get_contents(__DIR__ . "/fixtures/v4.3/{$name}"), $setCookies));
    }

    /** A response of the given result code. */
    public function result(int $code, string $msg = 'msg', string $extra = ''): self
    {
        return $this->queue(new HttpResponse(200, '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
            . "<result code=\"{$code}\"><msg>{$msg}</msg></result>{$extra}<trID><clTRID>c</clTRID><svTRID>s-{$code}</svTRID></trID></response></epp>"));
    }

    public function post(string $url, string $body, array $headers): HttpResponse
    {
        $this->sent[] = ['url' => $url, 'body' => $body, 'headers' => $headers];

        return array_shift($this->queue) ?? throw new \LogicException('No answer queued for: ' . substr($body, 0, 200));
    }

    /** @return list<string> the EPP element each request carried (hello, login, logout, or the command's) */
    public function kinds(): array
    {
        return array_map(function (array $request): string {
            $document = new \DOMDocument;
            $document->loadXML($request['body']);
            $epp = $document->documentElement;
            $first = $epp?->firstElementChild;
            if ($first?->localName === 'command') {
                return (string) $first->firstElementChild?->localName;
            }

            return (string) $first?->localName;
        }, $this->sent);
    }
}
