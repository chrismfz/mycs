<?php

namespace Nixpal\DomainTools\Tests;

use Nixpal\DomainTools\Whois\Port43;
use Nixpal\DomainTools\Whois\SocketConnector;
use PHPUnit\Framework\TestCase;

final class Port43Test extends TestCase
{
    private static function socket(array $answers)
    {
        return new class($answers) implements SocketConnector
        {
            public $queries = [];

            private $answers;

            public function __construct($answers)
            {
                $this->answers = $answers;
            }

            public function query($host, $query, $timeout = 8, $max = 65536)
            {
                $this->queries[] = [$host, $query];

                return $this->answers[$host . '|' . $query] ?? null;
            }
        };
    }

    public function test_server_from_iana_then_masked_answer()
    {
        $socket = self::socket([
            'whois.iana.org|eu' => "domain:       EU\nwhois:        whois.eu\n",
            'whois.eu|example.eu' => "% The WHOIS service offered by EURid\r\n%\r\n# another comment\r\n\r\n\r\n\r\nDomain: example.eu\r\nRegistrar:\r\n   Name: Example Registrar\r\n   Email: abuse@registrar.example\r\n"
                . "Technical:\r\n   Phone: +30.2100000000\r\n   Fax number: +30 210 000 0000\r\nExpiry: 2027-03-01 12:00:00\r\n",
        ]);
        [$result, $text] = (new Port43($socket))->lookup('example.eu');

        $this->assertSame('found', $result);
        $this->assertStringContainsString('Name: Example Registrar', $text);
        $this->assertStringContainsString('Email: [email]', $text);
        $this->assertStringContainsString('Phone: [phone]', $text);
        $this->assertStringContainsString('Fax number: [phone]', $text);
        $this->assertStringContainsString('Expiry: 2027-03-01 12:00:00', $text);   // dates are kept
        $this->assertStringNotContainsString("\r", $text);
        $this->assertStringStartsWith('Domain: example.eu', $text);              // disclaimers dropped
        $this->assertSame([['whois.iana.org', 'eu'], ['whois.eu', 'example.eu']], $socket->queries);
    }

    public function test_no_server_or_a_strange_one_is_unsupported()
    {
        $this->assertSame(['unsupported', null], (new Port43(self::socket(['whois.iana.org|gr' => "domain: GR\n"])))->lookup('example.gr'));
        $odd = self::socket(['whois.iana.org|xx' => "whois: evil.example:8080/x\n"]);
        $this->assertNull((new Port43($odd))->serverFor('xx'));
        $this->assertSame(['error', null], (new Port43(self::socket(['whois.iana.org|eu' => "whois: whois.eu\n"])))->lookup('example.eu'));
    }
}
