<?php

namespace MyCS\EuVatRates\Tests;

use MyCS\EuVatRates\Rate;
use MyCS\EuVatRates\StandardRates;
use MyCS\EuVatRates\Tedb;
use MyCS\EuVatRates\TedbUnavailable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TedbTest extends TestCase
{
    private function answer(): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/tedb-answer.xml');
    }

    public function test_the_request_asks_every_state_on_the_day_with_greece_as_el(): void
    {
        $xml = Tedb::request(new \DateTimeImmutable('2026-10-29'));

        $this->assertStringContainsString('<t:situationOn>2026-10-29</t:situationOn>', $xml);
        $this->assertStringContainsString('<t:isoCode>EL</t:isoCode>', $xml);
        $this->assertStringNotContainsString('<t:isoCode>GR</t:isoCode>', $xml);
        $this->assertSame(27, substr_count($xml, '<t:isoCode>'));
        $this->assertNotFalse((new \DOMDocument)->loadXML($xml));
    }

    public function test_the_answer_reads_into_each_states_standard_rate(): void
    {
        $rates = Tedb::parse($this->answer());

        $this->assertSame(StandardRates::EU, array_keys($rates)); // GR sorts between FR and HR
        $this->assertCount(27, $rates);
        $this->assertSame('24.00', $rates['GR']); // TEDB's EL
        $this->assertSame('25.50', $rates['FI']);
        $this->assertSame('21.00', $rates['ES']); // not the Canary Islands' 7
        $this->assertSame('20.00', $rates['AT']); // not its reduced rates
    }

    public function test_the_checked_list_is_what_tedb_answered(): void
    {
        $this->assertSame(StandardRates::rates(), Tedb::parse($this->answer()));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', StandardRates::checkedOn());
    }

    public function test_a_state_missing_from_the_answer_is_no_answer(): void
    {
        $xml = preg_replace('#<vatRateResults><memberState>MT</memberState><type>STANDARD</type>.*?</vatRateResults>#s', '', $this->answer());

        $this->expectException(TedbUnavailable::class);
        $this->expectExceptionMessage('MT');
        Tedb::parse((string) $xml);
    }

    public function test_a_fault_is_no_answer(): void
    {
        $fault = '<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body><env:Fault><faultcode>env:Client</faultcode>'
            . '<faultstring>TEDB-100: invalid request</faultstring></env:Fault></env:Body></env:Envelope>';

        $this->expectException(TedbUnavailable::class);
        $this->expectExceptionMessage('TEDB-100');
        Tedb::parse($fault);
    }

    /** @return iterable<string, array{string}> */
    public static function garbage(): iterable
    {
        yield 'empty' => [''];
        yield 'html' => ['<html><body>Service unavailable'];
        yield 'not a rate' => [(string) preg_replace('#<value>19.0</value>#', '<value>nineteen</value>', (string) file_get_contents(__DIR__ . '/fixtures/tedb-answer.xml'), 1)];
    }

    #[DataProvider('garbage')]
    public function test_anything_else_is_no_answer(string $xml): void
    {
        $this->expectException(TedbUnavailable::class);
        Tedb::parse($xml);
    }

    public function test_an_entity_in_the_answer_is_not_resolved(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>';

        $this->expectException(TedbUnavailable::class);
        Tedb::parse($xml);
    }

    public function test_rates_are_normalized_without_floats(): void
    {
        $this->assertSame('25.50', Rate::normalize('25.5'));
        $this->assertSame('25.50', Rate::normalize(25.5));
        $this->assertSame('20.00', Rate::normalize(20));
        $this->assertSame('17.00', Rate::normalize('17.000'));
        $this->expectException(\InvalidArgumentException::class);
        Rate::normalize('25.555');
    }
}
