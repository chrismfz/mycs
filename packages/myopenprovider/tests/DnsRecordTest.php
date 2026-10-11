<?php

namespace Nixpal\MyOpenprovider\Tests;

use Nixpal\MyOpenprovider\DnsRecord;
use PHPUnit\Framework\TestCase;

final class DnsRecordTest extends TestCase
{
    private static function reason(array $input)
    {
        try {
            DnsRecord::fromInput($input, 'example.com');
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function test_valid_records_are_normalised()
    {
        $a = DnsRecord::fromInput(['type' => 'a', 'name' => 'WWW', 'value' => '192.0.2.10', 'ttl' => '3600'], 'example.com');
        $this->assertSame(['name' => 'www', 'type' => 'A', 'value' => '192.0.2.10', 'ttl' => 3600], $a->toArray());

        // "@", the zone itself or a full name: relative to the zone.
        $this->assertSame('', DnsRecord::fromInput(['type' => 'TXT', 'name' => '@', 'value' => 'v=spf1 -all'], 'example.com')->name);
        $this->assertSame('', DnsRecord::fromInput(['type' => 'TXT', 'name' => 'example.com.', 'value' => 'x'], 'example.com')->name);
        $this->assertSame('mail', DnsRecord::fromInput(['type' => 'A', 'name' => 'mail.example.com', 'value' => '192.0.2.1'], 'example.com')->name);

        $mx = DnsRecord::fromInput(['type' => 'MX', 'name' => '', 'value' => 'Mail.Example.net.', 'ttl' => 900, 'prio' => '10'], 'example.com');
        $this->assertSame(['name' => '', 'type' => 'MX', 'value' => 'mail.example.net', 'ttl' => 900, 'prio' => 10], $mx->toArray());
        $this->assertSame('*', DnsRecord::fromInput(['type' => 'A', 'name' => '*', 'value' => '192.0.2.1'], 'example.com')->name);
        $this->assertSame('_sip._tcp', DnsRecord::fromInput(['type' => 'SRV', 'name' => '_sip._tcp', 'value' => '10 5060 sip.example.com', 'prio' => 0], 'example.com')->name);
    }

    public function test_invalid_input_is_refused_with_a_reason()
    {
        $this->assertSame('type', self::reason(['type' => 'SOA', 'value' => 'x']));
        $this->assertSame('name', self::reason(['type' => 'NS', 'name' => '@', 'value' => 'ns.example.net'])); // the zone's own NS set
        $this->assertNull(self::reason(['type' => 'NS', 'name' => 'sub', 'value' => 'ns.example.net']));     // a delegation is fine
        $this->assertSame('type', self::reason(['type' => 'ANY', 'value' => 'x']));
        $this->assertSame('name', self::reason(['type' => 'A', 'name' => 'bad name', 'value' => '192.0.2.1']));
        $this->assertSame('name', self::reason(['type' => 'A', 'name' => '-x', 'value' => '192.0.2.1']));
        $this->assertSame('name', self::reason(['type' => 'CNAME', 'name' => '@', 'value' => 'target.example.net'])); // apex
        $this->assertSame('value', self::reason(['type' => 'A', 'name' => 'x', 'value' => '2001:db8::1']));
        $this->assertSame('value', self::reason(['type' => 'AAAA', 'name' => 'x', 'value' => '192.0.2.1']));
        $this->assertSame('value', self::reason(['type' => 'CNAME', 'name' => 'x', 'value' => 'not a host']));
        $this->assertSame('value', self::reason(['type' => 'TXT', 'name' => 'x', 'value' => "line\nbreak"]));
        $this->assertSame('value', self::reason(['type' => 'TXT', 'name' => 'x', 'value' => '']));
        $this->assertSame('ttl', self::reason(['type' => 'A', 'name' => 'x', 'value' => '192.0.2.1', 'ttl' => 60]));
        $this->assertSame('prio', self::reason(['type' => 'MX', 'name' => '', 'value' => 'mx.example.net', 'prio' => 'high']));
        $this->assertSame('prio', self::reason(['type' => 'MX', 'name' => '', 'value' => 'mx.example.net']));
    }

    public function test_records_from_openprovider_and_what_may_be_removed()
    {
        $apexNs = DnsRecord::fromOpenprovider(['name' => 'example.com', 'type' => 'NS', 'value' => 'ns1.openprovider.nl', 'ttl' => 86400], 'example.com');
        $this->assertSame('', $apexNs->name);
        $this->assertFalse($apexNs->removable()); // the zone's own NS set
        $this->assertFalse(DnsRecord::fromOpenprovider(['name' => '', 'type' => 'SOA', 'value' => 'x', 'ttl' => 86400], 'example.com')->removable());

        $www = DnsRecord::fromOpenprovider(['name' => 'www.example.com', 'type' => 'A', 'value' => '192.0.2.10', 'ttl' => 3600], 'example.com');
        $this->assertTrue($www->removable());
        $this->assertSame(['name' => 'www', 'type' => 'A', 'value' => '192.0.2.10', 'ttl' => 3600], $www->toArray());
        $this->assertSame($www->key(), DnsRecord::fromInput(['type' => 'A', 'name' => 'www', 'value' => '192.0.2.10'], 'example.com')->key());
    }
}
