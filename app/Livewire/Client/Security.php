<?php

namespace App\Livewire\Client;

use App\Livewire\Component;
use App\Models\UserSession;
use App\Support\Actor;
use App\Support\Reauth;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use RobThree\Auth\Providers\Qr\EndroidQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;

class Security extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $twoFactorEnabled = false;

    #[Locked]
    public $twoFactorData = [];

    #[Locked]
    public $showEnableTwoFactor = false;

    public string $twoFactorCode = '';

    /** The recovery codes just made: shown once, then gone from the page (RecoveryCodes). @var list<string> */
    #[Locked]
    public array $recoveryCodes = [];

    public string $recovery_password = '';

    public function mount()
    {
        $this->twoFactorEnabled = Auth::user()->tfa_secret ? true : false;
    }

    public function rules()
    {
        return [
            'current_password' => 'required|string',
            'password' => ['required', 'string', 'confirmed', Password::defaults()], // AUTHN-12
        ];
    }

    public function changePassword()
    {
        $this->validate();

        if (($refusal = Reauth::refusal(Auth::user(), $this->current_password)) !== null) {
            return $this->notify($refusal, 'error');
        }

        Auth::user()->update([
            'password' => Hash::make($this->password),
        ]);

        // Destroy all other sessions
        Auth::user()->sessions()->where('ulid', '!=', Session::get('user_session'))->delete();

        $this->notify(__('account.notifications.password_changed'));

        $this->reset('current_password', 'password', 'password_confirmation');
    }

    /** Asked to turn two-factor authentication on: the password with the first code (review of #332). */
    public string $enable_password = '';

    public function enableTwoFactor()
    {
        if (Actor::impersonating()) {
            return $this->notify(__('account.notifications.not_while_impersonating'), 'error');
        }
        // On already: never replaced here (turned off first, with the password and a code: F1 of #332).
        if (Auth::user()->tfa_secret) {
            return $this->notify(__('account.notifications.two_factor_enabled'), 'error');
        }
        if ($this->showEnableTwoFactor) {
            $this->validate([
                'twoFactorCode' => 'required|string',
            ]);
            if (($refusal = Reauth::refusal(Auth::user(), $this->enable_password)) !== null) {
                return $this->notify($refusal, 'error');
            }
            $this->reset('enable_password');

            $tfa = new TwoFactorAuth(new EndroidQrCodeProvider, config('app.name'));
            if ($tfa->verifyCode($this->twoFactorData['secret'], $this->twoFactorCode)) {
                Auth::user()->update([
                    'tfa_secret' => $this->twoFactorData['secret'],
                ]);

                $this->notify(__('account.notifications.two_factor_enabled'));
                // Its recovery codes, shown once now: the way back in without the app.
                $this->recoveryCodes = RecoveryCodes::regenerate(Auth::user());

                $this->twoFactorEnabled = true;
                $this->showEnableTwoFactor = false;

                // Destroy all other sessions
                Auth::user()->sessions()->where('ulid', '!=', Session::get('user_session'))->delete();
            } else {
                $this->notify(__('account.notifications.two_factor_code_incorrect'), 'error');
            }
        } else {
            $tfa = new TwoFactorAuth(new EndroidQrCodeProvider, config('app.name'));
            $secret = $tfa->createSecret();
            $this->twoFactorData = [
                'secret' => $secret,
                'image' => $tfa->getQRCodeImageAsDataUri(Auth::user()->email, $secret),
            ];

            $this->showEnableTwoFactor = true;
        }
    }

    /** Asked to turn two-factor authentication off: the password and a code from the app (AUTHN-1). */
    public string $disable_password = '';

    public string $disable_code = '';

    public function disableTwoFactor()
    {
        if (Actor::impersonating()) {
            return $this->notify(__('account.notifications.not_while_impersonating'), 'error');
        }
        $user = Auth::user();
        $tfa = new TwoFactorAuth(new EndroidQrCodeProvider, config('app.name'));
        if (($refusal = Reauth::refusal($user, $this->disable_password)) !== null) {
            return $this->notify($refusal, 'error');
        }
        // The login's own limit and once-only rule (AUTHN-9): a session and the password never try every code.
        if (Totp::tooManyWrong($user)) {
            return $this->notify(__('Too many attempts. Try again in :minutes minutes.', [
                'minutes' => (int) ceil(RateLimiter::availableIn(Totp::limitKey($user)) / 60)]), 'error');
        }
        // A code from the app, or one of the recovery codes (the app lost).
        $accepted = RecoveryCodes::looksLikeOne($this->disable_code)
            ? RecoveryCodes::consume($user, $this->disable_code)
            : Totp::accept($user, $user->tfa_secret, $this->disable_code);
        if (!$accepted) {
            return $this->notify(__('account.notifications.two_factor_code_incorrect'), 'error');
        }
        $this->reset('disable_password', 'disable_code');

        Auth::user()->update([
            'tfa_secret' => null,
        ]);

        $this->notify(__('account.notifications.two_factor_disabled'));

        $this->twoFactorEnabled = false;
    }

    /** New recovery codes (the old ones stop working): with the password, while 2FA is on. */
    public function regenerateRecoveryCodes()
    {
        if (Actor::impersonating()) {
            return $this->notify(__('account.notifications.not_while_impersonating'), 'error');
        }
        $user = Auth::user();
        if (!$user->tfa_secret) {
            return;
        }
        if (($refusal = Reauth::refusal($user, $this->recovery_password)) !== null) {
            return $this->notify($refusal, 'error');
        }
        $this->reset('recovery_password');
        $this->recoveryCodes = RecoveryCodes::regenerate($user);
        $this->notify(__('account.notifications.recovery_codes_regenerated'));
    }

    /** The codes written down: off the page. */
    public function hideRecoveryCodes(): void
    {
        $this->recoveryCodes = [];
    }

    public function logoutSession(UserSession $session)
    {
        if ($session->user_id !== Auth::id()) {
            $this->notify(__('Unauthorized'), 'error');

            return;
        }

        $session->delete();

        $this->notify(__('account.notifications.session_logged_out'));
    }

    public function render()
    {
        return view('client.account.security')->layoutData([
            'sidebar' => true,
            'title' => __('navigation.security'),
        ]);
    }
}
