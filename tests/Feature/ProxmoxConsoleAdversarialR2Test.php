<?php

namespace Tests\Feature;

use App\Console\Commands\ProxmoxConsoleRelay;
use App\Helpers\ExtensionHelper;
use App\Models\Currency;
use App\Models\ProxmoxGuest;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Proxmox\ConsoleRelay;
use App\Services\Proxmox\ConsoleTickets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use MyCS\Proxmox\WebSocket;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #506, round 2 (after a029dff5): the relay on real local sockets with a fake node, as
 * ProxmoxConsoleAdversarialTest drives it. Each ADV-506-R2-n test fails on a029dff5 for the defect its name gives.
 */
class ProxmoxConsoleAdversarialR2Test extends TestCase
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

    private function relay(int $budget = ConsoleRelay::BUDGET): ConsoleRelay
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
            fn (array $ticket) => $this->dbDown ? throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused') : ConsoleRelay::allowed($ticket), $budget);
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

    /** @return array<int, array<string, mixed>> */
    private function consoles(): array
    {
        return (new ReflectionProperty(ConsoleRelay::class, 'consoles'))->getValue($this->relay);
    }

    private static function held(array $c): int
    {
        return strlen($c['cin']) + strlen($c['uin']) + strlen($c['cout']) + strlen($c['uout']);
    }

    /**
     * ADV-506-R2-1 (reopens ADV-506-2): the `more` flag (a read that stopped at READ) makes tick() read that console
     * again whatever the select said, and it is tested apart from the backpressure: line 169 reads a side when
     * `$c['more']`, though line 132 left the console out of the read set because it is backed (MAX_PENDING), the relay
     * is over BUDGET, or the console is closing. A node with more than READ waiting (a screen update the browser asked
     * for, which a customer's browser asks for again and again and never reads) is read 256 KB a turn for ever: uin
     * grows without bound (fromUpstream stops decoding once cout passes MAX_PENDING), past 512M, every console with it.
     */
    public function test_adv_506_r2_1_a_read_left_over_skips_the_backpressure_and_the_budget(): void
    {
        $this->relay();
        [$browser, $node] = $this->open($this->vps()); // and the browser never reads again
        $update = str_repeat(WebSocket::encode(WebSocket::BINARY, str_repeat('u', 65000), false), 16); // ~1 MB of screen
        $held = 0;
        for ($i = 0; $i < 600 && $this->relay->count() === 1 && $held < 64 << 20; $i++) {
            @fwrite($node, $update);
            $this->relay->tick(0.001);
            $held = max($held, array_sum(array_map(fn (array $c) => self::held($c), $this->consoles())));
        }

        $this->assertLessThanOrEqual(2 * ConsoleRelay::MAX_PENDING + ConsoleRelay::MAX_FRAME, $held,
            sprintf('%.0f MB held by one console whose browser reads nothing (MAX_PENDING is %.0f MB)', $held / 1048576, ConsoleRelay::MAX_PENDING / 1048576));
    }

    /**
     * ADV-506-R2-2: BUDGET is all-or-nothing: once every console's buffers together pass it, tick() reads no console
     * at all, healthy ones included, and the ones over it never drain (their browsers read nothing), so nothing is read
     * until IDLE closes them, 30 minutes on: by then every healthy console has gone idle too. Who holds the backlog is
     * never asked. Here other customers' consoles, each within what one console may hold (a node's frame of MAX_FRAME
     * on top of MAX_PENDING), freeze a healthy console's keys; with ADV-506-R2-3 one VPS does it alone.
     */
    public function test_adv_506_r2_2_the_budget_spent_by_others_freezes_a_healthy_console(): void
    {
        // The same rule with a budget of 4 MB, passed with a few MB of frames (it was 96 MB, passed with ~860 MB of
        // strings in one process: the worker crashed on CI's runners).
        $this->relay(4 * 1024 * 1024);
        [$browser, $node] = $this->open($this->vps()); // the healthy customer's
        $frame = WebSocket::encode(WebSocket::BINARY, str_repeat('s', 256 * 1024), false);
        $budget = new \ReflectionMethod(ConsoleRelay::class, 'buffered');
        $bulk = [];
        for ($v = 0; $v < 2; $v++) { // two VPS, two consoles each (PER_SERVICE), their browsers reading nothing
            $service = $this->vps();
            $bulk[] = $this->open($service);
            $bulk[] = $this->open($service);
        }
        // The same frame again and again, never a big string held here: as many bytes go through as the system's TCP
        // buffers take, until the relay itself holds more than its budget.
        $pending = array_fill(0, count($bulk), '');
        for ($round = 0; $round < 3000 && $budget->invoke($this->relay) <= 4 * 1024 * 1024; $round++) {
            foreach ($bulk as $k => [$b, $n]) {
                if ($pending[$k] === '') {
                    $pending[$k] = $frame;
                }
                $pending[$k] = (string) substr($pending[$k], (int) @fwrite($n, $pending[$k]));
            }
            $this->relay->tick(0.001);
        }
        $this->assertGreaterThan(4 * 1024 * 1024, $budget->invoke($this->relay), 'setup: the others\' consoles hold the budget');

        fwrite($browser, WebSocket::encode(WebSocket::BINARY, 'key: Enter', true));
        $got = '';
        for ($i = 0; $i < 50 && $got === ''; $i++) {
            $this->relay->tick(0.01);
            $got .= (string) fread($node, 65536);
        }

        $this->assertSame('key: Enter', (string) (WebSocket::decode($got, true, 1024)['payload'] ?? ''),
            sprintf('a healthy console\'s key never reached its node while other consoles hold %.0f MB', $budget->invoke($this->relay) / 1048576));
    }

    /**
     * ADV-506-R2-3 (reopens ADV-506-6): PER_SERVICE counts only consoles not `closing`, and a closing console waits for
     * its last bytes to be written before it ends, with no deadline but IDLE (30 minutes; `last` moves only on a read,
     * and a closing console is never read). A customer's browser that reads nothing, then sends a Close, leaves the
     * console closing, holding its node's connection and its megabytes, outside the cap: two more, and two more, until
     * all 64 slots (and the BUDGET, ADV-506-R2-2) are its one VPS's.
     */
    public function test_adv_506_r2_3_a_console_closing_for_ever_is_outside_the_per_service_cap(): void
    {
        $this->relay();
        $service = $this->vps();
        $sides = [];
        for ($k = 0; $k < 2; $k++) {
            $sides[] = $this->open($service);
        }
        $update = str_repeat(WebSocket::encode(WebSocket::BINARY, str_repeat('u', 65000), false), 16);
        for ($i = 0; $i < 400; $i++) { // the browsers ask for screens and read none: each console backed
            foreach ($sides as [$b, $n]) {
                @fwrite($n, $update);
            }
            $this->relay->tick(0.001);
            if (count(array_filter($this->consoles(), fn (array $c) => strlen($c['cout']) > ConsoleRelay::MAX_PENDING)) === 2) {
                break;
            }
        }
        foreach ($sides as [$b, $n]) {
            fwrite($b, WebSocket::encode(WebSocket::CLOSE, pack('n', 1000), true)); // a Close, then a little read at a time
            stream_set_blocking($b, false);
        }
        for ($i = 0; $i < 100 && count(array_filter($this->consoles(), fn (array $c) => $c['closing'])) < 2; $i++) {
            foreach (array_values($this->consoles()) as $k => $c) {
                if (!$c['closing']) {
                    fread($sides[$k][0], 16384); // the same order: opened one after the other
                }
            }
            $this->relay->tick(0.005);
        }
        // Setup adapted with the fix: a backed console is no longer read at all (ADV-506-R2-1), so its Close may never be
        // seen; closing, backed or drained by the reads above, the check below is the cap's.

        $this->now += 15; // a reconnect's few seconds later, a handshake's deadline past
        $fresh = [];
        for ($k = 0; $k < 2; $k++) {
            $fresh[] = $this->browser($this->ticket($service));
            for ($i = 0; $i < 5; $i++) {
                $this->relay->tick(0.01);
            }
            while (($n = @stream_socket_accept($this->node, 0.05)) !== false) {
                $fresh[] = $n; // kept: a node let go is a close
            }
        }
        $held = array_filter($this->consoles(), fn (array $c) => ($c['ticket']['service_id'] ?? null) === $service->id && $c['upstream'] !== null);

        $this->assertLessThanOrEqual(ConsoleRelay::PER_SERVICE, count($held), count($held) . ' consoles of one VPS hold a node\'s connection at once');
    }

    /**
     * ADV-506-R2-4 (variant of ADV-506-7): guarded() closes the failed console from its catch, and close() logs; a log
     * that throws there (Monolog's StreamHandler cannot open the day's file: made by root's cron or a deploy, a full
     * disk) escapes the catch, tick() and run(): the relay exits, every customer's console with it. Here the log fails
     * on «Console opened», which guarded() catches, then fails again on «Console closed».
     */
    public function test_adv_506_r2_4_a_log_that_throws_inside_guarded_ends_the_relay(): void
    {
        $this->relay();
        [$first] = $this->open($this->vps()); // another customer's, open
        $this->logFails = true;
        $browser = $this->browser($this->ticket($this->vps()));
        $node = $this->nodeSide();
        $escaped = null;
        try {
            $request = $this->read($node);
            preg_match('/Sec-WebSocket-Key: (\S+)/', $request, $m);
            fwrite($node, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . WebSocket::accept($m[1]) . "\r\n\r\n");
            for ($i = 0; $i < 10; $i++) {
                $this->relay->tick(0.02);
            }
        } catch (Throwable $e) {
            $escaped = $e;
        }

        $this->assertNull($escaped, 'tick() threw: ' . ($escaped?->getMessage() ?? ''));
    }

    /**
     * ADV-506-R2-5: the restart key lives in the application's cache, and the application flushes that cache whole:
     * Currency's saved/created/deleted hooks (staff editing a currency) and the WHMCS catalog import call Cache::flush().
     * The relay sees its key gone (null !== the value it started with) within 10 s and exits: every customer's console
     * drops, with no deploy. The relay is run here as systemd runs it, in a child process, on a file cache both share.
     */
    public function test_adv_506_r2_5_staff_saving_a_currency_restarts_the_relay(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl');
        }
        $dir = sys_get_temp_dir() . '/adv-506-r2-5-' . getmypid();
        config(['cache.stores.adv_file' => ['driver' => 'file', 'path' => $dir]]);
        Cache::setDefaultDriver('adv_file');
        Cache::forever(ProxmoxConsoleRelay::RESTART_KEY, 1.0); // the last deploy's
        $currency = Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'prefix' => '$', 'suffix' => '', 'format' => '1,000.00']);

        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                Artisan::call('proxmox:console-relay', ['--listen' => '127.0.0.1:0']);
            } finally {
                pcntl_exec('/bin/true'); // no shutdown of the parent's test in the child
            }
        }
        usleep(1_500_000); // the relay up, its key read
        $currency->save(); // staff saving a currency (unchanged, even)
        $exited = false;
        for ($i = 0; $i < 130 && !$exited; $i++) {
            usleep(100_000);
            $exited = pcntl_waitpid($pid, $status, WNOHANG) === $pid;
        }
        if (!$exited) {
            exec('kill -TERM ' . (int) $pid);
            pcntl_waitpid($pid, $status);
        }
        exec('rm -rf ' . escapeshellarg($dir));

        $this->assertFalse($exited, 'the relay exited (every console with it) because a currency was saved');
    }

    /**
     * ADV-506-R2-6 (Low): the 30 s recheck runs in guarded(), so a database that does not answer for a moment (MariaDB
     * restarting, or OOM-killed as on 2026-10 deploys) throws for every open console at its next recheck, and each is
     * closed («failed: SQLSTATE…»): every customer's console drops on a blip that changed nobody's access. A failed
     * recheck is not a «no»; a few failed in a row may be.
     */
    public function test_adv_506_r2_6_a_database_blip_at_the_recheck_closes_every_console(): void
    {
        $this->relay();
        $sides = [];
        for ($k = 0; $k < 3; $k++) {
            $sides[] = $this->open($this->vps());
        }
        $this->dbDown = true;
        $this->now += ConsoleRelay::RECHECK + 1;
        $this->relay->tick(0.01);
        $this->dbDown = false;
        $this->now += ConsoleRelay::RECHECK + 1;
        $this->relay->tick(0.01);

        $this->assertSame(3, $this->relay->count(), 'one failed recheck closed every console: ' . implode(' | ', $this->logged));
    }

    /** Guard (passes): a header value with a tab (RFC 9110 allows HTAB in field values) is not refused as a control character. */
    public function test_guard_a_tab_in_a_header_value_is_not_refused(): void
    {
        $buffer = "GET /console/ws?token=x HTTP/1.1\r\nUser-Agent: Mozilla/5.0\t(X11)\r\nOrigin:\t" . self::ORIGIN . "\r\n\r\n";
        $head = WebSocket::head($buffer);

        $this->assertSame(self::ORIGIN, $head[1]['origin'] ?? null);
    }

    /** Guard (passes): a console that never finishes its node's TLS ends at HANDSHAKE, without spinning the loop. */
    public function test_guard_a_node_that_never_finishes_tls_ends_at_the_handshake_deadline(): void
    {
        $silent = stream_socket_server('tcp://127.0.0.1:0');
        $address = (string) stream_socket_get_name($silent, false);
        $this->node = stream_socket_server('tcp://127.0.0.1:0');
        $this->relay = new ConsoleRelay('127.0.0.1:0', self::ORIGIN, '/console/ws', fn (array $ticket) => [
            'stream' => stream_socket_client('tcp://' . $address, $code, $message, 5, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
                stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]])),
            'host' => 'vega.example.test', 'port' => 8006, 'authorization' => 'PVEAPIToken=x', 'tls' => true], null, fn () => $this->now);
        $browser = $this->browser($this->ticket($this->vps()));
        $started = microtime(true);
        for ($i = 0; $i < 20; $i++) {
            $this->relay->tick(0.05);
        }
        $elapsed = microtime(true) - $started;
        $this->assertSame(1, $this->relay->count());
        $this->assertGreaterThan(0.5, $elapsed, 'the loop spun instead of waiting on the node');
        $this->now += ConsoleRelay::HANDSHAKE + 1;
        $this->relay->tick(0.01);
        fclose($silent);

        $this->assertSame(0, $this->relay->count());
    }
}
