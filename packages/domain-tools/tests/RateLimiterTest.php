<?php

namespace Nixpal\DomainTools\Tests;

use Nixpal\DomainTools\RateLimiter;
use Nixpal\DomainTools\RateLimitStore;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    public function test_sliding_window()
    {
        $store = new class implements RateLimitStore
        {
            public $hits = [];

            public function count($key, $since)
            {
                return count(array_filter($this->hits[$key] ?? [], function ($t) use ($since) {
                    return $t >= $since;
                }));
            }

            public function add($key, $time)
            {
                $this->hits[$key][] = $time;
            }
        };
        $limiter = new RateLimiter($store);

        $this->assertTrue($limiter->attempt('ip1|check', 2, 600, 1000));
        $this->assertTrue($limiter->attempt('ip1|check', 2, 600, 1100));
        $this->assertFalse($limiter->attempt('ip1|check', 2, 600, 1200));   // third within 10 minutes
        $this->assertCount(2, $store->hits['ip1|check']);                   // a refused hit is not recorded
        $this->assertTrue($limiter->attempt('ip2|check', 2, 600, 1200));    // per key
        $this->assertTrue($limiter->attempt('ip1|check', 2, 600, 1601));    // the first hit left the window
        $this->assertTrue($limiter->attempt('ip1|whois', 0, 600, 1601));    // 0 = no limit
    }
}
