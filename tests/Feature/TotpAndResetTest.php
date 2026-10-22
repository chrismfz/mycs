<?php

namespace Tests\Feature;

use App\Livewire\Auth\Password\Request as PasswordRequest;
use App\Livewire\Auth\Password\Reset;
use App\Livewire\Client\Security;
use App\Models\EmailLog;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use RobThree\Auth\Providers\Qr\EndroidQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;
use Tests\TestCase;

/**
 * Security audit AUTHN-9: an authenticator code is accepted once, never again in its time step or an earlier one, and
 * wrong codes are limited wherever they are typed. AUTHN-12: a reset mail per account is limited whatever the address,
 * and one password rule holds for registration, reset and change.
 */
class TotpAndResetTest extends TestCase
{
    use RefreshDatabase;

    private TwoFactorAuth $tfa;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tfa = new TwoFactorAuth(new EndroidQrCodeProvider, config('app.name'));
    }

    private function userWithTotp(): array
    {
        $secret = $this->tfa->createSecret();

        return [User::factory()->create(['tfa_secret' => $secret, 'password' => Hash::make('Right-Pass-1')]), $secret];
    }

    public function test_a_code_is_accepted_once(): void
    {
        [$user, $secret] = $this->userWithTotp();
        $code = $this->tfa->getCode($secret);

        $this->assertTrue(Totp::accept($user, $secret, $code));
        $this->assertFalse(Totp::accept($user, $secret, $code), 'the same code again');
    }

    public function test_an_earlier_steps_code_is_refused_after_a_later_one(): void
    {
        [$user, $secret] = $this->userWithTotp();
        $earlier = $this->tfa->getCode($secret, time() - 30);

        $this->assertTrue(Totp::accept($user, $secret, $this->tfa->getCode($secret)));
        $this->assertFalse(Totp::accept($user, $secret, $earlier), 'still inside the ±30 s window, but older than the one used');
    }

    public function test_wrong_codes_lock_the_login_and_the_2fa_off_alike(): void
    {
        [$user, $secret] = $this->userWithTotp();
        for ($i = 0; $i < Totp::MAX_WRONG; $i++) {
            $this->assertFalse(Totp::accept($user, $secret, '000000'));
        }
        $this->assertTrue(Totp::tooManyWrong($user));

        // Turning 2FA off, with the right password and the right code: still waits.
        Livewire::actingAs($user)->test(Security::class)
            ->set('disable_password', 'Right-Pass-1')->set('disable_code', $this->tfa->getCode($secret))->call('disableTwoFactor');
        $this->assertSame($secret, $user->fresh()->tfa_secret);

        RateLimiter::clear(Totp::limitKey($user));
        Livewire::actingAs($user)->test(Security::class)
            ->set('disable_password', 'Right-Pass-1')->set('disable_code', $this->tfa->getCode($secret))->call('disableTwoFactor');
        $this->assertNull($user->fresh()->tfa_secret);
    }

    public function test_a_reset_mail_per_account_is_limited_whatever_the_address(): void
    {
        Mail::fake();
        config(['settings.mail_disable' => false]);
        $user = User::factory()->create(['email' => 'victim@example.test']);

        foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3'] as $ip) {
            $this->app['request']->server->set('REMOTE_ADDR', $ip);
            Livewire::test(PasswordRequest::class)->set('email', 'victim@example.test')->call('submit');
        }

        $this->assertSame(1, EmailLog::query()->where('template_key', 'password_reset')->count(), 'one a minute, from any address');
    }

    public function test_the_password_rule_is_the_same_at_reset_and_change(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test', 'password' => Hash::make('Right-Pass-1')]);
        $token = Password::createToken($user);

        Livewire::withQueryParams(['email' => $user->email])->test(Reset::class, ['token' => $token])
            ->set('password', 'onlyletters')->set('password_confirmation', 'onlyletters')->call('submit')
            ->assertHasErrors('password');

        Livewire::actingAs($user)->test(Security::class)
            ->set('current_password', 'Right-Pass-1')->set('password', '12345678')->set('password_confirmation', '12345678')
            ->call('changePassword')->assertHasErrors('password');
    }
}
