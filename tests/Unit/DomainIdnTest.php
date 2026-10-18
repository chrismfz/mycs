<?php

namespace Tests\Unit;

use App\Models\Domain;
use MyCS\GrEpp\Command\Rules;
use PHPUnit\Framework\TestCase;

/** The name stored for a domain and the one the .gr registry is sent are the same name (non-transitional IDNA). */
class DomainIdnTest extends TestCase
{
    public function test_final_sigma_is_stored_and_sent_as_typed(): void
    {
        $stored = Domain::normalise('Καλός.gr');

        $this->assertSame('xn--mxare2a1c.gr', $stored);
        $this->assertSame('καλός.gr', Rules::name($stored));
        $this->assertNotSame($stored, Domain::normalise('καλόσ.gr'));
    }

    public function test_sharp_s_is_kept(): void
    {
        $this->assertSame('xn--strae-oqa.de', Domain::normalise('straße.de.'));
    }
}
