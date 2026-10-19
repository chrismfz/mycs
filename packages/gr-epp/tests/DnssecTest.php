<?php

namespace MyCS\GrEpp\Tests;

use InvalidArgumentException;
use MyCS\GrEpp\Command\DomainInfo;
use MyCS\GrEpp\Command\DomainSecDns;
use MyCS\GrEpp\Data\DsRecord;
use MyCS\GrEpp\OutcomeUnknown;
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
        // Each for its own reason (review 6 of #209: a rule refusing everything would pass a bare «it threw»).
        foreach ([
            'without changes' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1'),
            'algorithm 15' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds(algorithm: 15)]),
            'digest type 5' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds(digestType: 5)]),
            'is 96 hex digits' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds(digestType: 4)]),
            'is 64 hex digits' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds(digest: str_repeat('G', 64))]),
            'not 65536' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds(65536)]),
            'not -1' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds(-1)]),
            'At most 16' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1', add: array_map(fn (int $tag) => self::ds($tag), range(1, 17))),
            'twice' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds(), self::ds(digest: strtolower(self::SHA256))]),
            'twice ' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds(), self::ds(digest: self::SHA256 . "\n")]),
            'both added and removed' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds()], remove: [self::ds()]),
            'control character' => fn () => DomainSecDns::request("cdnet.gr\0.mine.gr", 'mycs-1', add: [self::ds()]),
            '0 to 255' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1', remove: [self::ds(algorithm: 256)]),
            'is hex digits' => fn () => DomainSecDns::request('cdnet.gr', 'mycs-1', remove: [self::ds(algorithm: 16, digestType: 9, digest: 'XYZ')]),
        ] as $why => $call) {
            try {
                $call();
                $this->fail("sent: {$why}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString(trim($why), $e->getMessage());
            }
        }
    }

    public function test_what_it_takes_at_its_edges(): void
    {
        // Its limits taken: key tags 0 and 65535, sixteen added or removed, a digest as `dig` prints it (review 1, 2, 6).
        QueriesTest::assertValid(DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds(0), self::ds(65535)]));
        QueriesTest::assertValid(DomainSecDns::request('cdnet.gr', 'mycs-1', add: array_map(fn (int $tag) => self::ds($tag), range(1, 16))));
        QueriesTest::assertValid(DomainSecDns::request('cdnet.gr', 'mycs-1', remove: array_map(fn (int $tag) => self::ds($tag), range(1, 16))));
        $xml = DomainSecDns::request('cdnet.gr', 'mycs-1', add: [self::ds(digest: implode(' ', str_split(strtolower(self::SHA256), 32)) . "\n")]);
        $this->assertStringContainsString('<secDNS:digest>' . self::SHA256 . '</secDNS:digest>', $xml, 'its spaces and newline dropped');
        // One the registry no longer lists, or another registrar's (Ed25519, 15): still removed by itself (review 4).
        QueriesTest::assertValid(DomainSecDns::request('cdnet.gr', 'mycs-1', remove: [self::ds(algorithm: 15)]));
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

    public function test_a_ds_record_the_registry_gives_oddly_is_not_read_as_zeros(): void
    {
        // Review 3 of #209: an empty key tag is not key tag 0.
        $answer = '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>ok</msg></result>'
            . '<resData><domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>cdnet.gr</domain:name><domain:roid>d1-gr</domain:roid>'
            . '<domain:clID>myip</domain:clID></domain:infData></resData><extension><secDNS:infData xmlns:secDNS="urn:ietf:params:xml:ns:secDNS-1.1">'
            . '<secDNS:dsData><secDNS:keyTag></secDNS:keyTag><secDNS:alg>13</secDNS:alg><secDNS:digestType>2</secDNS:digestType>'
            . '<secDNS:digest>' . self::SHA256 . '</secDNS:digest></secDNS:dsData></secDNS:infData></extension>'
            . '<trID><clTRID>mycs-1</clTRID><svTRID>s</svTRID></trID></response></epp>';

        $this->expectException(OutcomeUnknown::class);
        DomainInfo::parse(Response::parse($answer), 'cdnet.gr');
    }
}
