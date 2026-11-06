<?php

namespace MyCS\Proxmox\Tests\Console;

use Closure;
use InvalidArgumentException;
use MyCS\Proxmox\Console\AsyncHttp;
use MyCS\Proxmox\Console\ConsoleTicket;
use MyCS\Proxmox\Console\StandaloneRelay;
use PHPUnit\Framework\TestCase;

/**
 * The relay next to the cluster, from its config (docs/proxmox.md, «Σχέδιο: το relay στο cluster»): which tickets it
 * takes, how it asks the VNC proxy with its own token, and how it asks each site about its consoles.
 */
class StandaloneRelayTest extends TestCase
{
    private const KEY = 'nixpal-key-of-at-least-32-characters-0123';

    private const OTHER_KEY = 'myip-key-of-at-least-32-characters-012345';

    private int $now = 1000;

    /** @var list<array{method: string, url: string, headers: array<int, string>, body: string, tls: array<string, mixed>, done: Closure}> */
    private array $requests = [];

    private function config(): array
    {
        return ['sites' => [
            ['origin' => 'https://my.nixpal.test', 'keys' => [self::KEY], 'clusters' => [7 => [
                'nodes' => [['base' => 'https://pve1.example.test:8006', 'ip' => '10.0.0.11'], ['base' => 'https://pve2.example.test:8006', 'ip' => '10.0.0.12']],
                'self_signed' => true, 'token_id' => 'mycs-console@pve!relay', 'token_secret' => 's3cret']]],
            ['origin' => 'https://my.myip.test', 'keys' => [self::OTHER_KEY], 'check_url' => 'https://my.myip.test/check', 'clusters' => [3 => [
                'nodes' => [['base' => 'https://pve9.example.test:8006']], 'token_id' => 'myip-console@pve!relay', 'token_secret' => 'other']]],
        ]];
    }

    private function relay(?array $config = null): StandaloneRelay
    {
        $http = new class($this->requests) extends AsyncHttp
        {
            public function __construct(private array &$requests)
            {
                parent::__construct();
            }

            public function request(string $method, string $url, array $headers, string $body, array $tls, Closure $done): void
            {
                $this->requests[] = compact('method', 'url', 'headers', 'body', 'tls', 'done');
            }

            public function poll(): bool
            {
                return false;
            }
        };

        return new StandaloneRelay($config ?? $this->config(), $http, fn () => $this->now);
    }

    private function sign(string $origin, int $server, string $key, ?int $at = null): string
    {
        return ConsoleTicket::sign(['origin' => $origin, 'server_id' => $server, 'service_id' => 42, 'user_id' => 3, 'node' => 'vega',
            'type' => 'qemu', 'vmid' => 101], $key, $at ?? $this->now);
    }

    public function test_a_ticket_is_taken_once_and_only_with_its_own_sites_key_and_cluster(): void
    {
        $relay = $this->relay();
        $token = $this->sign('https://my.nixpal.test', 7, self::KEY);

        $this->assertSame(42, $relay->ticket($token)['service_id'] ?? null);
        $this->assertNull($relay->ticket($token), 'once');
        $this->assertNull($relay->ticket($this->sign('https://my.nixpal.test', 7, self::OTHER_KEY)), 'another site\'s key, naming this site');
        $this->assertNull($relay->ticket($this->sign('https://my.nixpal.test', 3, self::KEY)), 'another site\'s cluster');
        $this->assertNull($relay->ticket($this->sign('https://unknown.test', 7, self::KEY)));
        $this->assertNotNull($relay->ticket($this->sign('https://my.myip.test', 3, self::OTHER_KEY)));
    }

    /** A restart forgets the used tickets: one issued before the process started is not taken. */
    public function test_a_ticket_issued_before_the_relay_started_is_refused(): void
    {
        $old = $this->sign('https://my.nixpal.test', 7, self::KEY, 995);
        $relay = $this->relay();

        $this->assertNull($relay->ticket($old));
    }

    public function test_the_proxy_is_asked_with_the_relays_token_node_after_node(): void
    {
        $relay = $this->relay();
        $ticket = $relay->ticket($this->sign('https://my.nixpal.test', 7, self::KEY));
        $answers = [];
        $relay->prepare($ticket, function (?array $proxy, ?string $error) use (&$answers) {
            $answers[] = [$proxy, $error];
        });

        $first = $this->requests[0];
        $this->assertSame(['POST', 'https://pve1.example.test:8006/api2/json/nodes/vega/qemu/101/vncproxy', 'websocket=1'], [$first['method'], $first['url'], $first['body']]);
        $this->assertContains('Authorization: PVEAPIToken=mycs-console@pve!relay=s3cret', $first['headers']);
        $this->assertSame(['ip' => '10.0.0.11', 'self_signed' => true], $first['tls']);
        ($first['done'])(0, '', 'Connection timed out');
        $this->assertSame([], $answers, 'the next node is asked');
        $second = $this->requests[1];
        $this->assertSame('https://pve2.example.test:8006/api2/json/nodes/vega/qemu/101/vncproxy', $second['url']);
        ($second['done'])(200, '{"data":{"port":"5901","ticket":"PVEVNC:ABC","user":"mycs-console@pve!relay"}}', null);

        $this->assertSame([[['port' => 5901, 'ticket' => 'PVEVNC:ABC', 'base' => 'https://pve2.example.test:8006'], null]], $answers);
    }

    public function test_no_node_that_makes_the_proxy_is_one_answer_with_the_last_error(): void
    {
        $relay = $this->relay();
        $ticket = $relay->ticket($this->sign('https://my.nixpal.test', 7, self::KEY));
        $answers = [];
        $relay->prepare($ticket, function (?array $proxy, ?string $error) use (&$answers) {
            $answers[] = [$proxy, $error];
        });
        ($this->requests[0]['done'])(403, '{"data":null}', null);
        ($this->requests[1]['done'])(403, '{"data":null}', null);

        $this->assertSame([[null, 'https://pve2.example.test:8006 answered 403']], $answers);
    }

    public function test_the_relay_connects_the_node_that_made_the_proxy_without_waiting(): void
    {
        $relay = $this->relay();
        $ticket = $relay->ticket($this->sign('https://my.nixpal.test', 7, self::KEY));
        $started = microtime(true);
        $node = $relay->connect($ticket, ['port' => 5901, 'ticket' => 'x', 'base' => 'https://pve2.example.test:8006']);

        $this->assertLessThan(1.0, microtime(true) - $started);
        $this->assertSame(['pve2.example.test', 8006, true, 'PVEAPIToken=mycs-console@pve!relay=s3cret'],
            [$node['host'], $node['port'], $node['tls'], $node['authorization']]);
        fclose($node['stream']);
        $this->expectException(\RuntimeException::class);
        $relay->connect($ticket, ['port' => 5901, 'ticket' => 'x', 'base' => 'https://elsewhere.example.test:8006']);
    }

    public function test_the_check_asks_each_site_once_signed_and_reads_its_answers(): void
    {
        $relay = $this->relay();
        $a = $relay->ticket($this->sign('https://my.nixpal.test', 7, self::KEY));
        $b = $relay->ticket($this->sign('https://my.nixpal.test', 7, self::KEY));
        $c = $relay->ticket($this->sign('https://my.myip.test', 3, self::OTHER_KEY));
        $answers = null;
        $relay->check([11 => $a, 12 => $b, 13 => $c], function (array $got) use (&$answers) {
            $answers = $got;
        });

        $this->assertCount(2, $this->requests);
        [$nixpal, $myip] = $this->requests;
        $this->assertSame('https://my.nixpal.test/api/proxmox/console/check', $nixpal['url']);
        $this->assertSame('https://my.myip.test/check', $myip['url']);
        $this->assertContains('X-Console-Signature: ' . ConsoleTicket::checkSignature($nixpal['body'], self::KEY), $nixpal['headers']);
        $body = json_decode($nixpal['body'], true);
        $this->assertSame(1000, $body['ts']);
        $this->assertSame([11, 12], array_column($body['consoles'], 'id'));
        $this->assertSame(['id' => 11, 'jti' => $a['jti'], 'server_id' => 7, 'service_id' => 42, 'user_id' => 3, 'vmid' => 101], $body['consoles'][0]);

        ($nixpal['done'])(200, '{"allowed":{"11":true,"12":false}}', null);
        $this->assertNull($answers, 'the other site not answered yet');
        ($myip['done'])(0, '', 'Connection refused');

        $this->assertSame([11 => true, 12 => false, 13 => null], $answers);
    }

    public function test_a_config_not_sound_is_refused_at_the_start(): void
    {
        $site = fn (array $over) => ['sites' => [$over + $this->config()['sites'][0]]];
        $cluster = fn (array $over) => $site(['clusters' => [7 => $over + ['nodes' => [['base' => 'https://pve1.example.test:8006', 'ip' => '10.0.0.11']],
            'token_id' => 'a@pve!b', 'token_secret' => 'c']]]);
        $bad = [
            ['sites' => []],
            $site(['origin' => 'my.nixpal.test']),
            $site(['keys' => ['short']]),
            $site(['clusters' => []]),
            $cluster(['nodes' => [['base' => 'https://pve1.example.test:8006']], 'self_signed' => true]), // self-signed without the IP
            $cluster(['nodes' => [['base' => 'http://pve1.example.test:8006']]]),
            $cluster(['token_id' => 'a']),
            $cluster(['token_secret' => '']),
        ];
        $this->assertNotNull($this->relay($cluster([])), 'setup: the cluster above is sound');
        foreach ($bad as $n => $config) {
            try {
                $this->relay($config);
                $this->fail("config {$n} taken");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
