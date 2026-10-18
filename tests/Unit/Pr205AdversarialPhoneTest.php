<?php

namespace Tests\Unit;

use App\Support\Domains\Phone;
use PHPUnit\Framework\TestCase;

/** Adversarial review of #205: a local number under its contact's country's code (Phone::epp, the .gr registry's voice). */
class Pr205AdversarialPhoneTest extends TestCase
{
    /** ADV-6 (low). The comment keeps San Marino's trunk 0 (code 378), but SM is not in CODES: its number goes out as +30. */
    public function test_adv6_san_marino_is_known(): void
    {
        $this->assertSame('+378.0549123456', Phone::epp('0549 123456', 'SM'));
    }

    /**
     * ADV-7 (low). Only a trunk «0» is dropped; countries with another trunk prefix get it into the subscriber number:
     * Russia «8», Hungary «06», the US/Canada «1». Valid EPP syntax, wrong number at the registry.
     */
    public function test_adv7_other_trunk_prefixes_are_dropped(): void
    {
        $this->assertSame('+7.4951234567', Phone::epp('8 (495) 123-45-67', 'RU'), 'Russia: trunk 8');
        $this->assertSame('+36.12345678', Phone::epp('06 1 234 5678', 'HU'), 'Hungary: trunk 06');
        $this->assertSame('+1.2125550100', Phone::epp('1-212-555-0100', 'US'), 'US: trunk 1');
    }

    /** Regression guards (pass): the shapes the review was asked to try. */
    public function test_guard_usual_shapes(): void
    {
        $this->assertSame('+30.6912345678', Phone::epp('69 1234 5678', 'GR'), 'a Greek mobile');
        $this->assertSame('+30.2101234567', Phone::epp('0030 210 1234567', 'GR'), '00 prefix');
        $this->assertSame('+30.2101234567', Phone::epp('+30 210-123-4567', 'CY'), 'its own + wins');
        $this->assertSame('+357.99123456', Phone::epp('99 123456', ' cy '), 'a Cypriot mobile, the country lower case and spaced');
        $this->assertSame('+39.3331234567', Phone::epp('333 123 4567', 'IT'), 'an Italian mobile');
        $this->assertSame('+49.301234567', Phone::epp('030 1234567', 'DE'), 'Berlin, its trunk 0 dropped');
        $this->assertNull(Phone::epp('', 'GR'));
        $this->assertNull(Phone::epp(' - ', 'GR'));
        $this->assertNull(Phone::epp(null, 'GR'));
        foreach (['GR', 'CY', 'IT', 'GB', 'DE', 'US', 'XX', null] as $country) {
            $this->assertMatchesRegularExpression('/^\+\d{1,3}\.\d{1,14}$/', (string) Phone::epp('210 1234567', $country), "RFC 5733 shape for {$country}");
        }
    }
}
