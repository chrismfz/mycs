<?php

namespace MyCS\GrEpp\Tests;

use InvalidArgumentException;
use MyCS\GrEpp\Command\DomainInfo;
use MyCS\GrEpp\Command\DomainSecDns;
use MyCS\GrEpp\Data\DsRecord;
use MyCS\GrEpp\Response;
use PHPUnit\Framework\TestCase;

/**
 * G8-4a: a domain's DS records (secDNS-1.1's DS Data Interface, the only one the .gr registry takes): read from
 * domain:info, removed (some or all) then added by domain:update; its rules checked before sending (algorithms 3–14 as
 * it lists them, digest types 1–4 at their lengths, key tags 0–65535, at most 16). The answers here are ours, not the
 * registry's guide's.
 */
final class DnssecTest extends TestCase
{
    private const SHA256 = '2BB183AF5F22588179A53B0A98631FAD1A292118F8D0CFE5F1C2C2F7C1D3A9B4';

    private static function ds(int $keyTag = 2371, int $algorithm = 13, int $digestType = 2, string $digest = self::SHA256): DsRecord
    {
        return new DsRecord($keyTag, $algorithm, $digestType, $digest);
    }

    public function test_changes_are_valid_to_the_schemas_removed_before_added(): void
    {
        $sha1 = str_repeat('a1', 20);
        foreach ([
            DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds(), self::ds(2372, 8, 1, $sha1)]),
            DomainSecDns::request('cdnet.gr', 'mycs-1', remove: [self::ds()]),
            DomainSecDns::request('cdnet.gr', 'mycs-1', remove: true),
            DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds(2372)], remove: [self::ds()]),
        ] as $xml) {
            QueriesTest::assertValid($xml);
        }

        $xml = DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds(2372, 8, 1, $sha1)], remove: true);
        $this->assertLessThan(strpos($xml, 'secDNS:add'), strpos($xml, 'secDNS:rem'), 'rem first (RFC 5910 §5.2.5)');
        $this->assertStringContainsString('<secDNS:all>true</secDNS:all>', $xml);
        $this->assertStringContainsString('<secDNS:digest>' . strtoupper($sha1) . '</secDNS:digest>', $xml, 'upper-cased');
        $this->assertStringNotContainsString('keyData', $xml);
        $this->assertStringNotContainsString('maxSigLife', $xml);
        $this->assertStringNotContainsString('urgent', $xml);
    }

    public function test_what_the_registry_refuses_is_never_sent(): void
    {
        foreach ([
            'nothing to change' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1'),
            'an algorithm it does not take' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds(algorithm: 15)]),
            'a digest type it does not take' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds(digestType: 5)]),
            'a digest of the wrong length' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds(digestType: 4)]),
            'a digest not hex' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds(digest: str_repeat('G', 64))]),
            'a key tag out of range' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds(65536)]),
            'a negative key tag' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds(-1)]),
            'seventeen' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1', add: array_map(fn (int $tag) => self::ds($tag), range(1, 17))),
            'the same twice' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds(), self::ds(digest: strtolower(self::SHA256))]),
            'added and removed' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds()], remove: [self::ds()]),
            'a control character' => fn () => DomainSecDns::request("cdnet.gr\0.mine.gr", 'mycs-1', add: [self::ds()]),
        ] as $why => $call) {
            try {
                $call();
                $this->fail("sent: {$why}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        QueriesTest::assertValid(DomainSecDns::request('cdnet.gr', 'mycs-1', add: array_map(fn (int $tag) => self::ds($tag), range(1, 16))), 'sixteen: its limit');
    }

    public function test_its_ds_records_are_read(): void
    {
        $answer = '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>ok</msg></result>'
            . '<resData><domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>cdnet.gr</domain:name><domain:roid>d1-gr</domain:roid>'
            . '<domain:status s="serverUpdateProhibited"/><domain:clID>myip</domain:clID></domain:infData></resData><extension>'
            . '<secDNS:infData xmlns:secDNS="urn:ietf:params:xml:ns:secDNS-1.1"><secDNS:dsData><secDNS:keyTag>2371</secDNS:keyTag><secDNS:alg>13</secDNS:alg>'
            . '<secDNS:digestType>2</secDNS:digestType><secDNS:digest>' . strtolower(self::SHA256) . '</secDNS:digest></secDNS:dsData></secDNS:infData>'
            . '<extSecDNS:lastValidationResult xmlns:extSecDNS="urn:ics-forth:params:xml:ns:extSecDNS-1.0"><extSecDNS:wasSuccessful>true</extSecDNS:wasSuccessful>'
            . '<extSecDNS:validationOutcome>ok</extSecDNS:validationOutcome><extSecDNS:validationDate>2026-10-19T10:00:00Z</extSecDNS:validationDate></extSecDNS:lastValidationResult>'
            . '</extension><trID><clTRID>mycs-1</clTRID><svTRID>s</svTRID></trID></response></epp>';

        $info = DomainInfo::parse(Response::parse($answer), 'cdnet.gr');

        $this->assertCount(1, $info->dsRecords);
        $this->assertTrue($info->dsRecords[0]->same(self::ds()));
        $this->assertSame(self::SHA256, $info->dsRecords[0]->digest);
        $this->assertTrue($info->dsValidation?->successful);
    }

    public function test_none_when_unsigned(): void
    {
        $info = DomainInfo::parse(Response::parse((string) file_get_contents(__DIR__ . '/fixtures/v4.3/36-domain-info-response.xml')));
        $this->assertSame([], $info->dsRecords);
    }
}
