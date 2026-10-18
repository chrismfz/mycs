<?php

namespace MyCS\GrEpp\Tests;

use MyCS\GrEpp\Whois\WhoisRecord;
use PHPUnit\Framework\TestCase;

/** The registry's WHOIS API answer (live, 2026-10-18, anonymised): its dates as «dd-mm-yyyy», the spec's as Y-m-d. */
final class WhoisRecordTest extends TestCase
{
    public function test_a_live_answer(): void
    {
        $record = WhoisRecord::fromJson((string) file_get_contents(__DIR__ . '/fixtures/whois/domain-response.json'));

        $this->assertNotNull($record);
        $this->assertSame(['example1.gr', '1234567', ['ok'], ['ns1.example.com', 'ns2.example.com', 'ns3.example.com'], false],
            [$record->name, $record->protocol, $record->statuses, $record->nameservers, $record->dnssec]);
        $this->assertSame(['2005-03-01', '2026-10-18', '2029-03-01'], [$record->createdOn, $record->updatedOn, $record->expiresOn]);
        $this->assertSame(['MYIP NETWORKS', 'https://myip.gr'], [$record->registrar, $record->registrarUrl]);
    }

    public function test_what_is_not_a_record_and_its_dates(): void
    {
        $this->assertNull(WhoisRecord::fromJson((string) file_get_contents(__DIR__ . '/fixtures/whois/not-found-response.json')));
        $this->assertNull(WhoisRecord::fromJson('not json'));
        $this->assertNull(WhoisRecord::fromJson('[]'));
        $this->assertSame('2019-08-24', WhoisRecord::date('2019-08-24'));
        $this->assertSame('2004-08-17', WhoisRecord::date('17-08-2004'));
        $this->assertNull(WhoisRecord::date('31-02-2026'));
        $this->assertNull(WhoisRecord::date('08/17/2004'));
    }
}
