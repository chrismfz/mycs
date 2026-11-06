<?php

namespace MyCS\Proxmox\Tests\Console;

use MyCS\Proxmox\Console\AsyncHttp;
use MyCS\Proxmox\Console\Relay;
use MyCS\Proxmox\Console\StandaloneRelay;
use MyCS\Proxmox\WebSocket;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Adversarial review of #506 (the customer's VPS console), carried with the relay into the package: each ADV-506-n test
 * failed on #506's first code for the defect its name gives. ADV-506-4 and 5 (a console outliving the service or the
 * login's access) are the site's «no» here (RelayTest) and MyCS's answer there (ProxmoxConsoleTest's check).
 */
class RelayAdversarialTest extends TestCase
{
    use RelayHarness;

    /**
     * ADV-506-1: whatever a browser sends before the node's 101 was buffered without bound. A client may send no frame
     * before the 101 at all (RFC 6455 4.1), so a few KB is already too much.
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

        $this->assertLessThanOrEqual(WebSocket::MAX_HEAD + Relay::MAX_FRAME, $buffered,
            "{$buffered} bytes of a browser held before its console opened ({$sent} sent)");
        fclose($node);
    }

    /** ADV-506-2: browsers that ping and never read held every pong, past php-cli's memory_limit. */
    public function test_adv_506_2_browsers_that_ping_and_never_read_pass_php_clis_memory_limit(): void
    {
        $this->relay();
        $sides = [];
        for ($n = 0; $n < 4; $n++) {
            $sides[] = $this->open($this->vps());
        }
        $ping = str_repeat(WebSocket::encode(WebSocket::PING, str_repeat('p', 125), true), 512); // 64 KB of pings
        foreach ($sides as [$browser]) {
            stream_set_blocking($browser, false);
            stream_set_chunk_size($browser, 1 << 20);
        }
        $pending = 0;
        for ($i = 0; $i < 3000 && $this->relay->count() === 4; $i++) {
            foreach ($sides as [$browser]) {
                @fwrite($browser, $ping); // and never a read
            }
            $this->relay->tick(0.001);
            $pending = max($pending, array_sum(array_map(fn (array $c) => strlen($c['cout']), $this->consoles())));
            if ($pending > 128 << 20) {
                break;
            }
        }

        $this->assertLessThan(4 * (Relay::MAX_PENDING + (1 << 20)), $pending, sprintf('%.0f MB of pongs held for browsers that read nothing',
            $pending / 1048576));
    }

    /**
     * ADV-506-3: the node was connected synchronously inside the loop. A node that accepts TCP and never speaks TLS
     * froze every other console. Here the relay's own connect (StandaloneRelay), to a peer that never answers.
     */
    public function test_adv_506_3_a_stalled_tls_handshake_with_a_node_freezes_every_console(): void
    {
        $silent = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($silent, false), strrpos((string) stream_socket_get_name($silent, false), ':') + 1);
        $standalone = new StandaloneRelay(['sites' => [['origin' => self::ORIGIN, 'keys' => [str_repeat('k', 32)], 'clusters' => [1 => [
            'nodes' => [['base' => "https://vega.example.test:{$port}", 'ip' => '127.0.0.1']], 'self_signed' => true,
            'token_id' => 'mycs-console@pve!relay', 'token_secret' => 's3cret']]]]], new AsyncHttp);
        $this->relay();
        [$browser, $node] = $this->open($this->vps()); // a console that works, first
        $this->connectOverride = fn (array $ticket, array $proxy) => $standalone->connect($ticket,
            ['base' => "https://vega.example.test:{$port}"] + $proxy);

        $this->browser($this->ticket($this->vps()));
        $started = microtime(true);
        $this->relay->tick(0.5); // the new browser's handshake: connect() runs here
        $this->relay->tick(0.01);
        $this->relay->tick(0.01);
        $blocked = microtime(true) - $started;
        fclose($silent);

        $this->assertLessThan(2.0, $blocked, sprintf('the whole relay stood still %.1f s for one node\'s TLS handshake', $blocked));
        $this->assertCount(2, [$browser, $node]);
    }

    /** ADV-506-6: one customer with one VPS could take every console slot. */
    public function test_adv_506_6_one_customer_can_take_every_console_slot(): void
    {
        $this->relay();
        $service = $this->vps();
        $opened = 0;
        $browsers = $nodes = []; // kept open: a side let go is a close
        for ($i = 0; $i < 8; $i++) {
            $browsers[] = $this->browser($this->ticket($service));
            $this->relay->tick(0.02);
            $this->relay->tick(0.02);
            $this->relay->tick(0.02);
            if (($node = @stream_socket_accept($this->node, 0.2)) !== false) {
                $nodes[] = $node;
                $opened++;
            }
        }

        $this->assertLessThan(8, $opened, 'eight consoles of one VPS at once, and nothing would stop sixty-four');
    }

    /** ADV-506-7: a callback that throws on one browser's handshake (the tickets' store down) never ends the relay. */
    public function test_adv_506_7_a_cache_error_on_one_handshake_ends_the_relay_for_everyone(): void
    {
        $this->relay();
        $kept = $this->open($this->vps());
        $this->ticketsFail = true;
        $this->browser('v1.anything');

        $escaped = null;
        try {
            for ($i = 0; $i < 10; $i++) {
                $this->relay->tick(0.02);
            }
        } catch (Throwable $e) {
            $escaped = $e;
        }

        $this->assertNull($escaped, 'tick() threw: ' . ($escaped?->getMessage() ?? ''));
        $this->assertCount(2, $kept);
    }

    /** ADV-506-8: a lone LF in the Origin wrote whole forged lines into the log. */
    public function test_adv_506_8_a_forged_origin_writes_its_own_lines_into_the_log(): void
    {
        $this->relay();
        $this->read($this->browser('v1.anything', "https://evil.example.test\n[2026-10-28 00:00:00] production.INFO: Console opened"));

        foreach ($this->logged as $message) {
            $this->assertStringNotContainsString("\n", $message, 'a line break of the browser\'s, in the log');
        }
        $this->assertNotSame([], $this->logged);
    }

    /** Guard (passes): a refused handshake never spends the ticket. */
    public function test_guard_another_sites_origin_never_spends_the_ticket(): void
    {
        $this->relay();
        $token = $this->ticket($this->vps());
        $this->assertStringStartsWith('HTTP/1.1 403', $this->read($this->browser($token, 'null')));
        $this->assertStringStartsWith('HTTP/1.1 403', $this->read($this->browser($token, 'https://mycs.example.test.evil.example')));
        $this->assertArrayHasKey($token, $this->tickets, 'still there for this site\'s page');
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
