<?php

namespace MyCS\Proxmox\Tests\Console;

use MyCS\Proxmox\Console\AsyncHttp;
use PHPUnit\Framework\TestCase;

/** The relay's requests, on a real local server: begun at once, answered from `poll`, a host sent to the given IP. */
class AsyncHttpTest extends TestCase
{
    /** @return array{0: int, 1: string, 2: string|null, 3: string} status, body, error, and the request the server read */
    private function exchange(string $answer, string $host = '127.0.0.1', ?string $ip = null): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($server, false), strrpos((string) stream_socket_get_name($server, false), ':') + 1);
        $http = new AsyncHttp;
        $result = null;
        $started = microtime(true);
        $http->request('POST', "http://{$host}:{$port}/check", ['X-Test: 1'], '{"a":1}', ['ip' => $ip], function (int $status, string $body, ?string $error) use (&$result) {
            $result = [$status, $body, $error];
        });
        $this->assertLessThan(0.5, microtime(true) - $started, 'begun, not waited for');
        $request = '';
        $client = null;
        for ($i = 0; $i < 400 && $result === null; $i++) {
            $http->poll();
            $client ??= @stream_socket_accept($server, 0.005) ?: null;
            if ($client !== null && !str_contains($request, '{"a":1}')) {
                stream_set_blocking($client, false);
                $request .= (string) fread($client, 65536);
                if (str_contains($request, '{"a":1}')) {
                    fwrite($client, $answer);
                    fclose($client);
                }
            }
            usleep(5000);
        }
        fclose($server);

        return [...($result ?? [-1, '', 'no answer']), $request];
    }

    public function test_a_request_is_answered_from_poll(): void
    {
        [$status, $body, $error, $request] = $this->exchange("HTTP/1.1 200 OK\r\nContent-Length: 13\r\nConnection: close\r\n\r\n{\"allowed\":1}");

        $this->assertSame([200, '{"allowed":1}', null], [$status, $body, $error]);
        $this->assertStringStartsWith('POST /check HTTP/1.1', $request);
        $this->assertStringContainsString('X-Test: 1', $request);
    }

    public function test_a_host_goes_to_its_ip_with_its_name_kept(): void
    {
        [$status, , , $request] = $this->exchange("HTTP/1.1 204 No Content\r\nConnection: close\r\n\r\n", 'pve1.example.test', '127.0.0.1');

        $this->assertSame(204, $status);
        $this->assertMatchesRegularExpression('/Host: pve1\.example\.test:\d+/', $request);
    }

    public function test_an_answer_too_large_is_an_error_not_kept(): void
    {
        $size = AsyncHttp::MAX_BODY + 10;
        [$status, $body, $error] = $this->exchange("HTTP/1.1 200 OK\r\nContent-Length: {$size}\r\nConnection: close\r\n\r\n" . str_repeat('x', $size));

        $this->assertSame([0, ''], [$status, $body]);
        $this->assertNotNull($error);
    }
}
