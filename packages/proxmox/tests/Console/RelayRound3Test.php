<?php

namespace MyCS\Proxmox\Tests\Console;

use MyCS\Proxmox\Console\Relay;
use MyCS\Proxmox\WebSocket;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Round 3 of the review of #506, carried with the relay into the package: a big update reaches a browser that reads
 * late, a slow browser that reads is not «backed», and a tab gone without a word gives its slot back.
 */
class RelayRound3Test extends TestCase
{
    use RelayHarness;

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
        return ['64 KB frames, 32 KB/s' => [65536, 32768], '4 MB frames, 64 KB/s' => [Relay::MAX_FRAME - 16, 65536]];
    }

    /** Two tabs whose network went away (no FIN: a laptop asleep): pinged, no answer, their slots given back. */
    public function test_tabs_gone_without_a_word_give_their_slots_back(): void
    {
        $this->relay();
        $service = $this->vps();
        $first = $this->open($service); // each kept: a side let go is a close
        $second = $this->open($service);
        $this->now += Relay::PING_AFTER + 1;
        $this->relay->tick(0.001);
        $this->assertSame(2, $this->relay->count(), 'pinged, not closed yet');
        $this->now += Relay::PING_WAIT + 1;
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
        $this->now += Relay::PING_AFTER + 1;
        $this->relay->tick(0.001);
        $frames = $this->read($browser);
        $ping = WebSocket::decode($frames, false, 1024);
        $this->assertSame(WebSocket::PING, $ping['opcode']);
        fwrite($browser, WebSocket::encode(WebSocket::PONG, $ping['payload'], true));
        $this->read($browser, 0.2);
        $this->now += Relay::PING_WAIT + 1;
        $this->relay->tick(0.001);

        $this->assertSame(1, $this->relay->count());
        $this->assertIsResource($node);
    }
}
