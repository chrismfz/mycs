<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Models\User;
use App\Support\CallerIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

        $this->assertCaptchaHidden($this->get('/login')->assertOk()->getContent());
        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'Right-Pass-1')
            ->call('submit')->assertHasNoErrors()->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse(Cache::has('login-free-try:' . CallerIp::key()), 'signed in: the next one is free again');
    }

    /** Its widget is on the page from the start (its scripts run), hidden until needed (review of #684, finding 1). */
    private function assertCaptchaHidden(string $html): void
    {
        $this->assertStringContainsString('<altcha-widget', $html);
        // Disabled too: its required checkbox, hidden, would stop the browser sending the form (nixpal, 2026-11-13).
        $this->assertMatchesRegularExpression('/<fieldset class="[^"]*\\bhidden\\b[^"]*"\s+disabled\s+data-login-captcha>/', $html);
    }

    private function assertCaptchaShown(string $html): void
    {
        $this->assertStringContainsString('<altcha-widget', $html);
        $this->assertMatchesRegularExpression('/<fieldset class="m-0 min-w-0 border-0 p-0"\s+data-login-captcha>/', $html, 'shown and enabled');
    }

    /**
     * Review of #684, finding 2: requests sent at once from a clean address each saw no failure yet (counted only after
     * the password check) and skipped the captcha. The free try is taken atomically: one between them.
     */
    public function test_requests_at_once_get_one_free_try_between_them(): void
    {
        $user = $this->user();
        $this->storeSettings(['captcha' => 'altcha']);

        // The first request has taken the free try and not finished (no failure counted yet): the second needs it.
        Cache::add('login-free-try:' . CallerIp::key(), 1, 900);
        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'Right-Pass-1')
            ->call('submit')->assertHasErrors('captcha');
        $this->assertGuest();
    }

    public function test_a_right_password_with_two_factor_frees_the_try_too(): void
    {
        $user = $this->user();
        $user->forceFill(['tfa_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $this->storeSettings(['captcha' => 'altcha']);

        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'Right-Pass-1')
            ->call('submit')->assertHasNoErrors()->assertRedirect(route('2fa'));
        $this->assertFalse(Cache::has('login-free-try:' . CallerIp::key()));
    }

    public function test_by_default_a_failure_from_the_address_brings_the_captcha_back(): void
    {
        $user = $this->user();
        $this->storeSettings(['captcha' => 'altcha']);

        $login = Livewire::test(Login::class)->set('email', $user->email)->set('password', 'wrong')
            ->call('submit')->assertHasErrors(['email' => __('auth.failed')]);
        $this->assertCaptchaShown($login->html());

        // The right password now still needs the captcha solved: a guesser never gets a free try after a failure.
        $login->set('password', 'Right-Pass-1')->call('submit')->assertHasErrors('captcha');
        $this->assertGuest();
        $this->assertCaptchaShown($this->get('/login')->getContent());

        // Its failures and its try forgotten (15 minutes), it goes again.
        RateLimiter::clear('login-ip:' . CallerIp::key());
        Cache::forget('login-free-try:' . CallerIp::key());
        $this->assertCaptchaHidden($this->get('/login')->getContent());
    }

    public function test_always_asks_on_the_first_try(): void
    {
        $user = $this->user();
        $this->storeSettings(['captcha' => 'altcha', 'captcha_login' => 'always']);

        $this->assertCaptchaShown($this->get('/login')->getContent());
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

    /** Re-review of #684: a provider's captcha runs and is checked while hidden, so with one sign-in always asks. */
    public function test_with_a_provider_captcha_sign_in_always_asks(): void
    {
        $user = $this->user();
        $this->storeSettings(['captcha' => 'turnstile', 'captcha_site_key' => 'site', 'captcha_secret' => 'secret']);

        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'Right-Pass-1')
            ->call('submit')->assertHasErrors('captcha');
        $this->assertGuest();
    }

    public function test_the_widget_is_emptied_before_livewire_keeps_the_page_for_back(): void
    {
        // Ilias 2026-11-06: one more widget on each «back» (it draws in its own element, kept drawn in the page's HTML).
        $js = (string) file_get_contents(resource_path('js/altcha-entry.js'));

        $this->assertStringContainsString("addEventListener('livewire:navigating'", $js);
        $this->assertStringContainsString('replaceChildren()', $js);
    }
}
