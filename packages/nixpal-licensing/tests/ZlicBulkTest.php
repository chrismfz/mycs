<?php

namespace Nixpal\Licensing\Tests;

use Nixpal\Licensing\License;
use Nixpal\Licensing\LocalKey;
use Nixpal\Licensing\Tests\Oracle\ZlicReference;
use Nixpal\Licensing\Zlic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A bulk license: one key, a list of IPs (docs/zonecloud-bulk-license.md §5). The single license's answers stay as
 * ZlicTest pins them; these pin the bulk branch.
 */
final class ZlicBulkTest extends TestCase
{
    const SECRET = 'TestProductSecret';

    const TOKEN = '1791460800deadbeefdeadbeefdeadbeefdeadbeef';

    const NOW = 1791460800; // 2026-10-08 12:00:00 UTC

    const KEY = 'ZCAgent-00000000bbbbbbbb';

    const LISTED = '198.51.100.7';

    const ALSO_LISTED = '198.51.100.8';

    const FOREIGN = '203.0.113.9';

    const DIR = '/home/clnixpal/public_html';

    const IP_INVALID = '{"status":"Invalid","message":"IP Address Invalid","remotecheck":true}';

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

                return 'host-' . str_replace(['.', ':'], '-', $ip) . '.example.net';
            },
            function () {
                return self::NOW;
            },
            function () {
                return self::TOKEN;
            }
        );
    }

    /** The service tags of a real Active answer (the fixture), on a bulk license with the given list. */
    private function bulk(array $ips, $status = License::ACTIVE)
    {
        $tags = json_decode(file_get_contents(__DIR__ . '/fixtures/zlic/verify_responses.json'), true)['active_devnode'];
        unset($tags['status'], $tags['validdomain'], $tags['validip'], $tags['validdirectory'], $tags['md5hash']);
        $license = new License;
        $license->id = 77;
        $license->licenseKey = self::KEY;
        $license->status = $status;
        $license->secret = self::SECRET;
        $license->serviceTags = $tags;
        $license->ipList = $ips;
        $this->repo->add($license);

        return $license;
    }

    private function call($ip, $psk = self::SECRET)
    {
        return $this->zlic->handle(['license' => self::KEY, 'license_PSK' => $psk], $ip);
    }

    private function messages()
    {
        return array_column($this->repo->logs, 'message');
    }

    /** What zlic.php over verify.php gives for these tags, in verify.php's order. */
    private function expectedActive(License $license, $callerIp)
    {
        $results = ['status' => 'Active'] + $license->serviceTags
            + ['validip' => $callerIp, 'validdirectory' => self::DIR, 'md5hash' => md5(self::SECRET . self::TOKEN)];

        return json_encode(ZlicReference::activeResults($results, self::SECRET, '20261008'));
    }

    public function test_a_listed_ip_gets_the_answer_zlic_gives_with_the_caller_alone_as_validip()
    {
        $license = $this->bulk([self::ALSO_LISTED, self::LISTED]);

        $response = $this->call(self::LISTED);

        $this->assertSame(200, $response->httpStatus);
        $this->assertSame($this->expectedActive($license, self::LISTED), $response->json());
        $this->assertArrayNotHasKey('validdomain', $response->body);
        $this->assertSame([], $this->repo->logs, 'successful checks are not logged');
        $this->assertSame([], $this->rdnsCalls, 'no reverse DNS on the hot path');
        $this->assertSame('2026-10-08 12:00:00', $this->repo->licenses[self::KEY]->lastAccess);
        $this->assertSame('20261008', LocalKey::decode($response->body['localkey'], self::SECRET)['checkdate']);
    }

    public function test_every_listed_ip_is_answered_and_none_learns_the_others()
    {
        $this->bulk([self::LISTED, self::ALSO_LISTED]);

        foreach ([self::LISTED, self::ALSO_LISTED] as $ip) {
            $body = $this->call($ip)->body;
            $this->assertSame('Active', $body['status'], $ip);
            $this->assertSame($ip, $body['validip'], $ip);
            $this->assertSame($ip, LocalKey::decode($body['localkey'], self::SECRET)['validip'], $ip);
        }
    }

    public function test_the_local_key_does_not_grow_with_the_list()
    {
        $this->bulk([self::LISTED]);
        $one = $this->call(self::LISTED)->body['localkey'];

        $many = [];
        for ($i = 1; $i <= 250; $i++) {
            $many[] = '10.0.' . intdiv($i, 256) . '.' . ($i % 256);
        }
        $many[] = self::LISTED;
        $this->bulk($many);

        $this->assertSame($one, $this->call(self::LISTED)->body['localkey']);
    }

    public function test_an_ip_off_the_list_is_refused_like_a_foreign_ip_and_nothing_is_bound()
    {
        $this->bulk([self::LISTED]);

        $response = $this->call(self::FOREIGN);

        $this->assertSame(self::IP_INVALID, $response->json());
        $this->assertSame(['IP Address Invalid'], $this->messages());
        $stored = $this->repo->licenses[self::KEY];
        $this->assertSame([self::LISTED], $stored->ipList);
        $this->assertSame('', $stored->validIp);
        $this->assertSame('2026-10-08 12:00:00', $stored->lastAccess, 'a refused check still counts as access');
    }

    public static function provideNeverBinds()
    {
        return [
            'empty list, Active' => [License::ACTIVE],
            'empty list, Reissued' => [License::REISSUED],
        ];
    }

    #[DataProvider('provideNeverBinds')]
    public function test_an_empty_list_binds_nobody($status)
    {
        $this->bulk([], $status);
        $this->repo->beforeBind = function () {
            $this->fail('a bulk license never binds');
        };

        $response = $this->call(self::FOREIGN);

        $this->assertSame(self::IP_INVALID, $response->json());
        $stored = $this->repo->licenses[self::KEY];
        $this->assertSame([], $stored->ipList);
        $this->assertSame('', $stored->validIp);
        $this->assertSame($status, $stored->status);
    }

    public function test_allow_ip_conflict_does_not_open_a_bulk_license()
    {
        $license = $this->bulk([self::LISTED]);
        $license->allowIpConflict = true;
        $this->repo->add($license);

        $this->assertSame(self::IP_INVALID, $this->call(self::FOREIGN)->json());
    }

    public function test_bindings_left_in_its_own_columns_are_neither_accepted_nor_reported()
    {
        $license = $this->bulk([self::LISTED]);
        $license->validIp = self::FOREIGN;
        $license->validDomain = 'old.example.net';
        $this->repo->add($license);

        $this->assertSame(self::IP_INVALID, $this->call(self::FOREIGN)->json());
        $this->assertSame($this->expectedActive($license, self::LISTED), $this->call(self::LISTED)->json());
    }

    public function test_an_ipv6_address_matches_however_it_is_written()
    {
        $this->bulk(['2001:db8::1']);

        $body = $this->call('2001:0DB8:0000:0000:0000:0000:0000:0001')->body;

        $this->assertSame('Active', $body['status']);
        $this->assertSame('2001:0DB8:0000:0000:0000:0000:0000:0001', $body['validip'], 'the caller as it came');
        $this->assertSame(self::IP_INVALID, $this->call('2001:db8::2')->json());
    }

    public function test_something_that_is_not_an_ip_never_matches_a_listed_one()
    {
        $this->bulk([self::LISTED, '']);

        $this->assertSame(self::IP_INVALID, $this->call('')->json());
        $this->assertSame(self::IP_INVALID, $this->call(' ' . self::LISTED)->json());
    }

    public function test_a_wrong_psk_from_a_listed_ip_is_rejected_like_zlic()
    {
        $license = $this->bulk([self::LISTED]);

        $response = $this->call(self::LISTED, 'OtherProductSecret');

        $expected = ['status' => 'Invalid'] + $license->serviceTags
            + ['validip' => self::LISTED, 'validdirectory' => self::DIR, 'md5hash' => md5(self::SECRET . self::TOKEN),
                'description' => 'MD5 Checksum Verification Failed'];
        $this->assertSame(200, $response->httpStatus);
        $this->assertSame(json_encode($expected), $response->json());
        $this->assertSame(['MD5 Checksum Verification Failed'], $this->messages());
    }

    public function test_an_ip_off_the_list_is_reported_before_a_wrong_psk()
    {
        $this->bulk([self::LISTED]);

        $this->assertSame(self::IP_INVALID, $this->call(self::FOREIGN, 'OtherProductSecret')->json());
        $this->assertSame(['IP Address Invalid'], $this->messages());
    }

    public static function provideInactive()
    {
        return [[License::EXPIRED, 'License Expired'], [License::SUSPENDED, 'License Suspended']];
    }

    #[DataProvider('provideInactive')]
    public function test_suspended_or_expired_answers_as_a_single_license_without_its_list($status, $logMessage)
    {
        $license = $this->bulk([self::LISTED, self::ALSO_LISTED], $status);
        $license->validIp = self::FOREIGN;
        $this->repo->add($license);

        // From anywhere: the status wins over the IP check, as for a single license.
        $response = $this->call(self::FOREIGN);

        $results = ['status' => $status] + $license->serviceTags + ['md5hash' => md5(self::SECRET . self::TOKEN)];
        $this->assertSame(json_encode(ZlicReference::activeResults($results, self::SECRET, '20261008')), $response->json());
        $this->assertArrayNotHasKey('validip', $response->body);
        $this->assertArrayNotHasKey('localkey', $response->body);
        $this->assertSame([$logMessage], $this->messages());
    }
}
