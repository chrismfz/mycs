<?php

namespace Nixpal\Licensing\Tests;

use Nixpal\Licensing\LocalKey;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * public/zlic.php end to end, through PHP's built-in web server, against a
 * SQLite copy of the WHMCS schema: the exact file that goes into the docroot.
 */
final class ZlicEndpointTest extends TestCase
{
    /** @var resource */
    private static $server;

    /** @var string */
    private static $base;

    /** @var string */
    private static $home;

    public static function setUpBeforeClass(): void
    {
        $root = sys_get_temp_dir() . '/zlic-endpoint-' . getmypid();
        self::$home = $root . '/zlic';
        @mkdir($root . '/public_html', 0777, true);
        @mkdir(self::$home, 0777, true);

        copy(__DIR__ . '/../public/zlic.php', $root . '/public_html/zlic.php');
        @symlink(realpath(__DIR__ . '/../../../vendor'), self::$home . '/vendor'); // the monorepo's vendor/

        $dbFile = $root . '/whmcs.sqlite';
        @unlink($dbFile);
        WhmcsSchema::create(new PDO('sqlite:' . $dbFile));
        file_put_contents(self::$home . '/config.php', '<?php return ' . var_export([
            'dsn' => 'sqlite:' . $dbFile,
            'username' => null,
            'password' => null,
            'schema' => 'whmcs',
            'read_only' => false,
        ], true) . ';');

        $port = 18000 + getmypid() % 1000;
        self::$base = "http://127.0.0.1:$port/zlic.php";
        self::$server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root . '/public_html'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$server);
    }

    private function request($method, array $params)
    {
        $query = http_build_query($params);
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $method === 'POST' ? $query : '',
            'ignore_errors' => true,
        ]]);
        $url = $method === 'GET' ? self::$base . '?' . $query : self::$base;
        $body = file_get_contents($url, false, $context);
        preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0], $m);

        return [(int) $m[1], $body, $http_response_header];
    }

    public function test_missing_parameters()
    {
        [$status, $body] = $this->request('POST', []);

        $this->assertSame(400, $status);
        $this->assertSame('{"status":"Invalid","description":"Missing license or license_PSK"}', $body);
    }

    public function test_active_over_post_and_legacy_get()
    {
        foreach (['POST', 'GET'] as $method) {
            [$status, $body, $headers] = $this->request($method, [
                'license' => 'ZCNode-00000000000000aa',
                'license_PSK' => WhmcsSchema::SECRET,
            ]);
            $json = json_decode($body, true);

            $this->assertSame(200, $status, $method);
            $this->assertContains('Content-Type: application/json', $headers, $method);
            $this->assertSame('Active', $json['status'], $method);
            $this->assertNotEmpty($json['localkey'], $method);
            $this->assertNotNull(LocalKey::decode($json['localkey'], WhmcsSchema::SECRET), $method);
        }
    }

    public function test_broken_config_answers_remote_check_failed()
    {
        $config = self::$home . '/config.php';
        $good = file_get_contents($config);
        file_put_contents($config, '<?php return ["dsn" => "mysql:host=127.0.0.1;port=1;dbname=x", "username" => "u", "password" => "p", "schema" => "whmcs"];');
        try {
            [$status, $body] = $this->request('POST', ['license' => 'ZCNode-00000000000000aa', 'license_PSK' => 'x']);
        } finally {
            file_put_contents($config, $good);
        }

        $this->assertSame(200, $status);
        $this->assertSame('{"status":"Invalid","description":"Remote Check Failed"}', $body);
    }
}
