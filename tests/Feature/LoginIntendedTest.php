<?php

namespace Tests\Feature;

use App\Actions\Auth\Login as LoginAction;
use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A customer's login goes back where it started, never to the admin panel (owner 2026-11-02: staff logged out there,
 * the shared session still held /sysadmin as intended, and the customer's login landed on the staff login), nor to
 * another site.
 */
class LoginIntendedTest extends TestCase
{
    use RefreshDatabase;

    private function loginWith(?string $intended): Testable
    {
        User::factory()->create(['email' => 'anna@example.test', 'password' => bcrypt('Right-Pass-1')]);
        if ($intended !== null) {
            session()->put('url.intended', $intended);
        }

        return Livewire::test(Login::class)->set('email', 'anna@example.test')->set('password', 'Right-Pass-1')->call('submit');
    }

    public function test_an_admin_page_as_intended_goes_to_the_dashboard(): void
    {
        $this->loginWith(url('/sysadmin'))->assertRedirect(route('dashboard'));
        $this->assertFalse(session()->has('url.intended'), 'taken out of the session');
    }

    public function test_a_deeper_admin_page_too(): void
    {
        $this->loginWith(url('/sysadmin/invoices/5/edit'))->assertRedirect(route('dashboard'));
    }

    public function test_a_page_of_the_portal_is_where_it_goes_back(): void
    {
        $this->loginWith(url('/invoices'))->assertRedirect(url('/invoices'));
    }

    public function test_the_rule_itself(): void
    {
        session()->put('url.intended', 'https://evil.example/steal');
        $this->assertSame('/home', LoginAction::intended('/home'), 'another site');
        session()->put('url.intended', url('/sysadminish'));
        $this->assertSame(url('/sysadminish'), LoginAction::intended('/home'), 'only the panel\'s own path, not a page that starts with its name');
        $this->assertSame('/home', LoginAction::intended('/home'), 'nothing intended');
    }
}
