<?php

namespace App\Services\Proxmox;

use Closure;
use InvalidArgumentException;
use MyCS\Proxmox\WebSocket;
use Throwable;

/**
 * The customer's console (docs/proxmox.md, B6): a websocket from the browser, behind nginx, to a guest's VNC websocket
 * on its node, which only the token may open (the browser can send no header). One process, every console in one loop.
 *
 * A browser's connection opens only with a ticket ConsoleTickets gave for it a few seconds before, once, from this site
 * (its Origin); then its frames go to the node and the node's to it, each read whole and written again (a client's
 * masked, a server's not). Pings are answered where they arrive. A console ends when either side closes, after
 * IDLE without a frame, or MAX_SESSION in all.
 */
final class ConsoleRelay
{
    public const MAX_CONNECTIONS = 64;

    /** A VNC frame is a screen update: a few MB at most. */
    public const MAX_FRAME = 16 * 1024 * 1024;

    public const HANDSHAKE = 10;

    public const IDLE = 1800;

    public const MAX_SESSION = 4 * 3600;

    /** A side's unsent bytes past this (a browser that reads nothing) end its console. */
    public const MAX_PENDING = 32 * 1024 * 1024;

    /** @var resource */
    private $server;

    /** @var array<int, array<string, mixed>> */
    private array $consoles = [];

    /** @var Closure(): float */
    private Closure $clock;

    /**
     * @param  Closure(array<string, mixed>): array{stream: resource, host: string, port: int, authorization: string}  $connect
     *                                                                                                                           a ticket's node, connected (TLS)
     * @param  Closure(string, array<string, mixed>): void|null  $log
     */
    public function __construct(string $listen, private readonly string $origin, private readonly string $path, private readonly Closure $connect,
        private readonly ?Closure $log = null, ?Closure $clock = null)
    {
        $server = @stream_socket_server('tcp://' . $listen, $code, $message);
        if ($server === false) {
            throw new InvalidArgumentException("Cannot listen on {$listen}: {$message}");
        }
        stream_set_blocking($server, false);
        $this->server = $server;
        $this->clock = $clock ?? fn () => microtime(true);
    }

    /** The address it listens on («127.0.0.1:8091»). */
    public function address(): string
    {
        return (string) stream_socket_get_name($this->server, false);
    }

    public function count(): int
    {
        return count($this->consoles);
    }

    /** @param  Closure(): bool|null  $stop */
    public function run(?Closure $stop = null): void
    {
        while ($stop === null || !$stop()) {
            $this->tick(1.0);
        }
    }

    /** One turn of the loop: what is readable read, what is pending written, what is over closed. */
    public function tick(float $timeout = 1.0): void
    {
        $read = [$this->server];
        $write = [];
        foreach ($this->consoles as $c) {
            $read[] = $c['client'];
            if ($c['cout'] !== '') {
                $write[] = $c['client'];
            }
            if ($c['upstream'] !== null) {
                $read[] = $c['upstream'];
                if ($c['uout'] !== '') {
                    $write[] = $c['upstream'];
                }
            }
        }
        $except = null;
        $seconds = (int) $timeout;
        if (@stream_select($read, $write, $except, $seconds, (int) (($timeout - $seconds) * 1e6)) === false) {
            return;
        }
        foreach ($read as $stream) {
            if ($stream === $this->server) {
                $this->accept();

                continue;
            }
            $this->readFrom($stream);
        }
        foreach ($this->consoles as $id => $c) {
            $this->flush($id);
        }
        $this->expire();
    }

    private function accept(): void
    {
        $client = @stream_socket_accept($this->server, 0);
        if ($client === false) {
            return;
        }
        if (count($this->consoles) >= self::MAX_CONNECTIONS) {
            @fwrite($client, "HTTP/1.1 503 Service Unavailable\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
            fclose($client);

            return;
        }
        stream_set_blocking($client, false);
        $now = ($this->clock)();
        $this->consoles[(int) $client] = ['client' => $client, 'upstream' => null, 'state' => 'handshake', 'cin' => '', 'uin' => '', 'cout' => '',
            'uout' => '', 'since' => $now, 'last' => $now, 'accept' => null, 'protocol' => null, 'closing' => false, 'ticket' => null];
    }

    /** @param resource $stream */
    private function readFrom($stream): void
    {
        $id = $this->owner($stream);
        if ($id === null) {
            return;
        }
        $side = $this->consoles[$id]['client'] === $stream ? 'cin' : 'uin';
        $got = '';
        // A TLS stream holds decrypted bytes stream_select does not see: read until it has none.
        while (($chunk = @fread($stream, 65536)) !== false && $chunk !== '') {
            $got .= $chunk;
            if (strlen($got) > self::MAX_FRAME + 16) {
                break;
            }
        }
        if ($got === '') {
            if (feof($stream)) {
                $this->close($id, 'closed by the ' . ($side === 'cin' ? 'browser' : 'node'));
            }

            return;
        }
        $this->consoles[$id][$side] .= $got;
        $this->consoles[$id]['last'] = ($this->clock)();
        try {
            $side === 'cin' ? $this->fromClient($id) : $this->fromUpstream($id);
        } catch (InvalidArgumentException $e) {
            $this->close($id, $e->getMessage());
        }
    }

    private function fromClient(int $id): void
    {
        $c = &$this->consoles[$id];
        if ($c['state'] === 'handshake') {
            $head = WebSocket::head($c['cin']);
            if ($head === null) {
                return;
            }
            $this->open($id, ...$head);

            return;
        }
        if ($c['state'] !== 'open') {
            return; // the node's answer first
        }
        while (($frame = WebSocket::decode($c['cin'], true, self::MAX_FRAME)) !== null) {
            match ($frame['opcode']) {
                WebSocket::PING => $c['cout'] .= WebSocket::encode(WebSocket::PONG, $frame['payload'], false),
                WebSocket::PONG => null,
                WebSocket::CLOSE => $this->closing($id, $frame['payload']),
                default => $c['uout'] .= WebSocket::encode($frame['opcode'], $frame['payload'], true, $frame['fin']),
            };
            if ($c['closing']) {
                return;
            }
        }
    }

    private function fromUpstream(int $id): void
    {
        $c = &$this->consoles[$id];
        if ($c['state'] === 'upstream') {
            $head = WebSocket::head($c['uin']);
            if ($head === null) {
                return;
            }
            [$status, $headers] = $head;
            if (!preg_match('#^HTTP/1\.1 101 #', $status . ' ') || ($headers['sec-websocket-accept'] ?? '') !== $c['accept']) {
                $this->refuse($id, 502, 'the node refused the console: ' . trim($status));

                return;
            }
            $c['state'] = 'open';
            $c['cout'] .= "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: "
                . WebSocket::accept($c['key']) . "\r\n" . ($c['protocol'] !== null ? "Sec-WebSocket-Protocol: {$c['protocol']}\r\n" : '') . "\r\n";
            $this->say('Console opened', $id);
            if ($c['cin'] !== '') {
                $this->fromClient($id);
            }
        }
        if ($c['state'] !== 'open') {
            return;
        }
        while (($frame = WebSocket::decode($c['uin'], false, self::MAX_FRAME)) !== null) {
            match ($frame['opcode']) {
                WebSocket::PING => $c['uout'] .= WebSocket::encode(WebSocket::PONG, $frame['payload'], true),
                WebSocket::PONG => null,
                WebSocket::CLOSE => $this->closing($id, $frame['payload']),
                default => $c['cout'] .= WebSocket::encode($frame['opcode'], $frame['payload'], false, $frame['fin']),
            };
            if ($c['closing']) {
                return;
            }
        }
    }

    /**
     * A browser's handshake: this site's page, a websocket of version 13 at the console's path with a ticket not yet
     * taken; then the node's websocket, opened with the token.
     *
     * @param  array<string, string>  $headers
     */
    private function open(int $id, string $request, array $headers): void
    {
        $c = &$this->consoles[$id];
        if (preg_match('#^GET (\S+) HTTP/1\.1$#', $request, $m) !== 1 || strtolower($headers['upgrade'] ?? '') !== 'websocket'
            || ($headers['sec-websocket-version'] ?? '') !== '13' || ($headers['sec-websocket-key'] ?? '') === '') {
            $this->refuse($id, 400, 'not a websocket handshake');

            return;
        }
        if (($headers['origin'] ?? '') !== $this->origin) {
            $this->refuse($id, 403, 'from another site: ' . ($headers['origin'] ?? 'no Origin'));

            return;
        }
        $path = (string) parse_url($m[1], PHP_URL_PATH);
        parse_str((string) parse_url($m[1], PHP_URL_QUERY), $query);
        $ticket = $path === $this->path && is_string($query['token'] ?? null) ? ConsoleTickets::take($query['token']) : null;
        if ($ticket === null) {
            $this->refuse($id, 403, 'no ticket, or one already used or too late');

            return;
        }
        $c['ticket'] = $ticket;
        $c['key'] = $headers['sec-websocket-key'];
        $offered = array_map('trim', explode(',', $headers['sec-websocket-protocol'] ?? ''));
        $c['protocol'] = in_array('binary', $offered, true) ? 'binary' : null;
        try {
            $node = ($this->connect)($ticket);
        } catch (Throwable $e) {
            $this->refuse($id, 502, 'the node could not be reached: ' . $e->getMessage());

            return;
        }
        stream_set_blocking($node['stream'], false);
        $c['upstream'] = $node['stream'];
        $key = WebSocket::key();
        $c['accept'] = WebSocket::accept($key);
        $c['state'] = 'upstream';
        $c['uout'] .= 'GET ' . $ticket['path'] . '?port=' . (int) $ticket['port'] . '&vncticket=' . rawurlencode($ticket['ticket']) . " HTTP/1.1\r\n"
            . "Host: {$node['host']}:{$node['port']}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\nSec-WebSocket-Protocol: binary\r\nAuthorization: {$node['authorization']}\r\n\r\n";
    }

    /** A close from one side: to the other, and the console ends once both are written. */
    private function closing(int $id, string $payload): void
    {
        $c = &$this->consoles[$id];
        $c['cout'] .= WebSocket::encode(WebSocket::CLOSE, $payload, false);
        if ($c['upstream'] !== null) {
            $c['uout'] .= WebSocket::encode(WebSocket::CLOSE, $payload, true);
        }
        $c['closing'] = true;
    }

    private function refuse(int $id, int $status, string $why): void
    {
        $text = [400 => 'Bad Request', 403 => 'Forbidden', 502 => 'Bad Gateway'][$status] ?? 'Error';
        $this->consoles[$id]['cout'] .= "HTTP/1.1 {$status} {$text}\r\nConnection: close\r\nContent-Length: 0\r\n\r\n";
        $this->consoles[$id]['closing'] = true;
        $this->consoles[$id]['why'] = $why;
    }

    private function flush(int $id): void
    {
        if (!isset($this->consoles[$id])) {
            return;
        }
        $c = &$this->consoles[$id];
        foreach (['cout' => 'client', 'uout' => 'upstream'] as $buffer => $side) {
            if ($c[$buffer] === '' || $c[$side] === null) {
                continue;
            }
            $sent = @fwrite($c[$side], $c[$buffer]);
            if ($sent === false) {
                $this->close($id, "the {$side} cannot be written");

                return;
            }
            $c[$buffer] = (string) substr($c[$buffer], $sent);
            if (strlen($c[$buffer]) > self::MAX_PENDING) {
                $this->close($id, "the {$side} reads nothing");

                return;
            }
        }
        if ($c['closing'] && $c['cout'] === '' && $c['uout'] === '') {
            $this->close($id, $c['why'] ?? 'closed');
        }
    }

    private function expire(): void
    {
        $now = ($this->clock)();
        foreach ($this->consoles as $id => $c) {
            $reason = match (true) {
                $c['state'] !== 'open' && $now - $c['since'] > self::HANDSHAKE => 'no handshake in time',
                $now - $c['last'] > self::IDLE => 'idle',
                $now - $c['since'] > self::MAX_SESSION => 'its time is up',
                default => null,
            };
            if ($reason !== null) {
                $this->close($id, $reason);
            }
        }
    }

    private function close(int $id, string $why): void
    {
        $c = $this->consoles[$id] ?? null;
        if ($c === null) {
            return;
        }
        unset($this->consoles[$id]);
        @fclose($c['client']);
        if ($c['upstream'] !== null) {
            @fclose($c['upstream']);
        }
        $this->say('Console closed: ' . $why, $id, $c);
    }

    /** @param  array<string, mixed>|null  $c */
    private function say(string $message, int $id, ?array $c = null): void
    {
        $ticket = ($c ?? $this->consoles[$id] ?? [])['ticket'] ?? null;
        if ($this->log !== null) {
            ($this->log)($message, is_array($ticket) ? ['service_id' => $ticket['service_id'], 'vmid' => $ticket['vmid'], 'user_id' => $ticket['user_id']] : []);
        }
    }

    /** @param resource $stream */
    private function owner($stream): ?int
    {
        foreach ($this->consoles as $id => $c) {
            if ($c['client'] === $stream || $c['upstream'] === $stream) {
                return $id;
            }
        }

        return null;
    }
}
