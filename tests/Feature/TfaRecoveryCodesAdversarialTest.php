<?php

namespace Tests\Feature;

use App\Livewire\Client\Security;
use App\Models\User;
use App\Models\UserSession;
use App\Support\CurrentClient;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use RobThree\Auth\Providers\Qr\EndroidQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;
use Tests\TestCase;

/**
 * Adversarial review of PR #374 (customers' 2FA recovery codes): one test per finding, each failing on the PR as it was.
 */
class TfaRecoveryCodesAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['password' => Hash::make('Right-Pass-1')]);
        $secret = (new TwoFactorAuth(new EndroidQrCodeProvider, config('app.name')))->createSecret();
        $this->user->update(['tfa_secret' => $secret]);
    }

    protected function tearDown(): void
    {
        RateLimiter::clear(Totp::limitKey($this->user));
        RateLimiter::clear('reauth:' . $this->user->id);
        parent::tearDown();
    }

    private function as(): void
    {
        $data = $this->loginUser($this->user, [CurrentClient::SESSION_KEY => $this->user->client()->id]);
        $this->actingAs($this->user)->withSession($data);
        request()->attributes->set('user_session', UserSession::query()->where('ulid', $data['user_session'])->sole());
    }

    /**
     * ADV-1 (high): the password alone turns 2FA off, defeating AUTHN-1 («off only with the password AND a code from the
     * app»). Whoever holds a session and the password, but not the app (a stolen cookie plus a phished or reused password),
     * asks for new recovery codes with the password only, gets eight valid second factors on screen, and spends one to turn
     * 2FA off; or keeps them and signs in later from anywhere, after the session is revoked. The owner's own codes stop
     * working silently. New codes should need what turning 2FA off needs: the password and a code (from the app, or a
     * current recovery code).
     */
    public function test_new_recovery_codes_with_the_password_alone_never_turn_2fa_off(): void
    {
        $this->as();

        // The attacker: the session and the password, no app, no code.
        $page = Livewire::test(Security::class)->set('recovery_password', 'Right-Pass-1')->call('regenerateRecoveryCodes');
        $minted = $page->get('recoveryCodes');

        Livewire::test(Security::class)->set('disable_password', 'Right-Pass-1')
            ->set('disable_code', $minted[0] ?? 'aaaaa-aaaaa')->call('disableTwoFactor');

        $this->assertNotNull($this->user->fresh()->tfa_secret, '2FA turned off with the password alone (AUTHN-1)');
    }
}
