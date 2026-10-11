<?php

namespace Nixpal\MyOpenprovider\Tests;

use Nixpal\MyOpenprovider\GlueHost;
use PHPUnit\Framework\TestCase;

final class GlueHostTest extends TestCase
{
    private static function reason($name, array $ips)
    {
        try {
            GlueHost::fromInput($name, $ips, 'example.com');
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function test_hosts_are_normalised_under_the_domain()
    {
        $this->assertSame(['name' => 'ns1.example.com', 'ip' => '192.0.2.1'], GlueHost::fromInput('NS1', ['192.0.2.1'], 'Example.com')->toArray());
        $this->assertSame(['name' => 'ns2.example.com', 'ip' => '192.0.2.2', 'ip6' => '2001:db8::2'],
            GlueHost::fromInput('ns2.example.com.', ['', '2001:DB8::2', '192.0.2.2', '192.0.2.9'], 'example.com')->toArray());
        $this->assertSame('a.b.example.com', GlueHost::fromInput('a.b.example.com', ['2001:db8::1'], 'example.com')->name);
    }

    public function test_invalid_hosts_are_refused_with_a_reason()
    {
        $this->assertSame('name', self::reason('', ['192.0.2.1']));
        $this->assertSame('name', self::reason('example.com', ['192.0.2.1']));          // the domain itself
        $this->assertSame('name', self::reason('ns1.example.net', ['192.0.2.1']));      // another domain
        $this->assertSame('name', self::reason('ns1.badexample.com', ['192.0.2.1']));   // suffix, not a subdomain
        $this->assertSame('name', self::reason('-ns.example.com', ['192.0.2.1']));
        $this->assertSame('ip', self::reason('ns1', []));
        $this->assertSame('ip', self::reason('ns1', ['', ' ']));
        $this->assertSame('ip', self::reason('ns1', ['300.1.1.1']));
        $this->assertSame('ip', self::reason('ns1', ['10.0.0.1']));                     // private
        $this->assertSame('ip', self::reason('ns1', ['127.0.0.1']));
        $this->assertSame('ip', self::reason('ns1', ['169.254.1.1']));
        $this->assertSame('ip', self::reason('ns1', ['224.0.0.1']));
        $this->assertSame('ip', self::reason('ns1', ['::1']));
        $this->assertSame('ip', self::reason('ns1', ['fe80::1']));
        $this->assertSame('ip', self::reason('ns1', ['fd00::1']));
    }
}
