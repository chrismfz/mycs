<?php

namespace MyCS\Proxmox\Tests\Console;

use Closure;
use MyCS\Proxmox\Console\AsyncHttp;
use MyCS\Proxmox\Console\StandaloneRelay;
use PHPUnit\Framework\TestCase;

/**
 * Review of #675 (1), the reviewer's test: a self-signed node written by its IP (no `ip`) passed the config, and
 * `connect` reached it, but its VNC proxy was asked with the certificate checked: every console a 502. On a real TLS
 * server of its own (openssl s_server), as curl sees it.
 */
class StandaloneRelaySelfSignedTest extends TestCase
{
    public function test_a_self_signed_node_named_by_its_ip_gets_its_vnc_proxy(): void
    {
        if (!is_executable('/usr/bin/openssl') && trim((string) shell_exec('command -v openssl')) === '') {
            $this->markTestSkipped('openssl');
        }
        $dir = sys_get_temp_dir() . '/review675-' . bin2hex(random_bytes(4));
        mkdir($dir);
        exec('openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=pve -keyout ' . escapeshellarg("{$dir}/k.pem")
            . ' -out ' . escapeshellarg("{$dir}/c.pem") . ' 2>/dev/null');
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        $server = proc_open(['openssl', 's_server', '-quiet', '-accept', (string) $port, '-cert', "{$dir}/c.pem", '-key', "{$dir}/k.pem", '-www'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        usleep(300000);
        $seen = [];
        // s_server answers a GET with its status page: a 200 stands for the cluster's answer.
        $http = new class($seen) extends AsyncHttp
        {
            public function __construct(private array &$seen)
            {
                parent::__construct();
            }

            public function request(string $method, string $url, array $headers, string $body, array $tls, Closure $done, int $timeout = self::TIMEOUT): void
            {
                parent::request('GET', $url, [], '', $tls, function (int $status, string $answer, ?string $error) use ($done) {
                    $this->seen[] = [$status, $error];
                    $done($status, $status === 200 ? '{"data":{"port":5900,"ticket":"PVEVNC:x"}}' : $answer, $error);
                }, $timeout);
            }
        };
        $relay = new StandaloneRelay(['sites' => [['origin' => 'https://my.nixpal.test', 'clusters' => [7 => ['keys' => [str_repeat('k', 40)],
            'nodes' => [['base' => "https://127.0.0.1:{$port}"]], 'self_signed' => true, 'token_id' => 'mycs-console@pve!relay', 'token_secret' => 's3cret']]]]], $http);
        $result = null;
        $relay->prepare(['origin' => 'https://my.nixpal.test', 'server_id' => 7, 'node' => 'vega', 'type' => 'qemu', 'vmid' => 101],
            function (?array $proxy, ?string $error) use (&$result) {
                $result = [$proxy, $error];
            });
        for ($i = 0; $i < 400 && $result === null; $i++) {
            $http->poll();
            usleep(5000);
        }
        proc_terminate($server);
        array_map('unlink', glob("{$dir}/*") ?: []);
        rmdir($dir);

        $this->assertSame(5900, $result[0]['port'] ?? null, (string) json_encode([$result[1] ?? null, $seen]));
    }
}
