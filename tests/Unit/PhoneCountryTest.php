<?php

namespace Tests\Unit;

use App\Support\Domains\Phone;
use PHPUnit\Framework\TestCase;

/** G5b-3 of grepp: a local number under its contact's country's code (+30 when not known, as before). */
class PhoneCountryTest extends TestCase
{
    public function test_a_local_number_takes_its_countrys_code(): void
    {
        $this->assertSame('+30.2101234567', Phone::epp('210 1234567', 'GR'));
        $this->assertSame('+357.22123456', Phone::epp('22 123456', 'CY'));
        $this->assertSame('+44.2079460000', Phone::epp('020 7946 0000', 'gb'), 'its trunk 0 dropped');
        $this->assertSame('+39.0612345678', Phone::epp('06 1234 5678', 'IT'), 'kept in Italy');
        $this->assertSame('+30.2101234567', Phone::epp('210 1234567', null), 'not known: +30, as before');
        $this->assertSame('+30.2101234567', Phone::epp('210 1234567', 'XX'));
    }

    public function test_an_international_number_keeps_its_own(): void
    {
        $this->assertSame('+357.22123456', Phone::epp('+357 22 123456', 'GR'), 'its own + wins over the contact\'s country');
        $this->assertSame('+44.2079460000', Phone::epp('0044 20 7946 0000', 'GR'));
    }
}
