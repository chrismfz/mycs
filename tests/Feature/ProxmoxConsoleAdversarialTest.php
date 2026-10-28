<?php

namespace Tests\Feature;

use App\Console\Commands\ProxmoxConsoleRelay;
use App\Helpers\ExtensionHelper;
use App\Models\ProxmoxGuest;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Proxmox\ConsoleRelay;
use App\Services\Proxmox\ConsoleTickets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use MyCS\Proxmox\WebSocket;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #506 (the customer's VPS console): the relay on real local sockets with a fake node, as
 * ProxmoxConsoleTest drives it. Each ADV-506-n test fails on the PR's code for the defect its name gives.
 */
class ProxmoxConsoleAdversarialTest extends TestCase
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

    /** @var (\Closure(array<string, mixed>): array<string, mixed>)|null */
    private ?\Closure $connectOverride = null;

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

        return $this->relay = new ConsoleRelay('127.0.0.1:0', self::ORIGIN, '/console/ws', function (array $ticket) use ($address) {
            if ($this->connectOverride !== null) {
                return ($this->connectOverride)($ticket);
            }

            return ['stream' => stream_socket_client('tcp://' . $address), 'host' => 'vega.example.test', 'port' => 8006,
                'authorization' => 'PVEAPIToken=mycs@pve!api=s3cret'];
        }, function (string $message, array $context) {
            $this->logged[] = $message;
        }, fn () => $this->now);
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
    private function browser(string $token, string $origin = self::ORIGIN, string $extra = '')
    {
        $client = stream_socket_client('tcp://' . $this->relay->address());
        fwrite($client, "GET /console/ws?token={$token} HTTP/1.1\r\nHost: mycs.example.test\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Protocol: binary\r\nOrigin: {$origin}\r\n\r\n" . $extra);

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

    private function ticket(Service $service, ?int $userId = null): string
    {
        return ConsoleTickets::issue($service->provisioningServer()->id, $service->id, $userId ?? (int) $service->client->users()->first()->id,
            ['vmid' => 101, 'base' => self::VEGA, 'path' => '/api2/json/nodes/vega/qemu/101/vncwebsocket', 'port' => 5901, 'ticket' => 'PVEVNC:ABC+/=']);
    }

    /** A console opened end to end: [browser, node]. */
    private function open(Service $service, ?int $userId = null): array
    {
        $browser = $this->browser($this->ticket($service, $userId));
        $node = $this->nodeSide();
        $request = $this->read($node);
        preg_match('/Sec-WebSocket-Key: (\S+)/', $request, $m);
        fwrite($node, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . WebSocket::accept($m[1]) . "\r\n\r\n");
        $this->assertStringStartsWith('HTTP/1.1 101', $this->read($browser));

        return [$browser, $node];
    }

    /** @return array<int, array<string, mixed>> */
    private function consoles(): array
    {
        return (new ReflectionProperty(ConsoleRelay::class, 'consoles'))->getValue($this->relay);
    }

    /**
     * ADV-506-1: the relay buffers whatever a browser sends before the node's 101, without bound (fromClient returns while
     * the state is «upstream», and only HANDSHAKE's 10 seconds end it). A customer with a ticket sends its handshake and
     * then tens of MB at once; the single process (php-cli's memory_limit, 128M here) dies, every console with it. A
     * client may send no frame before the 101 at all (RFC 6455 4.1), so a few KB is already too much.
     */
    public function test_adv_506_1_bytes_before_the_nodes_answer_are_buffered_without_bound(): void
    {
        $this->relay();
        $browser = $this->browser($this->ticket($this->vps()));
        $node = $this->nodeSide(); // connected, and never answers: the node is slow, or busy
        stream_set_blocking($browser, false);
        $junk = str_repeat("\x82", 1 << 20);
        $sent = 0;
        for ($i = 0; $i < 400 && $sent < 48 << 20 && $this->relay->count() > 0; $i++) {
            $sent += (int) @fwrite($browser, $junk);
            $this->relay->tick(0.01);
        }
        $buffered = array_sum(array_map(fn (array $c) => strlen($c['cin']), $this->consoles()));

        $this->assertLessThanOrEqual(WebSocket::MAX_HEAD + ConsoleRelay::MAX_FRAME, $buffered,
            "{$buffered} bytes of a browser held before its console opened ({$sent} sent)");
        fclose($node);
    }

    /**
     * ADV-506-2: each console may hold MAX_PENDING (32 MB) unsent a side, and a frame of MAX_FRAME (16 MB) costs about
     * three times its size while relayed; the relay has 64 of them in one process, which the command gives no
     * memory_limit, so php-cli's (128M, PHP's default and this host's). A customer needs no screen to fill it: its
     * browser pings (the relay answers each, into the browser's buffer) and never reads. Four consoles of its one VPS
     * (the ticket route gives ten a minute) end the process with a fatal error, every customer's console with it.
     */
    public function test_adv_506_2_browsers_that_ping_and_never_read_pass_php_clis_memory_limit(): void
    {
        $this->relay();
        $sides = [];
        for ($n = 0; $n < 4; $n++) {
            $sides[] = $this->open($this->vps()); // four VPS: one has at most PER_SERVICE consoles now (setup adapted with the fix)
        }
        $ping = str_repeat(WebSocket::encode(WebSocket::PING, str_repeat('p', 125), true), 512); // 64 KB of pings
        foreach ($sides as [$browser]) {
            stream_set_blocking($browser, false);
            stream_set_chunk_size($browser, 1 << 20);
        }
        gc_collect_cycles();
        $before = memory_get_usage();
        $pending = 0;
        for ($i = 0; $i < 3000 && $this->relay->count() === 4; $i++) {
            foreach ($sides as [$browser]) {
                @fwrite($browser, $ping); // and never a read
            }
            $this->relay->tick(0.001);
            $pending = max($pending, array_sum(array_map(fn (array $c) => strlen($c['cout']), $this->consoles())));
            if ($before + $pending > 128 << 20) {
                break;
            }
        }

        $this->assertLessThan(128 << 20, $before + $pending, sprintf('%.0f MB of pongs held for browsers that read nothing, on top of the %.0f MB '
            . 'of a booted app: past php-cli\'s 128M', $pending / 1048576, $before / 1048576));
    }

    /**
     * ADV-506-3: the node is connected synchronously inside the loop (ProxmoxConsoleRelay::connect: a blocking
     * stream_socket_client with a 5 s timeout, then the TLS handshake on the stream's default_socket_timeout). A node
     * that accepts TCP and is slow to speak TLS (busy, half-down, or behind a firewall that eats the handshake) freezes
     * every other console of every customer meanwhile. Here a peer that accepts and never answers.
     */
    public function test_adv_506_3_a_stalled_tls_handshake_with_a_node_freezes_every_console(): void
    {
        $service = $this->vps();
        $silent = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($silent, false), strrpos((string) stream_socket_get_name($silent, false), ':') + 1);
        $server = $service->provisioningServer();
        ExtensionHelper::saveSettings($server, ['host' => "https://vega.example.test:{$port}", 'ip' => '127.0.0.1', 'self_signed' => true,
            'token_id' => 'mycs@pve!api', 'token_secret' => 's3cret', 'pool' => 'mycs', 'terminate' => 'stop']);
        $this->relay();
        [$browser, $node] = $this->open($service); // a console that works, first
        $this->connectOverride = fn (array $ticket) => ProxmoxConsoleRelay::connect($ticket);

        $this->browser(ConsoleTickets::issue($server->id, $service->id, 1, ['vmid' => 101, 'base' => "https://vega.example.test:{$port}",
            'path' => '/api2/json/nodes/vega/qemu/101/vncwebsocket', 'port' => 5901, 'ticket' => 'PVEVNC:ABC+/=']));
        $started = microtime(true);
        $this->relay->tick(0.5); // the new browser's handshake: connect() runs here
        $this->relay->tick(0.01);
        $blocked = microtime(true) - $started;
        fclose($silent);

        $this->assertLessThan(2.0, $blocked, sprintf('the whole relay stood still %.1f s for one node\'s TLS handshake', $blocked));
    }

    /**
     * ADV-506-4: the relay checks the service once, when the ticket is issued; a console stays open MAX_SESSION (4 hours)
     * whatever happens next. A service suspended for non-payment, or terminated with «Terminate: stop» kept, or handed
     * to staff with the VM left running (a suspension whose stop failed): the customer keeps its console.
     */
    public function test_adv_506_4_a_console_outlives_the_services_suspension_and_termination(): void
    {
        $this->relay();
        $service = $this->vps();
        [$browser, $node] = $this->open($service); // kept: a socket let go is a close
        $this->assertSame(1, $this->relay->count());

        $service->forceFill(['status' => Service::STATUS_SUSPENDED])->save();
        for ($i = 0; $i < 5; $i++) { // five minutes, the console's frames still coming
            $this->now += 60;
            $this->relay->tick(0.01);
        }

        $this->assertSame(0, $this->relay->count(), 'the console of a suspended service is still open');
    }

    /**
     * ADV-506-5: the same for who holds it: a login whose grant is revoked (an ex-employee of the customer, removed from
     * its account in Account → Users) keeps the console it opened, for hours, though it can no longer even see the
     * service. The ticket names its user_id, never checked again.
     */
    public function test_adv_506_5_a_console_outlives_the_users_loss_of_access(): void
    {
        $this->relay();
        $service = $this->vps();
        $employee = User::factory()->create();
        $grant = $service->client->grantTo($employee, null);
        [$browser, $node] = $this->open($service, (int) $employee->id);

        $service->client->revoke($grant);
        for ($i = 0; $i < 5; $i++) {
            $this->now += 60;
            $this->relay->tick(0.01);
        }

        $this->assertSame(0, $this->relay->count(), 'a login removed from the account keeps the console it opened');
    }

    /**
     * ADV-506-6: MAX_CONNECTIONS (64) is the relay's, for everyone; nothing limits one service or one login. The ticket
     * route gives 10 a minute per VPS (and 30 per login over several), each console lives 4 hours: one customer with one
     * VPS holds every slot in under 7 minutes, and every other customer's console is a 503.
     */
    public function test_adv_506_6_one_customer_can_take_every_console_slot(): void
    {
        $this->relay();
        $service = $this->vps();
        $opened = 0;
        $browsers = []; // kept open: a browser let go is a close (setup adapted with the fix)
        for ($i = 0; $i < 8; $i++) {
            $browsers[] = $this->browser($this->ticket($service));
            $this->relay->tick(0.02);
            $this->relay->tick(0.02);
            if (($node = @stream_socket_accept($this->node, 0.2)) !== false) {
                $nodes[] = $node; // kept as well: a node's side let go is a close
                $opened++;
            }
        }

        $this->assertLessThan(8, $opened, 'eight consoles of one VPS at once, and nothing would stop sixty-four');
    }

    /**
     * ADV-506-7: an exception other than InvalidArgumentException escapes tick() and run(): the cache behind
     * ConsoleTickets::take (Redis restarting, a full disk for the file store) throws, and the process exits with every
     * customer's console. One browser's handshake must never end the relay.
     */
    public function test_adv_506_7_a_cache_error_on_one_handshake_ends_the_relay_for_everyone(): void
    {
        $this->relay();
        $service = $this->vps();
        [$browser, $node] = $this->open($service);
        Cache::shouldReceive('add')->andThrow(new RuntimeException('Connection refused [tcp://127.0.0.1:6379]'));
        $this->browser(str_repeat('a', 48));

        $escaped = null;
        try {
            for ($i = 0; $i < 10; $i++) {
                $this->relay->tick(0.02);
            }
        } catch (Throwable $e) {
            $escaped = $e;
        }

        $this->assertNull($escaped, 'tick() threw: ' . ($escaped?->getMessage() ?? ''));
    }

    /**
     * ADV-506-8: the head is split on CRLF only, so a lone LF stays inside a header's value, and the refusal logs the
     * browser's Origin as it came («from another site: …»). Laravel's line formatter keeps inline line breaks: anyone on
     * the internet (no ticket needed) writes whole forged lines into the application's log.
     */
    public function test_adv_506_8_a_forged_origin_writes_its_own_lines_into_the_log(): void
    {
        $this->relay();
        $this->read($this->browser(str_repeat('a', 48), "https://evil.example.test\n[2026-10-28 00:00:00] production.INFO: proxmox:console-relay: Console opened"));

        foreach ($this->logged as $message) {
            $this->assertStringNotContainsString("\n", $message, 'a line break of the browser\'s, in the log');
        }
        $this->assertNotSame([], $this->logged);
    }

    /** Guard (passes): a refused handshake never reaches the node, and a ticket taken by a refused one is not spent first. */
    public function test_guard_another_sites_origin_never_spends_the_ticket(): void
    {
        $this->relay();
        $service = $this->vps();
        $token = $this->ticket($service);
        $this->assertStringStartsWith('HTTP/1.1 403', $this->read($this->browser($token, 'null')));
        $this->assertStringStartsWith('HTTP/1.1 403', $this->read($this->browser($token, 'https://mycs.example.test.evil.example')));
        $this->assertNotNull(ConsoleTickets::take($token), 'still there for this site\'s page');
    }

    /** Guard (passes): the token's secret never reaches the browser, even in a 502's refusal. */
    public function test_guard_the_secret_is_not_in_a_refusal_or_the_log(): void
    {
        $this->relay();
        $this->connectOverride = fn () => throw new RuntimeException('vega.example.test: Connection refused');
        $answer = $this->read($this->browser($this->ticket($this->vps())));

        $this->assertStringStartsWith('HTTP/1.1 502', $answer);
        $this->assertStringNotContainsString('s3cret', $answer . implode("\n", $this->logged));
    }
}
