<?php

namespace MyCS\Proxmox\Tests\Console;

use Closure;
use MyCS\Proxmox\Console\Relay;
use MyCS\Proxmox\WebSocket;
use ReflectionProperty;
use RuntimeException;

/**
 * The relay on real local sockets, a browser and a node of the test's own (as #506's tests drove it), with its site's
 * side faked: tickets taken once from a list, the VNC proxy made at once (or later, from `poll`), and the 30 s check
 * answered per service.
 */
trait RelayHarness
{
    private const VEGA = 'https://vega.example.test:8006';

    private const ORIGIN = 'https://mycs.example.test';

    private const OTHER_SITE = 'https://other.example.test';

    private const PASSWORD = 'PVEVNC:ABC+/=';

    private ?Relay $relay = null;

    /** @var resource|null the fake node's listening socket */
    private $node = null;

    private float $now = 1_000_000.0;

    /** @var list<string> */
    private array $logged = [];

    private bool $logFails = false;

    /** @var array<string, array<string, mixed>> the tickets not yet taken */
    private array $tickets = [];

    private bool $ticketsFail = false;

    /** @var array<int, bool> what the site answers for a service (true when not set) */
    private array $allowed = [];

    /** The site does not answer (its database restarting). */
    private bool $siteDown = false;

    /** @var list<array<string, mixed>> the tickets whose VNC proxy was asked for */
    private array $proxies = [];

    private ?string $proxyRefused = null;

    /** @var list<Closure(): void> VNC proxies answered by the next `poll`, when set */
    private ?array $later = null;

    /** The answers in `later` held back (not given by `poll`). */
    private bool $hold = false;

    /** @var (Closure(array<string, mixed>, array<string, mixed>): array<string, mixed>)|null */
    private ?Closure $connectOverride = null;

    private int $services = 0;

    protected function tearDown(): void
    {
        $this->relay = null;
        if ($this->node) {
            fclose($this->node);
        }
        parent::tearDown();
    }

    private function relay(int $budget = Relay::BUDGET): Relay
    {
        $this->node = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($this->node, false);

        return $this->relay = new Relay('127.0.0.1:0', [self::ORIGIN, self::OTHER_SITE], '/ws',
            function (string $token) {
                if ($this->ticketsFail) {
                    throw new RuntimeException('Connection refused [tcp://127.0.0.1:6379]');
                }
                $ticket = $this->tickets[$token] ?? null;
                unset($this->tickets[$token]);

                return $ticket;
            },
            function (array $ticket, Closure $done) {
                $this->proxies[] = $ticket;
                $answer = fn () => $this->proxyRefused !== null ? $done(null, $this->proxyRefused)
                    : $done(['port' => 5901, 'ticket' => self::PASSWORD, 'base' => self::VEGA], null);
                $this->later !== null ? $this->later[] = $answer : $answer();
            },
            function (array $ticket, array $proxy) use ($address) {
                if ($this->connectOverride !== null) {
                    return ($this->connectOverride)($ticket, $proxy);
                }

                return ['stream' => stream_socket_client('tcp://' . $address), 'host' => 'vega.example.test', 'port' => 8006,
                    'authorization' => 'PVEAPIToken=mycs-console@pve!relay=s3cret'];
            },
            function (array $tickets, Closure $done) {
                $answers = [];
                foreach ($tickets as $id => $ticket) {
                    $answers[$id] = $this->siteDown ? null : ($this->allowed[$ticket['service_id']] ?? true);
                }
                $done($answers);
            },
            function () {
                if ($this->hold) {
                    return true;
                }
                $later = $this->later ?? [];
                if ($this->later !== null) {
                    $this->later = [];
                }
                foreach ($later as $answer) {
                    $answer();
                }

                return false;
            },
            function (string $message, array $context) {
                if ($this->logFails) {
                    // Monolog's StreamHandler when the day's file was made by another user (a root cron, a deploy).
                    throw new \UnexpectedValueException('The stream or file could not be opened in append mode: Permission denied');
                }
                $this->logged[] = $message;
            }, fn () => $this->now, $budget);
    }

    /** A new VPS of the site: its service's number. */
    private function vps(): int
    {
        return ++$this->services;
    }

    /** A ticket of the site for the service, as `tickets` gives it once. */
    private function ticket(int $service, int $user = 1, string $origin = self::ORIGIN): string
    {
        $token = 'v1.' . bin2hex(random_bytes(12));
        $this->tickets[$token] = ['origin' => $origin, 'server_id' => 1, 'service_id' => $service, 'user_id' => $user, 'node' => 'vega',
            'type' => 'qemu', 'vmid' => 101, 'iat' => 0, 'exp' => 0, 'jti' => bin2hex(random_bytes(11))];

        return $token;
    }

    /** @return resource the node's side, once the relay connected it */
    private function nodeSide()
    {
        for ($i = 0; $i < 100; $i++) {
            $this->relay->tick(0.02);
            if (($node = @stream_socket_accept($this->node, 0)) !== false) {
                return $node;
            }
        }
        $this->fail('the relay never connected the node');
    }

    /** @return resource */
    private function browser(string $token, string $origin = self::ORIGIN, string $extra = '', string $key = 'dGhlIHNhbXBsZSBub25jZQ==')
    {
        $client = stream_socket_client('tcp://' . $this->relay->address());
        fwrite($client, "GET /ws?t={$token} HTTP/1.1\r\nHost: console.example.test\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Protocol: binary\r\nOrigin: {$origin}\r\n\r\n" . $extra);

        return $client;
    }

    /** The relay turned until $stream has something (or a while passed); what it had. */
    private function read($stream, float $for = 1.0): string
    {
        stream_set_blocking($stream, false);
        $got = '';
        $until = microtime(true) + $for;
        while (microtime(true) < $until) {
            $this->relay->tick(0.02);
            $chunk = (string) fread($stream, 65536);
            $got .= $chunk;
            if ($got !== '' && $chunk === '') {
                break;
            }
        }

        return $got;
    }

    /** The node's 101 to the relay's request (read from $node first). */
    private function accept($node): string
    {
        $request = $this->read($node);
        preg_match('/Sec-WebSocket-Key: (\S+)/', $request, $m);
        fwrite($node, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . WebSocket::accept($m[1] ?? '') . "\r\n\r\n");

        return $request;
    }

    /**
     * A console opened end to end: [browser, node], both non-blocking; the 101 and the password frame read.
     *
     * @return array{0: resource, 1: resource}
     */
    private function open(int $service, int $user = 1): array
    {
        $browser = $this->browser($this->ticket($service, $user));
        $node = $this->nodeSide();
        $this->accept($node);
        $answer = $this->read($browser);
        $this->assertStringStartsWith('HTTP/1.1 101', $answer);
        for ($i = 0; $i < 20 && !str_contains($answer, self::PASSWORD); $i++) {
            $answer .= $this->read($browser, 0.1);
        }
        $this->assertStringContainsString(self::PASSWORD, $answer, 'the password frame');
        stream_set_blocking($node, false);

        return [$browser, $node];
    }

    /** @return array<int, array<string, mixed>> */
    private function consoles(): array
    {
        return (new ReflectionProperty(Relay::class, 'consoles'))->getValue($this->relay);
    }

    /** @param  array<string, mixed>  $c */
    private static function held(array $c): int
    {
        return strlen($c['cin']) + strlen($c['uin']) + strlen($c['cout']) + strlen($c['uout']);
    }
}
