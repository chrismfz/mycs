<?php

namespace MyCS\Proxmox\Tests;

use InvalidArgumentException;
use MyCS\Proxmox\IpRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Documentation ranges only (RFC 5737, RFC 3849). */
class IpRangeTest extends TestCase
{
    public function test_a_network_lists_its_hosts_without_network_broadcast_and_gateway(): void
    {
        $hosts = IpRange::hosts('192.0.2.0/29', '192.0.2.1');

        $this->assertSame(['192.0.2.2', '192.0.2.3', '192.0.2.4', '192.0.2.5', '192.0.2.6'], $hosts);
    }

    public function test_a_31_and_a_32_keep_every_address(): void
    {
        $this->assertSame(['192.0.2.4', '192.0.2.5'], IpRange::hosts('192.0.2.4/31'));
        $this->assertSame(['192.0.2.9'], IpRange::hosts('192.0.2.9/32'));
    }

    public function test_a_range_is_inclusive_and_skips_the_gateway(): void
    {
        $this->assertSame(['198.51.100.10', '198.51.100.12'], IpRange::range('198.51.100.10', '198.51.100.12', '198.51.100.11'));
    }

    public function test_too_many_at_once_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IpRange::hosts('10.0.0.0/8');
    }

    public function test_a_range_backwards_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IpRange::range('198.51.100.12', '198.51.100.10');
    }

    /** @return array<string, array{0: string}> */
    public static function notNetworks(): array
    {
        return ['host bits' => ['192.0.2.5/24'], 'no length' => ['192.0.2.0'], 'too long' => ['192.0.2.0/33'],
            'v6 host bits' => ['2001:db8::1/64'], 'text' => ['pool/24']];
    }

    #[DataProvider('notNetworks')]
    public function test_what_is_not_a_network_is_refused(string $cidr): void
    {
        $this->expectException(InvalidArgumentException::class);
        IpRange::network($cidr);
    }

    public function test_masks_both_ways(): void
    {
        $this->assertSame(24, IpRange::prefix('255.255.255.0'));
        $this->assertSame(27, IpRange::prefix('255.255.255.224'));
        $this->assertSame(64, IpRange::prefix('/64'));
        $this->assertSame('255.255.255.240', IpRange::netmask(28));
        $this->assertSame('0.0.0.0', IpRange::netmask(0));
        $this->expectException(InvalidArgumentException::class);
        IpRange::prefix('255.0.255.0');
    }

    public function test_contains_by_family(): void
    {
        $this->assertTrue(IpRange::contains('192.0.2.0/24', '192.0.2.200'));
        $this->assertFalse(IpRange::contains('192.0.2.0/24', '198.51.100.1'));
        $this->assertTrue(IpRange::contains('2001:db8::/32', '2001:db8:1::/64'));
        $this->assertFalse(IpRange::contains('2001:db8::/32', '192.0.2.1'));
        $this->assertFalse(IpRange::contains('192.0.2.0/24', 'nonsense'));
    }

    public function test_normalize(): void
    {
        $this->assertSame('2001:db8::1', IpRange::normalize(' 2001:DB8:0:0::1 '));
        $this->assertSame('2001:db8:5::/64', IpRange::normalize('2001:db8:5:0::/64'));
        $this->expectException(InvalidArgumentException::class);
        IpRange::normalize('1');
    }

    public function test_the_network_of_an_address(): void
    {
        $this->assertSame('192.0.2.64/27', IpRange::networkOf('192.0.2.77', 27));
        $this->assertSame('2001:db8:5::/48', IpRange::networkOf('2001:db8:5:9::1', 48));
    }

    public function test_a_prefix_wider_than_the_network_is_not_in_it_and_overlaps_are_seen(): void
    {
        $this->assertFalse(IpRange::contains('2001:db8::/48', '2001:db8::/32'));
        $this->assertTrue(IpRange::contains('2001:db8::/48', '2001:db8:0:5::/64'));
        $this->assertTrue(IpRange::overlaps('2001:db8::/56', '2001:db8:0:5::/64'));
        $this->assertFalse(IpRange::overlaps('2001:db8:0:1::/64', '2001:db8:0:2::/64'));
        $this->assertSame(['192.0.2.64', '192.0.2.95'], IpRange::bounds('192.0.2.64/27'));
    }
}
