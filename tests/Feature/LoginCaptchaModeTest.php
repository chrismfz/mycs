<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Models\User;
use App\Support\CallerIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The captcha on sign-in (owner 2026-11-06, «Captcha on sign-in»): by default only once the address has failed to sign in;
 * «always» and «never» as they say. Sign-up keeps it whatever this says.
 */
class LoginCaptchaModeTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['password' => bcrypt('Right-Pass-1')]);
    }

    public function test_by_default_a_right_password_signs_in_without_the_captcha(): void
    {
        $user = $this->user();
        $this->storeSettings(['captcha' => 'altcha']);

        $this->get('/login')->assertOk()->assertDontSee('<altcha-widget', false);
        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'Right-Pass-1')
            ->call('submit')->assertHasNoErrors()->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_by_default_a_failure_from_the_address_brings_the_captcha_back(): void
    {
        $user = $this->user();
        $this->storeSettings(['captcha' => 'altcha']);

        $login = Livewire::test(Login::class)->set('email', $user->email)->set('password', 'wrong')
            ->call('submit')->assertHasErrors(['email' => __('auth.failed')])->assertSee('<altcha-widget', false);

        // The right password now still needs the captcha solved: a guesser never gets a free try after a failure.
        $login->set('password', 'Right-Pass-1')->call('submit')->assertHasErrors('captcha');
        $this->assertGuest();
        $this->get('/login')->assertSee('<altcha-widget', false);

        // Its failures forgotten (15 minutes), it goes again.
        RateLimiter::clear('login-ip:' . CallerIp::key());
        $this->get('/login')->assertDontSee('<altcha-widget', false);
    }

    public function test_always_asks_on_the_first_try(): void
    {
        $user = $this->user();
        $this->storeSettings(['captcha' => 'altcha', 'captcha_login' => 'always']);

        $this->get('/login')->assertSee('<altcha-widget', false);
        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'Right-Pass-1')
            ->call('submit')->assertHasErrors('captcha');
        $this->assertGuest();
    }

    public function test_never_asks_even_after_failures_and_sign_up_still_does(): void
    {
        $user = $this->user();
        $this->storeSettings(['captcha' => 'altcha', 'captcha_login' => 'never']);

        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'wrong')->call('submit')
            ->assertHasErrors(['email' => __('auth.failed')])->assertDontSee('<altcha-widget', false);
        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'Right-Pass-1')
            ->call('submit')->assertHasNoErrors();
        $this->assertAuthenticatedAs($user);

        Livewire::test(Register::class)->assertSee('<altcha-widget', false);
    }

    public function test_the_widget_is_emptied_before_livewire_keeps_the_page_for_back(): void
    {
        // Ilias 2026-11-06: one more widget on each «back» (it draws in its own element, kept drawn in the page's HTML).
        $js = (string) file_get_contents(resource_path('js/altcha-entry.js'));

        $this->assertStringContainsString("addEventListener('livewire:navigating'", $js);
        $this->assertStringContainsString('replaceChildren()', $js);
    }
}
