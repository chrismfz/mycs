<?php

namespace MyCS\Proxmox\Tests\Console;

use MyCS\Proxmox\Console\Relay;
use MyCS\Proxmox\WebSocket;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Round 2 of the adversarial review of #506, carried with the relay into the package. ADV-506-R2-5 (a cache flush
 * restarting the relay) is gone with its cause: the relay keeps nothing in MyCS's cache, and only its `restart_file`
 * restarts it.
 */
class RelayAdversarialR2Test extends TestCase
{
    use RelayHarness;

    /** ADV-506-R2-1: a read left over (`more`) skipped the backpressure and the budget. */
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

        $this->assertLessThanOrEqual(2 * Relay::MAX_PENDING + Relay::MAX_FRAME, $held,
            sprintf('%.0f MB held by one console whose browser reads nothing (MAX_PENDING is %.0f MB)', $held / 1048576, Relay::MAX_PENDING / 1048576));
        $this->assertIsResource($browser);
    }

    /** ADV-506-R2-2: the budget spent by others froze a healthy console. */
    public function test_adv_506_r2_2_the_budget_spent_by_others_freezes_a_healthy_console(): void
    {
        $this->relay(4 * 1024 * 1024);
        [$browser, $node] = $this->open($this->vps()); // the healthy customer's
        $frame = WebSocket::encode(WebSocket::BINARY, str_repeat('s', 256 * 1024), false);
        $budget = new \ReflectionMethod(Relay::class, 'buffered');
        $bulk = [];
        for ($v = 0; $v < 2; $v++) { // two VPS, two consoles each (PER_SERVICE), their browsers reading nothing
            $service = $this->vps();
            $bulk[] = $this->open($service);
            $bulk[] = $this->open($service);
        }
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

    /** ADV-506-R2-3: a console closing for ever was outside the per-service cap. */
    public function test_adv_506_r2_3_a_console_closing_for_ever_is_outside_the_per_service_cap(): void
    {
        $this->relay();
        $service = $this->vps();
        $sides = [];
        for ($k = 0; $k < 2; $k++) {
            $sides[] = $this->open($service);
        }
        $update = str_repeat(WebSocket::encode(WebSocket::BINARY, str_repeat('u', 65000), false), 16);
        for ($i = 0; $i < 400; $i++) {
            foreach ($sides as [$b, $n]) {
                @fwrite($n, $update);
            }
            $this->relay->tick(0.001);
            if (count(array_filter($this->consoles(), fn (array $c) => strlen($c['cout']) > Relay::MAX_PENDING)) === 2) {
                break;
            }
        }
        foreach ($sides as [$b, $n]) {
            fwrite($b, WebSocket::encode(WebSocket::CLOSE, pack('n', 1000), true));
            stream_set_blocking($b, false);
        }
        for ($i = 0; $i < 100 && count(array_filter($this->consoles(), fn (array $c) => $c['closing'])) < 2; $i++) {
            foreach (array_values($this->consoles()) as $k => $c) {
                if (!$c['closing']) {
                    fread($sides[$k][0], 16384);
                }
            }
            $this->relay->tick(0.005);
        }

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
        $held = array_filter($this->consoles(), fn (array $c) => ($c['ticket']['service_id'] ?? null) === $service && $c['upstream'] !== null);

        $this->assertLessThanOrEqual(Relay::PER_SERVICE, count($held), count($held) . ' consoles of one VPS hold a node\'s connection at once');
    }

    /** ADV-506-R2-4: a log that throws inside guarded() ended the relay. */
    public function test_adv_506_r2_4_a_log_that_throws_inside_guarded_ends_the_relay(): void
    {
        $this->relay();
        [$first] = $this->open($this->vps()); // another customer's, open
        $this->logFails = true;
        $browser = $this->browser($this->ticket($this->vps()));
        $node = $this->nodeSide();
        $escaped = null;
        try {
            $this->accept($node);
            for ($i = 0; $i < 10; $i++) {
                $this->relay->tick(0.02);
            }
        } catch (Throwable $e) {
            $escaped = $e;
        }

        $this->assertNull($escaped, 'tick() threw: ' . ($escaped?->getMessage() ?? ''));
        $this->assertIsResource($first);
        $this->assertIsResource($browser);
    }

    /** ADV-506-R2-6: a blip of the site at the check closed every console. */
    public function test_adv_506_r2_6_a_database_blip_at_the_recheck_closes_every_console(): void
    {
        $this->relay();
        $sides = [];
        for ($k = 0; $k < 3; $k++) {
            $sides[] = $this->open($this->vps());
        }
        $this->siteDown = true;
        $this->now += Relay::RECHECK + 1;
        $this->relay->tick(0.01);
        $this->siteDown = false;
        $this->now += Relay::RECHECK + 1;
        $this->relay->tick(0.01);

        $this->assertSame(3, $this->relay->count(), 'one failed recheck closed every console: ' . implode(' | ', $this->logged));
    }

    /** Guard (passes): a header value with a tab is not refused as a control character. */
    public function test_guard_a_tab_in_a_header_value_is_not_refused(): void
    {
        $buffer = "GET /ws?t=x HTTP/1.1\r\nUser-Agent: Mozilla/5.0\t(X11)\r\nOrigin:\t" . self::ORIGIN . "\r\n\r\n";
        $head = WebSocket::head($buffer);

        $this->assertSame(self::ORIGIN, $head[1]['origin'] ?? null);
    }

    /** Guard (passes): a console that never finishes its node's TLS ends at HANDSHAKE, without spinning the loop. */
    public function test_guard_a_node_that_never_finishes_tls_ends_at_the_handshake_deadline(): void
    {
        $silent = stream_socket_server('tcp://127.0.0.1:0');
        $address = (string) stream_socket_get_name($silent, false);
        $this->relay();
        $this->connectOverride = fn () => [
            'stream' => stream_socket_client('tcp://' . $address, $code, $message, 5, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
                stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]])),
            'host' => 'vega.example.test', 'port' => 8006, 'authorization' => 'PVEAPIToken=x', 'tls' => true];
        $browser = $this->browser($this->ticket($this->vps()));
        $started = microtime(true);
        for ($i = 0; $i < 20; $i++) {
            $this->relay->tick(0.05);
        }
        $elapsed = microtime(true) - $started;
        $this->assertSame(1, $this->relay->count());
        $this->assertGreaterThan(0.5, $elapsed, 'the loop spun instead of waiting on the node');
        $this->now += Relay::HANDSHAKE + 1;
        $this->relay->tick(0.01);
        fclose($silent);

        $this->assertSame(0, $this->relay->count());
        $this->assertIsResource($browser);
    }
}
