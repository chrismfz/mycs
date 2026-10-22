<?php

namespace Tests\Feature;

use App\Livewire\Client\Account;
use App\Livewire\Client\Security;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserSession;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use RobThree\Auth\Providers\Qr\EndroidQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;
use Tests\TestCase;

/**
 * Adversarial review of #332: what a stolen customer session can still do to the login without its password.
 */
class Adv332ReauthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::query()->updateOrCreate(['key' => 'mail_disable', 'settingable_type' => null, 'settingable_id' => null], ['value' => '0', 'type' => 'boolean']);
        config(['settings.mail_disable' => false]);
        $this->user = User::factory()->create(['email' => 'old@example.test', 'password' => Hash::make('Right-Pass-1')]);
    }

    private function as(): void
    {
        $data = $this->loginUser($this->user, [CurrentClient::SESSION_KEY => $this->user->client()->id]);
        $this->actingAs($this->user)->withSession($data);
        request()->attributes->set('user_session', UserSession::query()->where('ulid', $data['user_session'])->sole());
    }

    private function tfa(): TwoFactorAuth
    {
        return new TwoFactorAuth(new EndroidQrCodeProvider, config('app.name'));
    }

    /**
     * F1: 2FA already on, the stolen session "enables" it again with a secret of its own: the owner's app no longer
     * works, without the password or a current code (the new disable form is bypassed).
     */
    public function test_a_stolen_session_cannot_replace_the_two_factor_secret_without_the_password_or_a_code(): void
    {
        $secret = $this->tfa()->createSecret();
        $this->user->update(['tfa_secret' => $secret]);
        $this->as();

        $component = Livewire::test(Security::class)->call('enableTwoFactor');
        $attackerSecret = $component->get('twoFactorData')['secret'] ?? null;
        if (is_string($attackerSecret)) {
            $component->set('twoFactorCode', $this->tfa()->getCode($attackerSecret))->call('enableTwoFactor');
        }

        $this->assertSame($secret, $this->user->fresh()->tfa_secret);
    }

    /**
     * F2: 2FA off, the stolen session turns it on with its own app and no password: every other session (the owner's)
     * is logged out, and after «Forgot password» the owner still needs a code only the attacker has.
     */
    public function test_a_stolen_session_cannot_turn_two_factor_on_without_the_password(): void
    {
        $this->as();
        $other = UserSession::query()->create(['user_id' => $this->user->id, 'ip_address' => '192.0.2.10', 'user_agent' => 'owner', 'last_activity' => now()]);

        $component = Livewire::test(Security::class)->call('enableTwoFactor');
        $attackerSecret = $component->get('twoFactorData')['secret'] ?? null;
        if (is_string($attackerSecret)) {
            $component->set('twoFactorCode', $this->tfa()->getCode($attackerSecret))->call('enableTwoFactor');
        }

        $this->assertNull($this->user->fresh()->tfa_secret);
        $this->assertTrue(UserSession::query()->whereKey($other->id)->exists());
    }

    /** F3: the new password prompt is an unlimited oracle: guesses, then the right one, change the login's email. */
    public function test_the_email_password_cannot_be_guessed_without_limit(): void
    {
        $this->as();

        for ($i = 0; $i < 25; $i++) {
            Livewire::test(Account::class)->set('email', 'attacker@example.test')->set('email_password', 'guess-' . $i)->call('submit');
        }
        Livewire::test(Account::class)->set('email', 'attacker@example.test')->set('email_password', 'Right-Pass-1')->call('submit');

        $this->assertSame('old@example.test', $this->user->fresh()->email);
    }

    /** F3: the same for the current password of «Change password», which on a right guess is the takeover itself. */
    public function test_the_current_password_cannot_be_guessed_without_limit(): void
    {
        $this->as();

        for ($i = 0; $i < 25; $i++) {
            Livewire::test(Security::class)->set('current_password', 'guess-' . $i)->set('password', 'Attacker-9x')->set('password_confirmation', 'Attacker-9x')->call('changePassword');
        }
        Livewire::test(Security::class)->set('current_password', 'Right-Pass-1')->set('password', 'Attacker-9x')->set('password_confirmation', 'Attacker-9x')->call('changePassword');

        $this->assertTrue(Hash::check('Right-Pass-1', $this->user->fresh()->password));
    }

    /**
     * F4: a WHMCS login whose hash is not bcrypt (imported raw, «anything older needs Forgot password») comes in by
     * social login; a new email is a server error instead of a defined answer.
     */
    public function test_a_login_without_a_usable_password_gets_an_answer_not_a_server_error(): void
    {
        DB::table('users')->where('id', $this->user->id)->update(['password' => md5('legacy') . ':ab']);
        $this->user->refresh();
        $this->as();

        Livewire::test(Account::class)->set('email', 'new@example.test')->set('email_password', 'legacy')->call('submit')->assertHasErrors(['email_password']);

        $this->assertSame('old@example.test', $this->user->fresh()->email);
    }
}
