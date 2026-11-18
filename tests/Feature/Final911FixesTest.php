<?php

namespace Tests\Feature;

use App\Support\EmailKinds;
use Tests\TestCase;

/** Final review of #911: «affiliates» never decides whether a member sees every kind. */
class Final911FixesTest extends TestCase
{
    public function test_every_area_but_affiliates_sees_all(): void
    {
        $this->assertNull(EmailKinds::keysAllowed(fn (string $p) => $p !== 'affiliates'));
        $this->assertNull(EmailKinds::keysAllowed(fn () => true));
        $this->assertNotNull(EmailKinds::keysAllowed(fn (string $p) => !in_array($p, ['affiliates', 'domains'], true)));
    }
}
