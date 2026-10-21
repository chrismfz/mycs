<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Adversarial review of #295 (the admin at /sysadmin, /admin redirects there). */
class AdminPathAdversarialTest extends TestCase
{
    use RefreshDatabase;

    /** The decoded {rest} goes into Location as is: an encoded CR/LF becomes a raw one in the header. */
    public function test_the_old_admin_redirect_puts_no_raw_newline_in_location(): void
    {
        $location = (string) $this->get('/admin/x%0d%0aSet-Cookie:%20a=b')->assertStatus(301)->headers->get('Location');

        $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $location);
    }

    /** An encoded «?» in the old path is part of the path, not the start of a query, once at /sysadmin. */
    public function test_the_old_admin_redirect_keeps_the_path_encoded(): void
    {
        $this->get('/admin/a%3Fb=1')->assertStatus(301)->assertRedirect('/sysadmin/a%3Fb=1');
    }
}
