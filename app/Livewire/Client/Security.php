<?php

namespace App\Livewire\Client;

use App\Livewire\Component;
use App\Models\UserSession;
use App\Support\Actor;
use App\Support\Reauth;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
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

    public function mount()
    {
        $this->twoFactorEnabled = Auth::user()->tfa_secret ? true : false;
    }

    public function rules()
    {
        return [
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
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
        if (!is_string($user->tfa_secret) || !$tfa->verifyCode($user->tfa_secret, preg_replace('/\s+/', '', $this->disable_code))) {
            return $this->notify(__('account.notifications.two_factor_code_incorrect'), 'error');
        }
        $this->reset('disable_password', 'disable_code');

        Auth::user()->update([
            'tfa_secret' => null,
        ]);

        $this->notify(__('account.notifications.two_factor_disabled'));

        $this->twoFactorEnabled = false;
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
