<?php

namespace Nixpal\Licensing\Tests;

use Nixpal\Licensing\License;
use Nixpal\Licensing\Zlic;
use PHPUnit\Framework\TestCase;

/**
 * Adversarial review of #819 (bulk license in zlic).
 */
final class ZlicBulkAdversarialTest extends TestCase
{
    const SECRET = 'TestProductSecret';

    const TOKEN = '1791460800deadbeefdeadbeefdeadbeefdeadbeef';

    const NOW = 1791460800;

    const KEY = 'ZCAgent-00000000cccccccc';

    const LISTED = '198.51.100.7';

    const FOREIGN = '203.0.113.9';

    const IP_INVALID = '{"status":"Invalid","message":"IP Address Invalid","remotecheck":true}';

    /** @var InMemoryRepository */
    private $repo;

    /** @var Zlic */
    private $zlic;

    protected function setUp(): void
    {
        date_default_timezone_set('UTC');
        $this->repo = new InMemoryRepository;
        $this->zlic = new Zlic(
            $this->repo,
            fn ($ip) => 'host.example.net',
            fn () => self::NOW,
            fn () => self::TOKEN
        );
    }

    private function license()
    {
        $license = new License;
        $license->id = 91;
        $license->licenseKey = self::KEY;
        $license->status = License::ACTIVE;
        $license->secret = self::SECRET;
        $license->serviceTags = ['registeredname' => 'Test', 'nextduedate' => '2026-12-01'];

        return $license;
    }

    /**
     * ADV-819-1: the re-read after a failed bind ignores the bulk branch.
     *
     * The first read sees an unbound single license, so a caller with the right PSK tries to bind it. Before the bind
     * lands the license turns into a bulk one (staff switch it to bulk, or §8 merges the key into a bulk service), so the
     * guarded bind fails and Zlic re-reads it. The re-read license is bulk, but handle() goes on with the single rules:
     * it checks the caller against validIp (a binding the bulk license keeps from before, which the bulk branch
     * deliberately ignores) instead of ipList, and answers Active, with that column as validip, to an IP that is not on
     * the list. The bulk branch answers IP Address Invalid for the same stored license.
     */
    public function test_adv_819_1_a_license_that_became_bulk_before_the_bind_is_checked_against_its_list()
    {
        $this->repo->add($this->license()); // single, unbound
        $this->repo->beforeBind = function (InMemoryRepository $repo) {
            $bulk = $this->license();
            $bulk->ipList = [self::LISTED];
            $bulk->validIp = self::FOREIGN; // left in its own column from its single days
            $repo->licenses[self::KEY] = $bulk;
        };

        $response = $this->zlic->handle(['license' => self::KEY, 'license_PSK' => self::SECRET], self::FOREIGN);

        $this->assertSame(self::IP_INVALID, $response->json());
        $this->assertSame(self::FOREIGN, $this->repo->licenses[self::KEY]->validIp);
    }

    /**
     * ADV-819-1 (b): same race, the bulk license carries "Allow IP Conflict" (the bulk branch ignores it on purpose).
     * The re-read honours it and opens the license to any caller.
     */
    public function test_adv_819_1b_allow_ip_conflict_on_a_re_read_bulk_license_does_not_open_it()
    {
        $this->repo->add($this->license());
        $this->repo->beforeBind = function (InMemoryRepository $repo) {
            $bulk = $this->license();
            $bulk->ipList = [self::LISTED];
            $bulk->validIp = self::LISTED;
            $bulk->allowIpConflict = true;
            $repo->licenses[self::KEY] = $bulk;
        };

        $response = $this->zlic->handle(['license' => self::KEY, 'license_PSK' => self::SECRET], self::FOREIGN);

        $this->assertSame(self::IP_INVALID, $response->json());
    }
}
