<?php

namespace MyCS\Proxmox;

use InvalidArgumentException;

/**
 * The bits of RFC 6455 the console relay needs (docs/proxmox.md, B6): the handshake both ways, and frames read from a
 * buffer and written again. Framework-free; the relay's loop owns the sockets.
 */
final class WebSocket
{
    public const CONTINUATION = 0x0;

    public const TEXT = 0x1;

    public const BINARY = 0x2;

    public const CLOSE = 0x8;

    public const PING = 0x9;

    public const PONG = 0xA;

    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** The largest HTTP head read: a handshake is a few hundred bytes. */
    public const MAX_HEAD = 8192;

    public static function accept(string $key): string
    {
        return base64_encode(sha1($key . self::GUID, true));
    }

    public static function key(): string
    {
        return base64_encode(random_bytes(16));
    }

    /**
     * An HTTP head off the front of the buffer, once whole: [first line, headers by lower-case name]; null while it is
     * still coming.
     *
     * @return array{0: string, 1: array<string, string>}|null
     *
     * @throws InvalidArgumentException a head too long
     */
    public static function head(string &$buffer): ?array
    {
        $end = strpos($buffer, "\r\n\r\n");
        if ($end === false) {
            if (strlen($buffer) > self::MAX_HEAD) {
                throw new InvalidArgumentException('HTTP head too long.');
            }

            return null;
        }
        if ($end > self::MAX_HEAD) {
            throw new InvalidArgumentException('HTTP head too long.');
        }
        $lines = explode("\r\n", substr($buffer, 0, $end));
        foreach ($lines as $line) {
            // A lone LF or another control character inside a line is no HTTP of a browser's or a node's: it would
            // carry its own lines into a log (review of #506).
            if (preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $line) === 1) {
                throw new InvalidArgumentException('A control character in the HTTP head.');
            }
        }
        $buffer = (string) substr($buffer, $end + 4);
        $first = (string) array_shift($lines);
        $headers = [];
        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return [$first, $headers];
    }

    /** One frame, whole (FIN), masked as a client's must be and a server's never is. */
    public static function encode(int $opcode, string $payload, bool $mask, bool $fin = true): string
    {
        $length = strlen($payload);
        $head = chr(($fin ? 0x80 : 0) | ($opcode & 0x0F));
        $bit = $mask ? 0x80 : 0;
        $head .= match (true) {
            $length < 126 => chr($bit | $length),
            $length < 65536 => chr($bit | 126) . pack('n', $length),
            default => chr($bit | 127) . pack('J', $length),
        };
        if (!$mask) {
            return $head . $payload;
        }
        $key = random_bytes(4);

        return $head . $key . ($payload ^ str_repeat($key, intdiv($length, 4) + 1));
    }

    /**
     * A frame off the front of the buffer, once whole, unmasked: null while it is still coming.
     *
     * @return array{fin: bool, opcode: int, payload: string}|null
     *
     * @throws InvalidArgumentException a frame larger than $max, reserved bits, a control frame fragmented or too long, or
     *                                  one masked (or not) against $masked
     */
    public static function decode(string &$buffer, bool $masked, int $max): ?array
    {
        if (strlen($buffer) < 2) {
            return null;
        }
        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        if (($first & 0x70) !== 0) {
            throw new InvalidArgumentException('Reserved bits set (no extension is agreed).');
        }
        $opcode = $first & 0x0F;
        $fin = ($first & 0x80) !== 0;
        if (in_array($opcode, [0x3, 0x4, 0x5, 0x6, 0x7, 0xB, 0xC, 0xD, 0xE, 0xF], true)) {
            throw new InvalidArgumentException("Unknown opcode {$opcode}.");
        }
        if ((($second & 0x80) !== 0) !== $masked) {
            throw new InvalidArgumentException($masked ? 'A client frame must be masked.' : 'A server frame must not be masked.');
        }
        $length = $second & 0x7F;
        $offset = 2;
        if ($length === 126) {
            if (strlen($buffer) < 4) {
                return null;
            }
            $length = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($buffer) < 10) {
                return null;
            }
            $length = unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;
        }
        if ($opcode >= 0x8 && (!$fin || $length > 125)) {
            throw new InvalidArgumentException('A control frame is never fragmented nor over 125 bytes.');
        }
        if ($length < 0 || $length > $max) {
            throw new InvalidArgumentException("Frame of {$length} bytes, over {$max}.");
        }
        $key = '';
        if ($masked) {
            if (strlen($buffer) < $offset + 4) {
                return null;
            }
            $key = substr($buffer, $offset, 4);
            $offset += 4;
        }
        if (strlen($buffer) < $offset + $length) {
            return null;
        }
        $payload = (string) substr($buffer, $offset, $length);
        $buffer = (string) substr($buffer, $offset + $length);
        if ($masked && $length > 0) {
            $payload ^= substr(str_repeat($key, intdiv($length, 4) + 1), 0, $length);
        }

        return ['fin' => $fin, 'opcode' => $opcode, 'payload' => $payload];
    }
}
