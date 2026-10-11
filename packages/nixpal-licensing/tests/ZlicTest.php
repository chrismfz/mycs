<?php

namespace Nixpal\Licensing\Tests;

use Nixpal\Licensing\License;
use Nixpal\Licensing\Tests\Oracle\ZlicReference;
use Nixpal\Licensing\Zlic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ZlicTest extends TestCase
{
    const SECRET = 'TestProductSecret';

    const TOKEN = '1791460800deadbeefdeadbeefdeadbeefdeadbeef';

    const NOW = 1791460800; // 2026-10-08 12:00:00 UTC

    const BOUND_IP = '198.51.100.7';

    const OTHER_IP = '203.0.113.9';

    const DIR = '/home/clnixpal/public_html';

    /** @var InMemoryRepository */
    private $repo;

    /** @var string[] */
    private $rdnsCalls;

    /** @var Zlic */
    private $zlic;

    protected function setUp(): void
    {
        date_default_timezone_set('UTC');
        $this->repo = new InMemoryRepository;
        $this->rdnsCalls = [];
        $this->zlic = new Zlic(
            $this->repo,
            function ($ip) {
                $this->rdnsCalls[] = $ip;

                return 'host-' . str_replace('.', '-', $ip) . '.example.net';
            },
            function () {
                return self::NOW;
            },
            function () {
                return self::TOKEN;
            }
        );
    }

    private static function fixture($case)
    {
        $all = json_decode(file_get_contents(__DIR__ . '/fixtures/zlic/verify_responses.json'), true);

        return $all[$case];
    }

    /** A License whose verify.php output is exactly the given fixture. */
    private function licenseFromFixture($case, $key = 'ZCAgent-0123456789abcdef')
    {
        $tags = self::fixture($case);
        $license = new License;
        $license->id = 42;
        $license->licenseKey = $key;
        $license->status = $tags['status'];
        $license->validDomain = $tags['validdomain'];
        $license->validIp = $tags['validip'];
        $license->validDirectory = $tags['validdirectory'];
        $license->secret = self::SECRET;
        unset($tags['status'], $tags['validdomain'], $tags['validip'], $tags['validdirectory'], $tags['md5hash']);
        $license->serviceTags = $tags;
        $this->repo->add($license);

        return $license;
    }

    /** verify.php fixture with the md5hash it would really carry. */
    private static function verifyTags($case)
    {
        $tags = self::fixture($case);
        $tags['md5hash'] = md5(self::SECRET . self::TOKEN);

        return $tags;
    }

    private function call($key, $psk = self::SECRET, $ip = self::BOUND_IP)
    {
        return $this->zlic->handle(['license' => $key, 'license_PSK' => $psk], $ip);
    }

    private function messages()
    {
        return array_column($this->repo->logs, 'message');
    }

    // --- request validation -------------------------------------------------

    public static function provideMissing()
    {
        return [
            'nothing' => [[]],
            'no PSK' => [['license' => 'ZCAgent-0123456789abcdef']],
            'no key' => [['license_PSK' => self::SECRET]],
            'empty strings' => [['license' => '', 'license_PSK' => '']],
        ];
    }

    #[DataProvider('provideMissing')]
    public function test_missing_parameters_give400($params)
    {
        $response = $this->zlic->handle($params, self::BOUND_IP);

        $this->assertSame(400, $response->httpStatus);
        $this->assertSame('{"status":"Invalid","description":"Missing license or license_PSK"}', $response->json());
        $this->assertSame([], $this->repo->logs);
    }

    // --- unknown key ----------------------------------------------------------

    public function test_unknown_key()
    {
        $response = $this->call('ZCAgent-0000000000000000');

        $this->assertSame(200, $response->httpStatus);
        $this->assertSame('{"status":"Invalid","remotecheck":true}', $response->json());
        $this->assertSame(['Invalid Key - ZCAgent-0000000000000000'], $this->messages());
        $this->assertSame(self::DIR, $this->repo->logs[0]['dir']);
    }

    // --- Active ---------------------------------------------------------------

    public function test_active_is_byte_identical_to_zlic_over_verify()
    {
        $license = $this->licenseFromFixture('active_devnode');

        $response = $this->call($license->licenseKey);

        $expected = ZlicReference::activeResults(self::verifyTags('active_devnode'), self::SECRET, '20261008');
        $this->assertSame(200, $response->httpStatus);
        $this->assertSame(json_encode($expected), $response->json());
        $this->assertSame([], $this->repo->logs, 'successful checks are not logged');
        $this->assertSame([], $this->rdnsCalls, 'no reverse DNS on the hot path');
        $this->assertSame('2026-10-08 12:00:00', $this->repo->licenses[$license->licenseKey]->lastAccess);
    }

    public function test_active_from_other_ip_is_rejected()
    {
        $license = $this->licenseFromFixture('active_devnode');

        $response = $this->call($license->licenseKey, self::SECRET, self::OTHER_IP);

        $this->assertSame('{"status":"Invalid","message":"IP Address Invalid","remotecheck":true}', $response->json());
        $this->assertSame(['IP Address Invalid'], $this->messages());
        $this->assertSame(self::BOUND_IP, $this->repo->licenses[$license->licenseKey]->validIp);
    }

    public function test_ip_conflict_allowed_accepts_any_ip_without_rebinding()
    {
        $license = $this->licenseFromFixture('active_devnode');
        $license->allowIpConflict = true;
        $this->repo->add($license);

        $response = $this->call($license->licenseKey, self::SECRET, self::OTHER_IP);

        $this->assertSame('Active', $response->body['status']);
        $this->assertSame(self::BOUND_IP, $response->body['validip']);
        $this->assertSame(self::BOUND_IP, $this->repo->licenses[$license->licenseKey]->validIp);
    }

    public function test_any_of_several_bound_ips_is_accepted()
    {
        $license = $this->licenseFromFixture('active_devnode');
        $license->validIp = self::OTHER_IP . ',' . self::BOUND_IP;
        $this->repo->add($license);

        $this->assertSame('Active', $this->call($license->licenseKey)->body['status']);
    }

    public function test_first_check_binds_ip_domain_and_directory()
    {
        $license = $this->licenseFromFixture('active_devnode');
        $license->validIp = $license->validDomain = $license->validDirectory = '';
        $this->repo->add($license);

        $response = $this->call($license->licenseKey, self::SECRET, self::OTHER_IP);

        $stored = $this->repo->licenses[$license->licenseKey];
        $this->assertSame(self::OTHER_IP, $stored->validIp);
        $this->assertSame('host-203-0-113-9.example.net,www.host-203-0-113-9.example.net', $stored->validDomain);
        $this->assertSame(self::DIR, $stored->validDirectory);
        $this->assertSame('Active', $response->body['status']);
        $this->assertSame(self::OTHER_IP, $response->body['validip']);
        $this->assertArrayHasKey('localkey', $response->body);
    }

    public function test_reissued_rebinds_to_new_ip_and_becomes_active()
    {
        $license = $this->licenseFromFixture('active_devnode');
        $license->status = License::REISSUED;
        $license->validIp = $license->validDomain = $license->validDirectory = '';
        $this->repo->add($license);

        $response = $this->call($license->licenseKey, self::SECRET, self::OTHER_IP);

        $stored = $this->repo->licenses[$license->licenseKey];
        $this->assertSame(License::ACTIVE, $stored->status);
        $this->assertSame(self::OTHER_IP, $stored->validIp);
        $this->assertSame('Active', $response->body['status']);
    }

    // --- Suspended / Expired --------------------------------------------------

    public static function provideInactive()
    {
        return [['expired', 'License Expired'], ['suspended', 'License Suspended']];
    }

    #[DataProvider('provideInactive')]
    public function test_inactive_matches_zlic_over_verify($case, $logMessage)
    {
        $license = $this->licenseFromFixture($case);

        // even from a foreign IP: status wins over the IP check, as in WHMCS
        $response = $this->call($license->licenseKey, self::SECRET, self::OTHER_IP);

        $expected = ZlicReference::activeResults(self::verifyTags($case), self::SECRET, '20261008');
        $this->assertSame(json_encode($expected), $response->json());
        $this->assertArrayNotHasKey('localkey', $response->body);
        $this->assertSame([$logMessage], $this->messages());
        $this->assertSame('2026-10-08 12:00:00', $this->repo->licenses[$license->licenseKey]->lastAccess);
    }

    // --- wrong PSK ------------------------------------------------------------

    public static function provideWrongPsk()
    {
        return [['active_devnode'], ['expired'], ['suspended']];
    }

    #[DataProvider('provideWrongPsk')]
    public function test_wrong_psk_is_rejected_like_zlic($case)
    {
        $license = $this->licenseFromFixture($case);

        $response = $this->call($license->licenseKey, 'OtherProductSecret');

        $expected = self::verifyTags($case);
        $expected['status'] = 'Invalid';
        $expected['description'] = 'MD5 Checksum Verification Failed';
        $this->assertSame(200, $response->httpStatus);
        $this->assertSame(json_encode($expected), $response->json());
    }

    public function test_wrong_psk_never_binds()
    {
        $license = $this->licenseFromFixture('active_devnode');
        $license->validIp = $license->validDomain = $license->validDirectory = '';
        $this->repo->add($license);

        $response = $this->call($license->licenseKey, 'OtherProductSecret', self::OTHER_IP);

        $this->assertSame('Invalid', $response->body['status']);
        $this->assertSame('', $this->repo->licenses[$license->licenseKey]->validIp);
        $this->assertSame(['MD5 Checksum Verification Failed'], $this->messages());
    }

    public function test_ip_mismatch_is_reported_before_wrong_psk()
    {
        $license = $this->licenseFromFixture('active_devnode');

        $response = $this->call($license->licenseKey, 'OtherProductSecret', self::OTHER_IP);

        $this->assertSame('{"status":"Invalid","message":"IP Address Invalid","remotecheck":true}', $response->json());
    }

    public function test_when_another_request_binds_first_the_caller_is_checked_against_that_binding()
    {
        $license = $this->licenseFromFixture('active_devnode');
        $this->repo->licenses[$license->licenseKey]->validIp = '';
        $this->repo->beforeBind = function ($repo) use ($license) {
            $repo->beforeBind = null;
            $repo->licenses[$license->licenseKey]->validIp = '192.0.2.50'; // the other request won
        };

        $response = $this->zlic->handle(['license' => $license->licenseKey, 'license_PSK' => self::SECRET], self::OTHER_IP);

        $this->assertSame(['status' => 'Invalid', 'message' => 'IP Address Invalid', 'remotecheck' => true], $response->body);
        $this->assertSame('192.0.2.50', $this->repo->licenses[$license->licenseKey]->validIp);
    }

    public function test_a_suspend_during_the_first_bind_is_answered_as_suspended()
    {
        $license = $this->licenseFromFixture('active_devnode');
        $this->repo->licenses[$license->licenseKey]->validIp = '';
        $this->repo->beforeBind = function ($repo) use ($license) {
            $repo->beforeBind = null;
            $repo->licenses[$license->licenseKey]->status = 'Suspended'; // staff, meanwhile
        };

        $response = $this->zlic->handle(['license' => $license->licenseKey, 'license_PSK' => self::SECRET], self::OTHER_IP);

        $this->assertSame('Suspended', $response->body['status']);
        $this->assertArrayNotHasKey('localkey', $response->body);
        $this->assertSame('', $this->repo->licenses[$license->licenseKey]->validIp);
    }
}
