<?php

namespace Tests\Feature;

use App\Actions\Auth\Login as LoginAction;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
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

    /** Review of #575: the router decodes before it routes; the front controller and any case reach the panel too. */
    public function test_an_admin_address_written_another_way_goes_to_the_dashboard(): void
    {
        foreach (['/sys%61dmin', '/sys%61dmin/clients', '/sysadmin%2Fclients', '/index.php/sysadmin', '/SysAdmin/clients', '//sysadmin', '/x/../sysadmin'] as $path) {
            session()->put('url.intended', url('/') . $path);
            $this->assertSame('/home', LoginAction::intended('/home'), $path);
        }
    }

    public function test_only_http_addresses_of_this_site(): void
    {
        foreach (['javascript:alert(1)', '/\\evil.example', 'https:\\\\evil.example', '//evil.example/x', 'https://evil.example/x'] as $url) {
            session()->put('url.intended', $url);
            $this->assertSame('/home', LoginAction::intended('/home'), $url);
        }
        // An invitation's link carries APP_URL's host, also when the request came on another of this site's names.
        config(['app.url' => 'https://portal.example.test']);
        session()->put('url.intended', 'https://portal.example.test/invite/continue');
        $this->assertSame('https://portal.example.test/invite/continue', LoginAction::intended('/home'));
    }

    public function test_registering_never_goes_on_to_the_admin(): void
    {
        session()->put('url.intended', url('/sysadmin'));

        Livewire::test(Register::class)->set('contact.country', 'GR')
            ->set('first_name', 'New')->set('last_name', 'Person')->set('email', 'new@example.test')
            ->set('password', 'secret-pass-1')->set('password_confirmation', 'secret-pass-1')
            ->call('submit')->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));
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
