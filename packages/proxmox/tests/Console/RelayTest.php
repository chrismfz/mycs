<?php

namespace MyCS\Proxmox\Tests\Console;

use MyCS\Proxmox\Console\Relay;
use MyCS\Proxmox\WebSocket;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The console relay (docs/proxmox.md, B6 and «Σχέδιο: το relay στο cluster»): a signed ticket of one of its sites, from
 * that site's page, once; the guest's VNC proxy asked with the relay's own token; the password to the browser first,
 * then the frames both ways. The #506 tests (ProxmoxConsoleTest), framework-free.
 */
class RelayTest extends TestCase
{
    use RelayHarness;

    public function test_the_relay_asks_the_proxy_opens_the_nodes_websocket_with_its_token_sends_the_password_first_and_passes_frames(): void
    {
        $this->relay();
        $browser = $this->browser($this->ticket($this->vps()));
        $node = $this->nodeSide();

        $this->assertSame(['vega', 'qemu', 101], [$this->proxies[0]['node'], $this->proxies[0]['type'], $this->proxies[0]['vmid']]);
        $request = $this->read($node);
        $this->assertStringStartsWith("GET /api2/json/nodes/vega/qemu/101/vncwebsocket?port=5901&vncticket=PVEVNC%3AABC%2B%2F%3D HTTP/1.1\r\n", $request);
        $this->assertStringContainsString("Authorization: PVEAPIToken=mycs-console@pve!relay=s3cret\r\n", $request);
        preg_match('/Sec-WebSocket-Key: (\S+)/', $request, $m);
        fwrite($node, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . WebSocket::accept($m[1])
            . "\r\nSec-WebSocket-Protocol: binary\r\n\r\n");

        $answer = $this->read($browser);
        $this->assertStringStartsWith("HTTP/1.1 101 Switching Protocols\r\n", $answer);
        $this->assertStringContainsString("Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=\r\n", $answer);
        $this->assertStringContainsString("Sec-WebSocket-Protocol: binary\r\n", $answer);
        $this->assertStringNotContainsString('s3cret', $answer, 'the token never reaches the browser');
        $frames = substr($answer, strpos($answer, "\r\n\r\n") + 4);
        $first = WebSocket::decode($frames, false, 1024);
        $this->assertSame([WebSocket::TEXT, self::PASSWORD], [$first['opcode'], $first['payload']], 'the VNC password, first and as text');

        fwrite($browser, WebSocket::encode(WebSocket::BINARY, 'RFB 003.008', true));
        $frames = $this->read($node);
        $this->assertSame('RFB 003.008', WebSocket::decode($frames, true, 1024)['payload'], 'masked again towards the node');

        $screen = random_bytes(70000);
        fwrite($node, WebSocket::encode(WebSocket::BINARY, $screen, false));
        $frames = $this->read($browser);
        while (strlen($frames) < 70010) {
            $frames .= $this->read($browser, 0.5);
        }
        $this->assertSame($screen, WebSocket::decode($frames, false, 1 << 20)['payload']);

        fwrite($browser, WebSocket::encode(WebSocket::CLOSE, '', true));
        $this->read($node);
        $this->read($browser, 0.3);
        $this->assertSame(0, $this->relay->count(), 'a close ends the console');
    }

    public function test_the_relay_refuses_another_site_a_ticket_used_or_none(): void
    {
        $this->relay();
        $token = $this->ticket($this->vps());

        $this->assertStringStartsWith('HTTP/1.1 403', $this->read($this->browser($token, 'https://evil.example.test')));
        $this->assertStringStartsWith('HTTP/1.1 403', $this->read($this->browser('v1.nothing')));
        $this->assertSame([], $this->proxies, 'the cluster was never asked');
        $this->assertNull(@stream_socket_accept($this->node, 0) ?: null, 'the node was never asked');

        $this->browser($token); // this site's: it is taken, and the node asked
        $this->nodeSide();
        $this->assertStringStartsWith('HTTP/1.1 403', $this->read($this->browser($token)), 'once');

        $client = stream_socket_client('tcp://' . $this->relay->address());
        fwrite($client, "GET /ws HTTP/1.1\r\nHost: x\r\n\r\n");
        $this->assertStringStartsWith('HTTP/1.1 400', $this->read($client));
    }

    /** Two sites on one relay: a ticket of one is never opened from the other's page. */
    public function test_a_ticket_of_another_site_is_refused_even_from_a_site_of_the_relay(): void
    {
        $this->relay();
        $token = $this->ticket($this->vps(), 1, self::OTHER_SITE);

        $this->assertStringStartsWith('HTTP/1.1 403', $this->read($this->browser($token, self::ORIGIN)));
        $this->assertSame([], $this->proxies);
    }

    /** Two sites' service #1 are two VPS: each has its own PER_SERVICE. */
    public function test_the_per_service_cap_is_each_sites_own(): void
    {
        $this->relay();
        $held = [];
        for ($k = 0; $k < Relay::PER_SERVICE; $k++) {
            $held[] = $this->open(1);
        }
        $browser = $this->browser($this->ticket(1, 1, self::OTHER_SITE), self::OTHER_SITE);
        $held[] = $this->nodeSide();

        $this->assertSame(1, count(array_filter($this->consoles(), fn (array $c) => $c['ticket']['origin'] === self::OTHER_SITE)));
        $this->assertIsResource($browser);
    }

    public function test_a_node_that_refuses_is_a_502_for_the_browser(): void
    {
        $this->relay();
        $browser = $this->browser($this->ticket($this->vps()));
        $node = $this->nodeSide();
        $this->read($node);
        fwrite($node, "HTTP/1.1 401 No ticket\r\nContent-Length: 0\r\n\r\n");

        $this->assertStringStartsWith('HTTP/1.1 502', $this->read($browser));
    }

    /** The cluster refuses the VNC proxy (the relay's token without VM.Console, every node down): a 502, logged. */
    public function test_a_proxy_the_cluster_does_not_make_is_a_502(): void
    {
        $this->relay();
        $this->proxyRefused = 'https://vega.example.test:8006 answered 403';

        $this->assertStringStartsWith('HTTP/1.1 502', $this->read($this->browser($this->ticket($this->vps()))));
        $this->assertNull(@stream_socket_accept($this->node, 0) ?: null);
        $this->assertStringContainsString('answered 403', implode("\n", $this->logged));
    }

    /** The proxy is asked without waiting: the loop turns meanwhile, and an answer for a console gone opens nothing. */
    public function test_the_proxy_is_answered_later_and_an_answer_for_a_closed_console_is_ignored(): void
    {
        $this->relay();
        $this->later = [];
        $this->hold = true;
        $browser = $this->browser($this->ticket($this->vps()));
        for ($i = 0; $i < 5; $i++) {
            $this->relay->tick(0.01);
        }
        $this->assertSame('proxy', array_values($this->consoles())[0]['state'], 'the loop turns while the cluster is asked');
        $this->assertCount(1, $this->later);
        fclose($browser); // the browser gone before the cluster answered
        for ($i = 0; $i < 5; $i++) {
            $this->relay->tick(0.01);
        }
        $this->assertSame(0, $this->relay->count());
        $this->hold = false;
        $this->relay->tick(0.01); // the late answer

        $this->assertSame(0, $this->relay->count());
        $this->assertNull(@stream_socket_accept($this->node, 0) ?: null, 'no node connected for a console gone');
        $this->later = null;
        $this->open($this->vps()); // and the next console opens
    }

    /** Review of #506: a browser's frame is a key or a mouse move; a large one ends its console, not the relay. */
    public function test_a_browsers_large_frame_ends_its_console(): void
    {
        $this->relay();
        [$browser] = $this->open($this->vps());

        fwrite($browser, WebSocket::encode(WebSocket::BINARY, str_repeat('k', Relay::MAX_CLIENT_FRAME + 1), true));
        $this->read($browser, 0.5);
        $this->assertSame(0, $this->relay->count());
    }

    /** Round 2 of #506: a console whose browser reads nothing gives its slot back after BACKED seconds. */
    public function test_a_browser_that_reads_nothing_gives_its_slot_back(): void
    {
        $this->relay();
        [$browser, $node] = $this->open($this->vps());
        $screen = str_repeat(WebSocket::encode(WebSocket::BINARY, str_repeat('s', 65000), false), 16);
        for ($i = 0; $i < 300 && $this->relay->count() === 1; $i++) {
            @fwrite($node, $screen); // and the browser never reads
            $this->relay->tick(0.002);
            if ($i === 150) {
                $this->now += Relay::BACKED + 1;
            }
        }

        $this->assertSame(0, $this->relay->count());
    }

    /** The site's «no» (the service suspended, the login without access) closes the console at the next check. */
    public function test_the_sites_no_closes_the_console_at_the_next_check(): void
    {
        $this->relay();
        $service = $this->vps();
        $kept = $this->open($service);
        $other = $this->open($this->vps());
        $this->allowed[$service] = false;
        $this->now += Relay::RECHECK + 1;
        $this->relay->tick(0.01);

        $this->assertSame(1, $this->relay->count(), 'only the console of the service the site said «no» for');
        $this->assertCount(4, [...$kept, ...$other]);
    }

    /** A site that does not answer: «unknown», closed only after RECHECK_FAILURES in a row; a check that throws, the same. */
    public function test_a_site_that_does_not_answer_closes_its_consoles_only_after_three_checks(): void
    {
        $this->relay();
        $sides = $this->open($this->vps());
        $this->siteDown = true;
        for ($k = 1; $k < Relay::RECHECK_FAILURES; $k++) {
            $this->now += Relay::RECHECK + 1;
            $this->relay->tick(0.01);
            fwrite($sides[0], WebSocket::encode(WebSocket::PONG, 'x', true)); // the browser alive
            $this->read($sides[0], 0.05);
        }
        $this->assertSame(1, $this->relay->count());
        $this->now += Relay::RECHECK + 1;
        $this->relay->tick(0.01);

        $this->assertSame(0, $this->relay->count());
    }

    /** ADV-506-7, for the tickets: a callback that throws on one handshake never ends the relay. */
    public function test_a_tickets_callback_that_throws_ends_no_console(): void
    {
        $this->relay();
        $kept = $this->open($this->vps()); // kept: a side let go is a close
        $this->ticketsFail = true;
        $this->browser('v1.anything');
        for ($i = 0; $i < 10; $i++) {
            $this->relay->tick(0.02);
        }

        $this->assertSame(1, $this->relay->count());
        $this->assertCount(2, $kept);
    }

    public function test_a_relay_that_cannot_listen_says_so(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Relay('256.0.0.1:1', [], '/ws', fn () => null, fn () => null, fn () => throw new RuntimeException);
    }
}
