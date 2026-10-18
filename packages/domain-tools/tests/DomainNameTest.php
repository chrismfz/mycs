<?php

namespace Nixpal\DomainTools\Tests;

use Nixpal\DomainTools\DomainName;
use PHPUnit\Framework\TestCase;

final class DomainNameTest extends TestCase
{
    const SOLD = ['.com', '.net', '.uk', '.co.uk', 'eu'];

    private static function parts($input)
    {
        $name = DomainName::parse($input, self::SOLD);

        return $name ? [$name->sld, $name->tld] : null;
    }

    public function test_input_is_normalised()
    {
        $this->assertSame(['example', 'com'], self::parts('Example.COM'));
        $this->assertSame(['example', 'com'], self::parts(' https://www.example.com/path?x=1 '));
        $this->assertSame(['example', 'co.uk'], self::parts('example.co.uk'));     // longest sold TLD
        $this->assertSame(['example', 'uk'], self::parts('example.uk'));
        $this->assertSame(['example', 'com'], self::parts('shop.example.com'));    // sub-domain typed
        $this->assertSame(['greekbakery', null], self::parts('Greek Bakery'));      // words make one name
        $this->assertSame(['example', null], self::parts('example.io'));           // a TLD we do not sell
        $this->assertSame(['example', 'com'], self::parts('example.com:8080'));
        $this->assertSame('example.net', DomainName::parse('example', self::SOLD)->withTld('.net'));
    }

    public function test_idn_and_invalid_input()
    {
        $this->assertSame(['xn--hxajbheg2az3al', 'eu'], self::parts('παράδειγμα.eu'));
        $this->assertNull(self::parts(''));
        $this->assertNull(self::parts('   '));
        $this->assertNull(self::parts('-bad-.com'));
        $this->assertNull(self::parts('bad_name.com'));
        $this->assertNull(self::parts(str_repeat('a', 64) . '.com'));
        $this->assertTrue(DomainName::validDomain('a-b.co.uk'));
        $this->assertFalse(DomainName::validDomain('nodot'));
        $this->assertFalse(DomainName::validDomain('a..com'));
    }

    public function test_idn_is_non_transitional()
    {
        // IDNA2008, as browsers and the .gr registry: final sigma and sharp s are kept, not mapped to σ and ss.
        $this->assertSame('xn--mxare2a1c.xn--qxam', DomainName::toAscii('καλός.ελ'));
        // Capital Σ has no final form: it lowers to σ, as in a browser.
        $this->assertSame('xn--mxare6a6b.xn--qxam', DomainName::toAscii('ΚΑΛΌΣ.ΕΛ'));
        $this->assertNotSame(DomainName::toAscii('καλός.ελ'), DomainName::toAscii('καλόσ.ελ'));
        $this->assertSame('xn--strae-oqa.de', DomainName::toAscii('straße.de'));
        $this->assertSame('strasse.de', DomainName::toAscii('strasse.de'));
        $this->assertSame(['xn--strae-oqa', 'com'], self::parts('https://www.Straße.com/'));
        $this->assertSame(['xn--mxare2a1c', 'eu'], self::parts('καλός.eu'));
    }
}
