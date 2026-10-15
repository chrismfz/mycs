<?php

namespace MyCS\Vies\Tests;

use MyCS\Vies\Transport;
use MyCS\Vies\VatCountry;
use MyCS\Vies\VatNumber;
use MyCS\Vies\Vies;
use MyCS\Vies\ViesInvalidFormat;
use MyCS\Vies\ViesUnavailable;
use PHPUnit\Framework\TestCase;

class ViesTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    /** @param array{status: int, body: string}|\Throwable $answer */
    private function vies(array|\Throwable $answer, ?string $requester = null): Vies
    {
        $transport = new class($answer, $this->sent) implements Transport
        {
            /** @param list<array<string, mixed>> $sent */
            public function __construct(private array|\Throwable $answer, private array &$sent) {}

            public function postJson(string $url, array $body): array
            {
                $this->sent[] = $body;
                if ($this->answer instanceof \Throwable) {
                    throw $this->answer;
                }

                return $this->answer;
            }
        };

        return new Vies($transport, $requester);
    }

    /** @param array<string, mixed> $body */
    private static function ok(array $body): array
    {
        return ['status' => 200, 'body' => json_encode($body)];
    }

    public function test_a_valid_number_with_its_identity_and_consultation_number(): void
    {
        $result = $this->vies(self::ok(['countryCode' => 'AT', 'vatNumber' => 'U18522105', 'valid' => true, 'name' => 'Example GmbH',
            'address' => 'Street 1', 'requestDate' => '2026-10-15T10:00:00.000Z', 'requestIdentifier' => 'WAPIAAAAZ1']), 'EE100000000')
            ->check('ATU 185.221-05');

        $this->assertTrue($result->valid);
        $this->assertSame(['AT', 'U18522105', 'ATU18522105'], [$result->vat->country, $result->vat->number, $result->vat->full()]);
        $this->assertSame(['Example GmbH', 'Street 1', 'WAPIAAAAZ1'], [$result->name, $result->address, $result->requestIdentifier]);
        $this->assertSame([['countryCode' => 'AT', 'vatNumber' => 'U18522105', 'requesterMemberStateCode' => 'EE', 'requesterNumber' => '100000000']], $this->sent);
    }

    public function test_a_withheld_identity_is_null(): void
    {
        $result = $this->vies(self::ok(['valid' => true, 'name' => '---', 'address' => '---']))->check('DE129273398');

        $this->assertTrue($result->valid);
        $this->assertNull($result->name);
        $this->assertNull($result->address);
        $this->assertNull($result->requestIdentifier);
    }

    public function test_an_invalid_number(): void
    {
        $this->assertFalse($this->vies(self::ok(['valid' => false]))->check('AT00000000')->valid);
    }

    public function test_greece_is_el_to_vies_and_gr_everywhere_else(): void
    {
        $result = $this->vies(self::ok(['valid' => true]))->check('GR094014201');

        $this->assertSame('EL', $this->sent[0]['countryCode']);
        $this->assertSame(['GR', 'EL094014201'], [$result->vat->country, $result->vat->full()]);
        $this->assertSame('GR', VatNumber::parse('EL094014201')->country);
        $this->assertSame('GR', VatNumber::parse('094014201', 'GR')->country, 'no prefix: the customer\'s country');
        $this->assertSame(['EL', 'GR', null], [VatCountry::prefixOf('GR'), VatCountry::isoOf('EL'), VatCountry::isoOf('GB')]);
    }

    /** VIES down, or its own error in a 200: never an answer about the number (fail closed). */
    public function test_every_failure_is_unavailable(): void
    {
        foreach ([
            new ViesUnavailable('unreachable'),
            ['status' => 503, 'body' => ''],
            ['status' => 200, 'body' => '<html>'],
            self::ok(['errorWrappers' => [['error' => 'MS_UNAVAILABLE']]]),
            self::ok(['valid' => 'true']),
            self::ok(['countryCode' => 'AT']),
        ] as $i => $answer) {
            try {
                $this->vies($answer)->check('ATU18522105');
                $this->fail("case {$i} answered");
            } catch (ViesUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_number_that_is_not_an_eu_one_is_not_sent(): void
    {
        foreach (['', 'GB123456789', 'CHE123456789', '123456789', 'AT', 'ATU1234567890123456'] as $vat) {
            try {
                $this->vies(self::ok(['valid' => true]))->check($vat);
                $this->fail("{$vat} was sent");
            } catch (ViesInvalidFormat) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], $this->sent);
    }

    /** Our own number rejected (a wrong setting): asked again without it, and the result says so (adversarial ADV-6). */
    public function test_a_rejected_requester_is_left_out_and_flagged(): void
    {
        $transport = new class implements Transport
        {
            /** @var list<array<string, mixed>> */
            public array $sent = [];

            public function postJson(string $url, array $body): array
            {
                $this->sent[] = $body;

                return isset($body['requesterNumber'])
                    ? ['status' => 200, 'body' => json_encode(['errorWrappers' => [['error' => 'INVALID_REQUESTER_INFO']]])]
                    : ['status' => 200, 'body' => json_encode(['valid' => true, 'requestIdentifier' => ['not', 'text']])];
            }
        };

        $result = (new Vies($transport, 'EE100000000'))->check('DE129273398');

        $this->assertTrue($result->valid);
        $this->assertTrue($result->requesterRejected);
        $this->assertNull($result->requestIdentifier, 'a field of another shape is no value');
        $this->assertCount(2, $transport->sent);
    }

    public function test_an_unusable_requester_is_left_out(): void
    {
        $this->vies(self::ok(['valid' => true]), 'not a vat')->check('DE129273398');

        $this->assertArrayNotHasKey('requesterNumber', $this->sent[0]);
    }
}
