<?php

namespace App\Services\Proxmox;

use App\Admin\Resources\ServiceResource\ProxmoxPower;
use App\Models\Service;
use App\Models\User;
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
 * IDLE without a frame, MAX_SESSION in all, or once the service is no longer active or its login may no longer power
 * it (asked again every RECHECK seconds: review of #506).
 *
 * One customer never takes it down for the others (review of #506): nothing a browser sends is kept before its
 * console opens past a handshake's size; a side that reads nothing stops the other side's reading (MAX_PENDING), and
 * all consoles together hold at most BUDGET; the node is connected and its TLS spoken without blocking the loop; a VPS
 * has at most PER_SERVICE consoles at once; and whatever one console throws ends that console, never the loop.
 */
final class ConsoleRelay
{
    public const MAX_CONNECTIONS = 64;

    /** A VPS's consoles at once: a reconnect while the old one closes, not every slot. */
    public const PER_SERVICE = 2;

    /** A node's frame is a screen update: pveproxy sends them in pieces far smaller. */
    public const MAX_FRAME = 4 * 1024 * 1024;

    /** A browser's frame is a key or a mouse move (review of #506: one of 16 MB cost 64 MB to relay). */
    public const MAX_CLIENT_FRAME = 64 * 1024;

    public const HANDSHAKE = 10;

    public const IDLE = 1800;

    public const MAX_SESSION = 4 * 3600;

    public const RECHECK = 30;

    /** A console whose browser (or node) reads nothing is kept this long, its slot with it (round 2 of #506). */
    public const BACKED = 60;

    /** A closing console's last bytes are written this long, at most (ADV-506-R2-3). */
    public const CLOSING = 5;

    /** A recheck that fails (the database down) this many times in a row closes the console; once is «unknown». */
    public const RECHECK_FAILURES = 3;

    /** A side's unsent bytes past this: the other side is not read until it drains. */
    public const MAX_PENDING = 2 * 1024 * 1024;

    /** All consoles' buffers together past this: nothing more is read until they drain. */
    public const BUDGET = 96 * 1024 * 1024;

    /** Read from one stream a turn, at most. */
    private const READ = 256 * 1024;

    /** @var resource */
    private $server;

    /** @var array<int, array<string, mixed>> */
    private array $consoles = [];

    /** @var Closure(): float */
    private Closure $clock;

    /** @var Closure(array<string, mixed>): bool */
    private Closure $allowed;

    /**
     * @param  Closure(array<string, mixed>): array{stream: resource, host: string, port: int, authorization: string, tls?: bool}  $connect
     *                                                                                                                                       a ticket's node, its connection begun (TLS spoken here when `tls`)
     * @param  Closure(string, array<string, mixed>): void|null  $log
     * @param  (Closure(array<string, mixed>): bool)|null  $allowed  whether a ticket's console may stay open (default: self::allowed)
     */
    public function __construct(string $listen, private readonly string $origin, private readonly string $path, private readonly Closure $connect,
        private readonly ?Closure $log = null, ?Closure $clock = null, ?Closure $allowed = null)
    {
        $server = @stream_socket_server('tcp://' . $listen, $code, $message);
        if ($server === false) {
            throw new InvalidArgumentException("Cannot listen on {$listen}: {$message}");
        }
        stream_set_blocking($server, false);
        $this->server = $server;
        $this->clock = $clock ?? fn () => microtime(true);
        $this->allowed = $allowed ?? fn (array $ticket) => self::allowed($ticket);
    }

    /**
     * A ticket's console may stay open: its service active, still mapped to the same VM, and its login still allowed
     * to power it (its grant on the account not revoked).
     *
     * @param  array<string, mixed>  $ticket
     */
    public static function allowed(array $ticket): bool
    {
        $service = Service::query()->find($ticket['service_id'] ?? 0);
        $user = User::query()->find($ticket['user_id'] ?? 0);

        return $service !== null && $user !== null && $service->status === Service::STATUS_ACTIVE
            && ProxmoxPower::guest($service)?->vmid === (int) ($ticket['vmid'] ?? 0) && $user->can('update', $service);
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
        $read = count($this->consoles) < self::MAX_CONNECTIONS + 1 ? [$this->server] : [];
        $write = [];
        // Over BUDGET, only the consoles holding more than their share stop being read, not every one (ADV-506-R2-2).
        $full = $this->buffered() > self::BUDGET;
        $again = false; // a stream left with bytes read next turn without waiting
        $readable = []; // the consoles read this turn: a leftover (`more`) is read only for them (ADV-506-R2-1)
        foreach ($this->consoles as $id => $c) {
            $backed = strlen($c['cout']) > self::MAX_PENDING || strlen($c['uout']) > self::MAX_PENDING
                || ($full && self::held($c) > intdiv(self::BUDGET, self::MAX_CONNECTIONS));
            $this->consoles[$id]['backed_since'] = $backed ? ($c['backed_since'] ?? ($this->clock)()) : null;
            if (!$backed && !$c['closing']) {
                $readable[$id] = true;
                $read[] = $c['client'];
                // A TLS handshake under way waits on the node's answer: readable, once its first step is sent.
                if ($c['upstream'] !== null && ($c['state'] !== 'connecting' || $c['tls_started'])) {
                    $read[] = $c['upstream'];
                }
                $again = $again || $c['more'];
            }
            if ($c['cout'] !== '') {
                $write[] = $c['client'];
            }
            if ($c['upstream'] !== null && ($c['uout'] !== '' || ($c['state'] === 'connecting' && !$c['tls_started']))) {
                $write[] = $c['upstream'];
            }
        }
        $except = null;
        $timeout = $again ? 0.0 : $timeout;
        $seconds = (int) $timeout;
        if ($read === [] && $write === []) {
            usleep((int) ($timeout * 1e6));
        } elseif (@stream_select($read, $write, $except, $seconds, (int) (($timeout - $seconds) * 1e6)) === false) {
            return;
        }
        foreach ($read as $stream) {
            if ($stream === $this->server) {
                $this->accept();
            }
        }
        foreach ($this->consoles as $id => $c) {
            $this->guarded($id, function () use ($id, $c, $read, $write, $readable) {
                if ($c['state'] === 'connecting' && (in_array($c['upstream'], $write, true) || in_array($c['upstream'], $read, true))) {
                    $this->handshakeTls($id);
                }
                foreach (['client', 'upstream'] as $side) {
                    if ($side === 'upstream' && $c['state'] === 'connecting') {
                        continue;
                    }
                    if ($c[$side] !== null && isset($readable[$id], $this->consoles[$id]) && (in_array($c[$side], $read, true) || $c['more'])) {
                        $this->readFrom($id, $side);
                    }
                }
                $this->flush($id);
            });
        }
        $this->expire();
    }

    /** One console's turn: whatever it throws (the cache, a node) ends it alone, never the loop (ADV-506-7). */
    private function guarded(int $id, Closure $turn): void
    {
        try {
            $turn();
        } catch (Throwable $e) {
            if (isset($this->consoles[$id])) {
                $this->consoles[$id]['why'] = 'failed: ' . self::clean($e->getMessage());
                $this->close($id, $this->consoles[$id]['why']);
            }
        }
    }

    private function buffered(): int
    {
        return array_sum(array_map(fn (array $c) => self::held($c), $this->consoles));
    }

    /** @param  array<string, mixed>  $c */
    private static function held(array $c): int
    {
        return strlen($c['cin']) + strlen($c['uin']) + strlen($c['cout']) + strlen($c['uout']);
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
            'uout' => '', 'since' => $now, 'last' => $now, 'checked' => $now, 'accept' => null, 'key' => null, 'protocol' => null, 'closing' => false,
            'ticket' => null, 'node' => null, 'more' => false, 'tls_started' => false, 'closing_at' => null, 'failures' => 0, 'backed_since' => null];
    }

    private function readFrom(int $id, string $side): void
    {
        $c = &$this->consoles[$id];
        $stream = $c[$side];
        $buffer = $side === 'client' ? 'cin' : 'uin';
        $got = '';
        $c['more'] = false;
        // A TLS stream holds decrypted bytes stream_select does not see: read until it has none, or READ (then again next
        // turn without waiting).
        while (($chunk = @fread($stream, 65536)) !== false && $chunk !== '') {
            $got .= $chunk;
            if (strlen($got) >= self::READ) {
                $c['more'] = true;

                break;
            }
        }
        if ($got === '') {
            if (feof($stream)) {
                $this->close($id, 'closed by the ' . ($side === 'client' ? 'browser' : 'node'));
            }

            return;
        }
        $c[$buffer] .= $got;
        $c['last'] = ($this->clock)();
        try {
            $side === 'client' ? $this->fromClient($id) : $this->fromUpstream($id);
        } catch (InvalidArgumentException $e) {
            $this->close($id, $e->getMessage());
        }
    }

    private function fromClient(int $id): void
    {
        $c = &$this->consoles[$id];
        if ($c['closing']) {
            $c['cin'] = ''; // refused: nothing more of it is read (a second head pipelined after the first)

            return;
        }
        if ($c['state'] === 'handshake') {
            $head = WebSocket::head($c['cin']);
            if ($head === null) {
                return;
            }
            $this->open($id, ...$head);

            return;
        }
        if ($c['state'] !== 'open') {
            // A client sends nothing before the 101 (RFC 6455 4.1): a handshake's size of it, at most (ADV-506-1).
            if (strlen($c['cin']) > WebSocket::MAX_HEAD) {
                throw new InvalidArgumentException('bytes before the console opened');
            }

            return;
        }
        while (($frame = WebSocket::decode($c['cin'], true, self::MAX_CLIENT_FRAME)) !== null) {
            match ($frame['opcode']) {
                WebSocket::PING => $c['cout'] .= WebSocket::encode(WebSocket::PONG, $frame['payload'], false),
                WebSocket::PONG => null,
                WebSocket::CLOSE => $this->closing($id, $frame['payload']),
                default => $c['uout'] .= WebSocket::encode($frame['opcode'], $frame['payload'], true, $frame['fin']),
            };
            if ($this->consoles[$id]['closing'] || strlen($c['cout']) > self::MAX_PENDING || strlen($c['uout']) > self::MAX_PENDING) {
                return; // the rest when it drains
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
                $this->refuse($id, 502, 'the node refused the console: ' . self::clean($status));

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
        if ($c['state'] !== 'open' || $c['closing']) {
            return;
        }
        while (($frame = WebSocket::decode($c['uin'], false, self::MAX_FRAME)) !== null) {
            match ($frame['opcode']) {
                WebSocket::PING => $c['uout'] .= WebSocket::encode(WebSocket::PONG, $frame['payload'], true),
                WebSocket::PONG => null,
                WebSocket::CLOSE => $this->closing($id, $frame['payload']),
                default => $c['cout'] .= WebSocket::encode($frame['opcode'], $frame['payload'], false, $frame['fin']),
            };
            if ($this->consoles[$id]['closing'] || strlen($c['cout']) > self::MAX_PENDING || strlen($c['uout']) > self::MAX_PENDING) {
                return;
            }
        }
    }

    /**
     * A browser's handshake: this site's page, a websocket of version 13 at the console's path with a ticket not yet
     * taken, of a VPS with fewer than PER_SERVICE consoles; then the node's connection begun.
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
            $this->refuse($id, 403, 'from another site: ' . self::clean($headers['origin'] ?? 'no Origin'));

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
        // Closing ones too: one that never drains holds its slot until CLOSING ends it (ADV-506-R2-3).
        $open = count(array_filter($this->consoles, fn (array $o) => ($o['ticket']['service_id'] ?? null) === $ticket['service_id']));
        if ($open > self::PER_SERVICE) {
            $this->refuse($id, 429, 'service #' . $ticket['service_id'] . ' has ' . self::PER_SERVICE . ' consoles open (ADV-506-6)');

            return;
        }
        $c['key'] = $headers['sec-websocket-key'];
        $offered = array_map('trim', explode(',', $headers['sec-websocket-protocol'] ?? ''));
        $c['protocol'] = in_array('binary', $offered, true) ? 'binary' : null;
        try {
            $node = ($this->connect)($ticket);
        } catch (Throwable $e) {
            $this->refuse($id, 502, 'the node could not be reached: ' . self::clean($e->getMessage()));

            return;
        }
        stream_set_blocking($node['stream'], false);
        $c['upstream'] = $node['stream'];
        $c['node'] = $node;
        if ($node['tls'] ?? false) {
            $c['state'] = 'connecting'; // its TCP and TLS finished from the loop (ADV-506-3)

            return;
        }
        $this->upgrade($id);
    }

    /** The node's TLS, a step a turn: it never holds the loop. */
    private function handshakeTls(int $id): void
    {
        $c = &$this->consoles[$id];
        $done = @stream_socket_enable_crypto($c['upstream'], true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        $c['tls_started'] = true;
        if ($done === false) {
            $this->refuse($id, 502, 'the node\'s TLS failed');
        } elseif ($done === true) {
            $this->upgrade($id);
        }
    }

    /** The node's websocket asked for, with the token. */
    private function upgrade(int $id): void
    {
        $c = &$this->consoles[$id];
        $ticket = $c['ticket'];
        $node = $c['node'];
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
        if ($c['upstream'] !== null && $c['state'] === 'open') {
            $c['uout'] .= WebSocket::encode(WebSocket::CLOSE, $payload, true);
        }
        $c['closing'] = true;
        $c['closing_at'] ??= ($this->clock)();
    }

    private function refuse(int $id, int $status, string $why): void
    {
        $text = [400 => 'Bad Request', 403 => 'Forbidden', 429 => 'Too Many Requests', 502 => 'Bad Gateway'][$status] ?? 'Error';
        $this->consoles[$id]['cout'] .= "HTTP/1.1 {$status} {$text}\r\nConnection: close\r\nContent-Length: 0\r\n\r\n";
        $this->consoles[$id]['uout'] = '';
        $this->consoles[$id]['closing'] = true;
        $this->consoles[$id]['closing_at'] ??= ($this->clock)();
        $this->consoles[$id]['why'] = $why;
    }

    private function flush(int $id): void
    {
        if (!isset($this->consoles[$id])) {
            return;
        }
        $c = &$this->consoles[$id];
        foreach (['cout' => 'client', 'uout' => 'upstream'] as $buffer => $side) {
            if ($c[$buffer] === '' || $c[$side] === null || ($side === 'upstream' && $c['state'] === 'connecting')) {
                continue;
            }
            $sent = @fwrite($c[$side], $c[$buffer]);
            if ($sent === false) {
                $this->close($id, "the {$side} cannot be written");

                return;
            }
            $c[$buffer] = (string) substr($c[$buffer], $sent);
        }
        if ($c['closing'] && $c['cout'] === '' && ($c['uout'] === '' || $c['state'] !== 'open')) {
            $this->close($id, $c['why'] ?? 'closed');
        }
    }

    private function expire(): void
    {
        $now = ($this->clock)();
        foreach ($this->consoles as $id => $c) {
            $this->guarded($id, function () use ($id, $c, $now) {
                $reason = match (true) {
                    $c['closing'] && $now - $c['closing_at'] > self::CLOSING => $c['why'] ?? 'closed (its last bytes not read)',
                    $c['backed_since'] !== null && $now - $c['backed_since'] > self::BACKED => 'a side reads nothing',
                    $c['state'] !== 'open' && $now - $c['since'] > self::HANDSHAKE => 'no handshake in time',
                    $now - $c['last'] > self::IDLE => 'idle',
                    $now - $c['since'] > self::MAX_SESSION => 'its time is up',
                    default => null,
                };
                // Still the customer's to keep (ADV-506-4, 5): asked again every RECHECK seconds.
                if ($reason === null && $c['state'] === 'open' && $now - $c['checked'] >= self::RECHECK) {
                    $this->consoles[$id]['checked'] = $now;
                    try {
                        $allowed = ($this->allowed)($c['ticket']);
                        $this->consoles[$id]['failures'] = 0;
                    } catch (Throwable $e) {
                        // Unknown, not «no» (a database restarting): closed only after RECHECK_FAILURES in a row
                        // (ADV-506-R2-6).
                        $allowed = ++$this->consoles[$id]['failures'] < self::RECHECK_FAILURES;
                    }
                    if (!$allowed) {
                        $reason = 'no longer allowed (the service not active, or its login without access)';
                    }
                }
                if ($reason !== null) {
                    $this->close($id, $reason);
                }
            });
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

    /** A browser's or a node's words in the log: one line, printable, short (ADV-506-8). */
    private static function clean(string $text): string
    {
        return mb_strimwidth((string) preg_replace('/[^\x20-\x7e]/', '?', $text), 0, 160, '…');
    }

    /** @param  array<string, mixed>|null  $c */
    private function say(string $message, int $id, ?array $c = null): void
    {
        $ticket = ($c ?? $this->consoles[$id] ?? [])['ticket'] ?? null;
        if ($this->log === null) {
            return;
        }
        try {
            ($this->log)($message, is_array($ticket) ? ['service_id' => $ticket['service_id'], 'vmid' => $ticket['vmid'], 'user_id' => $ticket['user_id']] : []);
        } catch (Throwable) {
            // A log that cannot be written (a file of root's, a full disk) never ends the relay (ADV-506-R2-4).
        }
    }
}
