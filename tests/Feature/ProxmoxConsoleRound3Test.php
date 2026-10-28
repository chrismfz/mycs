<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\ProxmoxGuest;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Proxmox\ConsoleRelay;
use App\Services\Proxmox\ConsoleTickets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MyCS\Proxmox\WebSocket;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Round 3 of #506 (the final review's probes, kept): a big screen update never left half-sent, a slow browser that
 * reads not closed as one that reads nothing, and a browser gone without a word giving its slot back.
 */
class ProxmoxConsoleRound3Test extends TestCase
{
    use RefreshDatabase;

    private const VEGA = 'https://vega.example.test:8006';

    private const ORIGIN = 'https://mycs.example.test';

    private ?ConsoleRelay $relay = null;

    /** @var resource|null */
    private $node = null;

    private float $now = 1_000_000.0;

    /** @var list<string> */
    private array $logged = [];

    private bool $logFails = false;

    private bool $dbDown = false;

    protected function tearDown(): void
    {
        $this->relay = null;
        if ($this->node) {
            fclose($this->node);
        }
        parent::tearDown();
    }

    private function vps(string $status = 'active'): Service
    {
        $server = Server::query()->create(['name' => 'PVE', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        ExtensionHelper::saveSettings($server, ['host' => self::VEGA, 'ip' => '192.0.2.201', 'self_signed' => true, 'token_id' => 'mycs@pve!api',
            'token_secret' => 's3cret', 'pool' => 'mycs', 'terminate' => 'stop']);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => $status, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        ProxmoxGuest::query()->create(['server_id' => $server->id, 'vmid' => 101, 'node' => 'vega', 'name' => 'vps1', 'status' => 'running',
            'pool' => 'mycs', 'macs' => ['BC:24:11:00:00:01'], 'service_id' => $service->id]);

        return $service;
    }

    private function relay(): ConsoleRelay
    {
        $this->node = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($this->node, false);

        return $this->relay = new ConsoleRelay('127.0.0.1:0', self::ORIGIN, '/console/ws', fn (array $ticket) => [
            'stream' => stream_socket_client('tcp://' . $address), 'host' => 'vega.example.test', 'port' => 8006,
            'authorization' => 'PVEAPIToken=mycs@pve!api=s3cret'],
            function (string $message, array $context) {
                if ($this->logFails) {
                    // Monolog's StreamHandler when the day's file was made by another user (a root cron, a deploy).
                    throw new \UnexpectedValueException('The stream or file "storage/logs/laravel.log" could not be opened in append mode: Permission denied');
                }
                $this->logged[] = $message;
            }, fn () => $this->now,
            fn (array $ticket) => $this->dbDown ? throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused') : ConsoleRelay::allowed($ticket));
    }

    /** @return resource */
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
    private function browser(string $token)
    {
        $client = stream_socket_client('tcp://' . $this->relay->address());
        fwrite($client, "GET /console/ws?token={$token} HTTP/1.1\r\nHost: mycs.example.test\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Protocol: binary\r\nOrigin: " . self::ORIGIN . "\r\n\r\n");

        return $client;
    }

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

    private function ticket(Service $service): string
    {
        return ConsoleTickets::issue($service->provisioningServer()->id, $service->id, (int) $service->client->users()->first()->id,
            ['vmid' => 101, 'base' => self::VEGA, 'path' => '/api2/json/nodes/vega/qemu/101/vncwebsocket', 'port' => 5901, 'ticket' => 'PVEVNC:ABC+/=']);
    }

    /** A console opened end to end: [browser, node], both non-blocking. */
    private function open(Service $service): array
    {
        $browser = $this->browser($this->ticket($service));
        $node = $this->nodeSide();
        $request = $this->read($node);
        preg_match('/Sec-WebSocket-Key: (\S+)/', $request, $m);
        fwrite($node, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . WebSocket::accept($m[1]) . "\r\n\r\n");
        $this->assertStringStartsWith('HTTP/1.1 101', $this->read($browser));
        stream_set_blocking($node, false);

        return [$browser, $node];
    }

    /** A burst over MAX_PENDING, then the node quiet (VNC answers one request): the tail still reaches the browser. */
    public function test_a_big_update_reaches_the_browser_whole_though_the_node_is_then_quiet(): void
    {
        $this->relay();
        [$browser, $node] = $this->open($this->vps());
        $burst = str_repeat(WebSocket::encode(WebSocket::BINARY, str_repeat('u', 60000), false), 120); // about 7 MB
        stream_set_blocking($node, false);
        $off = 0;
        for ($i = 0; $i < 5000 && $off < strlen($burst); $i++) { // the node writes it all, the browser reads nothing yet
            $off += (int) @fwrite($node, substr($burst, $off, 1 << 20));
            $this->relay->tick(0.001);
        }
        $this->assertSame(strlen($burst), $off);
        $got = '';
        for ($quiet = 0; $quiet < 300;) { // then the browser reads, and the node sends nothing more
            $this->relay->tick(0.005);
            $chunk = (string) fread($browser, 1 << 20);
            $got .= $chunk;
            $quiet = $chunk === '' ? $quiet + 1 : 0;
        }

        $this->assertSame(1, $this->relay->count());
        $this->assertSame(strlen($burst), strlen($got));
    }

    #[DataProvider('sizes')]
    public function test_a_slow_browser_that_reads_is_not_closed_as_backed(int $frame, int $perSecond): void
    {
        $this->relay();
        [$browser, $node] = $this->open($this->vps());
        stream_set_read_buffer($browser, 0);
        $chunk = WebSocket::encode(WebSocket::BINARY, str_repeat('u', $frame), false);
        $pending = '';
        for ($t = 0; $t < 200 && $this->relay->count() === 1; $t++) {
            if (strlen($pending) < (1 << 20)) {
                $pending .= $chunk;
            }
            $pending = (string) substr($pending, (int) @fwrite($node, $pending));
            for ($k = 0; $k < 5; $k++) {
                $this->relay->tick(0.001);
            }
            fread($browser, $perSecond);
            if ($t % 20 === 0) {
                fwrite($browser, WebSocket::encode(WebSocket::PONG, 'mycs', true)); // as a browser answers the relay's pings
            }
            $this->now += 1.0;
        }

        $this->assertSame(1, $this->relay->count());
    }

    /** @return array<string, array{0: int, 1: int}> */
    public static function sizes(): array
    {
        return ['64 KB frames, 32 KB/s' => [65536, 32768], '4 MB frames, 64 KB/s' => [ConsoleRelay::MAX_FRAME - 16, 65536]];
    }

    /** Two tabs whose network went away (no FIN: a laptop asleep): pinged, no answer, their slots given back. */
    public function test_tabs_gone_without_a_word_give_their_slots_back(): void
    {
        $this->relay();
        $service = $this->vps();
        $first = $this->open($service); // each kept: a side let go is a close
        $second = $this->open($service);
        $this->now += ConsoleRelay::PING_AFTER + 1;
        $this->relay->tick(0.001);
        $this->assertSame(2, $this->relay->count(), 'pinged, not closed yet');
        $this->now += ConsoleRelay::PING_WAIT + 1;
        $this->relay->tick(0.001);

        $this->assertSame(0, $this->relay->count());
        $this->assertCount(4, [...$first, ...$second]); // their browsers and nodes, still held here
        $this->open($service); // the customer's next console opens (101)
    }

    /** A browser that answers the ping keeps its console. */
    public function test_a_browser_that_answers_the_ping_keeps_its_console(): void
    {
        $this->relay();
        [$browser, $node] = $this->open($this->vps()); // both kept: a side let go is a close
        $this->now += ConsoleRelay::PING_AFTER + 1;
        $this->relay->tick(0.001);
        $frames = $this->read($browser);
        $ping = WebSocket::decode($frames, false, 1024);
        $this->assertSame(WebSocket::PING, $ping['opcode']);
        fwrite($browser, WebSocket::encode(WebSocket::PONG, $ping['payload'], true));
        $this->read($browser, 0.2);
        $this->now += ConsoleRelay::PING_WAIT + 1;
        $this->relay->tick(0.001);

        $this->assertSame(1, $this->relay->count());
        $this->assertIsResource($node);
    }
}
