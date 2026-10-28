<?php

namespace MyCS\Proxmox\Tests;

use InvalidArgumentException;
use MyCS\Proxmox\WebSocket;
use PHPUnit\Framework\TestCase;

class WebSocketTest extends TestCase
{
    public function test_the_accept_key_of_rfc_6455(): void
    {
        $this->assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', WebSocket::accept('dGhlIHNhbXBsZSBub25jZQ=='));
    }

    public function test_frames_go_through_masked_or_not_at_every_length(): void
    {
        foreach ([0, 5, 125, 126, 65535, 65536, 200000] as $length) {
            $payload = $length > 0 ? random_bytes($length) : '';
            foreach ([true, false] as $masked) {
                $buffer = WebSocket::encode(WebSocket::BINARY, $payload, $masked) . 'next';
                $frame = WebSocket::decode($buffer, $masked, 1 << 20);
                $this->assertSame(['fin' => true, 'opcode' => WebSocket::BINARY, 'payload' => $payload], $frame, "{$length} bytes, masked: " . (int) $masked);
                $this->assertSame('next', $buffer, 'the rest is left');
            }
        }
    }

    public function test_a_frame_still_coming_is_waited_for(): void
    {
        $whole = WebSocket::encode(WebSocket::BINARY, str_repeat('x', 300), true);
        for ($cut = 0; $cut < strlen($whole); $cut += 7) {
            $buffer = substr($whole, 0, $cut);
            $this->assertNull(WebSocket::decode($buffer, true, 1024));
            $this->assertSame(substr($whole, 0, $cut), $buffer, 'nothing taken');
        }
    }

    public function test_what_breaks_the_protocol_is_refused(): void
    {
        $cases = [
            'unmasked from a client' => [WebSocket::encode(WebSocket::BINARY, 'a', false), true],
            'masked from a server' => [WebSocket::encode(WebSocket::BINARY, 'a', true), false],
            'too large' => [WebSocket::encode(WebSocket::BINARY, str_repeat('a', 2000), true), true],
            'reserved bits' => [chr(0xC2) . chr(0x80) . 'abcd', true],
            'fragmented ping' => [WebSocket::encode(WebSocket::PING, 'a', true, false), true],
            'unknown opcode' => [chr(0x83) . chr(0x80) . 'abcd', true],
        ];
        foreach ($cases as $name => [$buffer, $masked]) {
            try {
                WebSocket::decode($buffer, $masked, 1024);
                $this->fail("{$name} was read");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_http_head_is_read_once_whole(): void
    {
        $buffer = "GET /console/ws?token=abc HTTP/1.1\r\nHost: example.test\r\nUpgrade: websocket\r\nSec-WebSocket-Key: k\r\n";
        $this->assertNull(WebSocket::head($buffer));
        $buffer .= "\r\n" . 'frame';
        $this->assertSame(['GET /console/ws?token=abc HTTP/1.1', ['host' => 'example.test', 'upgrade' => 'websocket', 'sec-websocket-key' => 'k']], WebSocket::head($buffer));
        $this->assertSame('frame', $buffer);

        $long = str_repeat('a', WebSocket::MAX_HEAD + 1);
        $this->expectException(InvalidArgumentException::class);
        WebSocket::head($long);
    }

    public function test_a_control_character_in_a_head_is_refused(): void
    {
        $buffer = "GET / HTTP/1.1\r\nOrigin: https://a.example.test\n[forged] line\r\n\r\n";
        $this->expectException(InvalidArgumentException::class);
        WebSocket::head($buffer);
    }
}
