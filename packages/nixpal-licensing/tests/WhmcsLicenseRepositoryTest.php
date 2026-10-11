<?php

namespace Nixpal\Licensing\Tests;

use Nixpal\Licensing\Tests\Oracle\ZlicReference;
use Nixpal\Licensing\WhmcsLicenseRepository;
use Nixpal\Licensing\Zlic;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WhmcsLicenseRepositoryTest extends TestCase
{
    const TOKEN = '1791460800deadbeefdeadbeefdeadbeefdeadbeef';

    const NOW = 1791460800; // 2026-10-08 12:00:00 UTC

    /** @var PDO */
    private $db;

    protected function setUp(): void
    {
        date_default_timezone_set('UTC');
        $this->db = new PDO('sqlite::memory:');
        WhmcsSchema::create($this->db);
    }

    private function zlic($readOnly = false)
    {
        $clock = function () {
            return self::NOW;
        };

        return new Zlic(
            new WhmcsLicenseRepository($this->db, $readOnly, $clock),
            function ($ip) {
                return 'rdns.example.net';
            },
            $clock,
            function () {
                return self::TOKEN;
            }
        );
    }

    private static function fixture($case)
    {
        $tags = json_decode(file_get_contents(__DIR__ . '/fixtures/zlic/verify_responses.json'), true)[$case];
        $tags['md5hash'] = md5(WhmcsSchema::SECRET . self::TOKEN);

        return $tags;
    }

    public static function provideFixtures()
    {
        return [
            'active_devnode' => ['ZCNode-00000000000000aa', 'active_devnode', '1001'],
            'expired' => ['ZCAgent-00000000000000bb', 'expired', '1002'],
            'suspended' => ['ZCWeb-00000000000000cc', 'suspended', '1003'],
        ];
    }

    #[DataProvider('provideFixtures')]
    public function test_reads_whmcs_rows_into_the_verify_tags_of_the_fixtures($key, $case, $serviceId)
    {
        // the fixtures anonymise every serviceid to 1001
        $tags = self::fixture($case);
        $tags['serviceid'] = $serviceId;

        $response = $this->zlic()->handle(['license' => $key, 'license_PSK' => WhmcsSchema::SECRET], '198.51.100.7');

        $expected = ZlicReference::activeResults($tags, WhmcsSchema::SECRET, '20261008');
        $this->assertSame(json_encode($expected), $response->json());
    }

    public function test_binding_and_log_are_written()
    {
        $this->db->exec("UPDATE mod_licensing SET validip = '', validdomain = '', validdirectory = '', status = 'Reissued' WHERE id = 1");

        $this->zlic()->handle(['license' => 'ZCNode-00000000000000aa', 'license_PSK' => WhmcsSchema::SECRET], '203.0.113.9');
        $this->zlic()->handle(['license' => 'ZCAgent-00000000000000bb', 'license_PSK' => WhmcsSchema::SECRET], '203.0.113.9');

        $row = $this->db->query('SELECT * FROM mod_licensing WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('203.0.113.9', $row['validip']);
        $this->assertSame('rdns.example.net,www.rdns.example.net', $row['validdomain']);
        $this->assertSame('Active', $row['status']);
        $this->assertSame('2026-10-08 12:00:00', $row['lastaccess']);

        $log = $this->db->query('SELECT licenseid, domain, ip, path, message, datetime FROM mod_licensinglog')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame([[
            'licenseid' => 2,
            'domain' => 'rdns.example.net',
            'ip' => '203.0.113.9',
            'path' => '/home/clnixpal/public_html',
            'message' => 'License Expired',
            'datetime' => '2026-10-08 12:00:00',
        ]], $log);
    }

    public function test_unknown_key_is_logged_with_license_id_zero()
    {
        $this->zlic()->handle(['license' => 'ZCAgent-ffffffffffffffff', 'license_PSK' => 'x'], '203.0.113.9');

        $this->assertSame(
            ['0', 'Invalid Key - ZCAgent-ffffffffffffffff'],
            array_map('strval', $this->db->query('SELECT licenseid, message FROM mod_licensinglog')->fetch(PDO::FETCH_NUM))
        );
    }

    public function test_read_only_never_writes()
    {
        $this->db->exec("UPDATE mod_licensing SET validip = '' WHERE id = 1");
        $before = $this->db->query('SELECT * FROM mod_licensing ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        $zlic = $this->zlic(true);
        $first = $zlic->handle(['license' => 'ZCNode-00000000000000aa', 'license_PSK' => WhmcsSchema::SECRET], '203.0.113.9');
        $zlic->handle(['license' => 'ZCAgent-00000000000000bb', 'license_PSK' => WhmcsSchema::SECRET], '203.0.113.9');
        $zlic->handle(['license' => 'nope', 'license_PSK' => 'x'], '203.0.113.9');

        $this->assertSame('Active', $first->body['status'], 'answers as if it had bound');
        $this->assertSame($before, $this->db->query('SELECT * FROM mod_licensing ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame('0', (string) $this->db->query('SELECT COUNT(*) FROM mod_licensinglog')->fetchColumn());
    }

    public function test_custom_field_values_are_urlencoded()
    {
        $this->db->exec("INSERT INTO tblcustomfieldsvalues VALUES (1, 3, 1002, 'agent #1 & co')");

        $response = $this->zlic()->handle(['license' => 'ZCAgent-00000000000000bb', 'license_PSK' => WhmcsSchema::SECRET], '198.51.100.7');

        $this->assertSame('comments=agent+%231+%26+co', $response->body['customfields']);
    }
}
