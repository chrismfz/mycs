<?php

namespace Tests\Feature;

use App\Livewire\Auth\Tfa;
use App\Livewire\Client\Security;
use App\Models\User;
use App\Models\UserSession;
use App\Support\CurrentClient;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use RobThree\Auth\Providers\Qr\EndroidQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;
use Tests\TestCase;

/**
 * A customer's 2FA recovery codes: made when 2FA goes on (shown once, kept hashed), each signs in once instead of the app,
 * turns 2FA off with the password, counts towards the codes' limit; new ones with the password; gone with the secret.
 */
class TfaRecoveryCodesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['password' => Hash::make('Right-Pass-1')]);
        $this->secret = (new TwoFactorAuth(new EndroidQrCodeProvider, config('app.name')))->createSecret();
    }

    private function as(): void
    {
        $data = $this->loginUser($this->user, [CurrentClient::SESSION_KEY => $this->user->client()->id]);
        $this->actingAs($this->user)->withSession($data);
        request()->attributes->set('user_session', UserSession::query()->where('ulid', $data['user_session'])->sole());
    }

    private function code(): string
    {
        return (new TwoFactorAuth(new EndroidQrCodeProvider, config('app.name')))->getCode($this->secret);
    }

    public function test_turning_2fa_on_shows_codes_once_and_keeps_only_their_hashes(): void
    {
        $this->as();
        $page = Livewire::test(Security::class)->call('enableTwoFactor');
        $secret = $page->get('twoFactorData')['secret'];
        $page->set('enable_password', 'Right-Pass-1')->set('twoFactorCode', (new TwoFactorAuth(new EndroidQrCodeProvider, config('app.name')))->getCode($secret))
            ->call('enableTwoFactor');

        $codes = $page->get('recoveryCodes');
        $this->assertCount(RecoveryCodes::COUNT, $codes);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{5}-[a-z0-9]{5}$/', $codes[0]);
        $page->assertSee($codes[0]);
        $stored = (string) \DB::table('users')->where('id', $this->user->id)->value('tfa_recovery_codes');
        $this->assertStringNotContainsString($codes[0], $stored);
        $this->assertStringNotContainsString($codes[0], json_encode($this->user->fresh()->tfa_recovery_codes));
        $page->call('hideRecoveryCodes')->assertDontSee($codes[0]);
    }

    public function test_a_recovery_code_signs_in_once(): void
    {
        $this->user->update(['tfa_secret' => $this->secret]);
        $codes = RecoveryCodes::regenerate($this->user);
        Session::put('2fa', ['user_id' => $this->user->id, 'remember' => false, 'expires' => now()->addMinutes(5)]);

        Livewire::test(Tfa::class)->set('recovery', strtoupper($codes[2]))->call('verifyRecovery')->assertRedirect(route('dashboard'));
        $this->assertStringContainsString((string) (RecoveryCodes::COUNT - 1), (string) session('notification')['message']);
        $this->assertSame($this->user->id, Auth::id());
        $this->assertSame(RecoveryCodes::COUNT - 1, RecoveryCodes::remaining($this->user->fresh()));

        Auth::logout();
        Session::put('2fa', ['user_id' => $this->user->id, 'remember' => false, 'expires' => now()->addMinutes(5)]);
        Livewire::test(Tfa::class)->set('recovery', $codes[2])->call('verifyRecovery')->assertHasErrors('recovery');
        $this->assertNull(Auth::id(), 'used once');
    }

    public function test_wrong_recovery_codes_count_towards_the_limit(): void
    {
        $this->user->update(['tfa_secret' => $this->secret]);
        $codes = RecoveryCodes::regenerate($this->user);
        Session::put('2fa', ['user_id' => $this->user->id, 'remember' => false, 'expires' => now()->addMinutes(5)]);

        foreach (range(1, Totp::MAX_WRONG) as $i) {
            Livewire::test(Tfa::class)->set('recovery', 'aaaaa-aaaa' . $i)->call('verifyRecovery')->assertHasErrors('recovery');
        }
        Livewire::test(Tfa::class)->set('recovery', $codes[0])->call('verifyRecovery')->assertHasErrors('recovery');
        $this->assertNull(Auth::id(), 'locked even for a right one');
        $this->assertSame(RecoveryCodes::COUNT, RecoveryCodes::remaining($this->user->fresh()), 'not used while locked');
        RateLimiter::clear(Totp::limitKey($this->user));
    }

    public function test_2fa_goes_off_with_the_password_and_a_recovery_code_and_its_codes_go_with_it(): void
    {
        $this->user->update(['tfa_secret' => $this->secret]);
        $codes = RecoveryCodes::regenerate($this->user);
        $this->as();

        Livewire::test(Security::class)->set('disable_password', 'wrong')->set('disable_code', $codes[0])->call('disableTwoFactor');
        $this->assertNotNull($this->user->fresh()->tfa_secret);

        Livewire::test(Security::class)->set('disable_password', 'Right-Pass-1')->set('disable_code', $codes[0])->call('disableTwoFactor');
        $this->assertNull($this->user->fresh()->tfa_secret);
        $this->assertNull($this->user->fresh()->tfa_recovery_codes);
    }

    public function test_new_codes_need_the_password_and_the_old_stop_working(): void
    {
        $this->user->update(['tfa_secret' => $this->secret]);
        $old = RecoveryCodes::regenerate($this->user);
        $this->as();

        Livewire::test(Security::class)->set('recovery_password', 'wrong')->set('recovery_code', $this->code())->call('regenerateRecoveryCodes')->assertSet('recoveryCodes', []);
        $page = Livewire::test(Security::class)->set('recovery_password', 'Right-Pass-1')->set('recovery_code', $old[1])->call('regenerateRecoveryCodes');
        $new = $page->get('recoveryCodes');

        $this->assertCount(RecoveryCodes::COUNT, $new);
        $this->assertFalse(RecoveryCodes::consume($this->user->fresh(), $old[0]));
        $this->assertTrue(RecoveryCodes::consume($this->user->fresh(), $new[0]));
        RateLimiter::clear(Totp::limitKey($this->user));
    }

    public function test_staff_turning_2fa_off_takes_the_codes_too(): void
    {
        $this->user->update(['tfa_secret' => $this->secret]);
        RecoveryCodes::regenerate($this->user);

        $this->user->fresh()->update(['tfa_secret' => null]);

        $this->assertNull($this->user->fresh()->tfa_recovery_codes);
    }

    /** Code review 2: a recovery login goes where the login was going (an invitation), told on that page. */
    public function test_a_recovery_login_keeps_where_it_was_going(): void
    {
        $this->user->update(['tfa_secret' => $this->secret]);
        $codes = RecoveryCodes::regenerate($this->user);
        Session::put('url.intended', '/invites/continue');
        Session::put('2fa', ['user_id' => $this->user->id, 'remember' => false, 'expires' => now()->addMinutes(5)]);

        Livewire::test(Tfa::class)->set('recovery', $codes[0])->call('verifyRecovery')->assertRedirect('/invites/continue');
    }

    /** Code review 3: a save that writes the same secret keeps the codes, also with a previous key (where it is dirty). */
    public function test_saving_the_same_secret_keeps_the_codes(): void
    {
        $this->user->update(['tfa_secret' => $this->secret]);
        RecoveryCodes::regenerate($this->user);
        // The app's key, with a previous one (APP_PREVIOUS_KEYS): any write of an encrypted cast is dirty then.
        $key = base64_decode(substr((string) config('app.key'), 7));
        Model::encryptUsing((new Encrypter($key, (string) config('app.cipher')))->previousKeys([random_bytes(32)]));
        try {
            $this->user->forceFill(['tfa_secret' => $this->secret, 'tfa_recovery_codes' => $this->user->tfa_recovery_codes])->save();
            $this->user->fresh()->update(['first_name' => 'Maria', 'tfa_secret' => $this->secret]);

            $this->assertSame(RecoveryCodes::COUNT, RecoveryCodes::remaining($this->user->fresh()));
            $this->user->fresh()->update(['tfa_secret' => null]);
            $this->assertNull($this->user->fresh()->tfa_recovery_codes);
        } finally {
            Model::encryptUsing(null);
        }
    }
}
